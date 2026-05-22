# Pherb Deployment Guide

Pherb is designed to run on FreeBSD with a jail-based architecture. Workers
are generic NATS-to-process bridges deployed in each jail alongside their
respective ML tools. The consumer (orchestrator) runs in its own jail and
coordinates the pipeline via NATS messages.

## Components

| Component | Location | Description |
|-----------|----------|-------------|
| pherb-worker | Any jail/host | Generic command executor (Zig binary) |
| pherb-consumer | Jail: `/usr/local/libexec/pherb/` | Pipeline orchestrator daemon (PHP) |
| pherb-api | Jail: `/usr/local/www/pherb/` | REST API (PHP, Apache) |
| NATS | Jail: `:4222` | Message broker with JetStream |
| MariaDB | Jail: `:3306` | Job database |
| HAProxy | Host | TLS termination and routing |

## Architecture

The worker is stage-agnostic. Each deployment instance subscribes to the
NATS subjects defined in its `worker.conf` and runs the configured command.
The consumer (orchestrator) owns all paths and pipeline logic.

```
┌─────────────────────────────────────────────────────────────────┐
│ Consumer (orchestrator)                                          │
│   - Owns all file paths                                          │
│   - Dispatches stages with {audio_path, output_path, job_id}    │
│   - Advances pipeline on completion events                       │
│   - Merges + formats final output                                │
└───────────┬──────────────┬──────────────┬──────────────┬────────┘
            │              │              │              │
    ┌───────▼──────┐ ┌────▼─────┐ ┌──────▼──────┐ ┌────▼────┐
    │ convert      │ │ whisper  │ │ pyannote    │ │ align   │
    │ worker       │ │ worker   │ │ worker      │ │ worker  │
    │ (any jail)   │ │ (GPU host)│ │ (GPU jail)  │ │ (jail)  │
    └──────────────┘ └──────────┘ └─────────────┘ └─────────┘
```

## Jails

| Jail | Purpose | Audio Mount |
|------|---------|-------------|
| pherb | Consumer + API (PHP, Apache) | /data/audio (rw) |
| webdav | Audio upload/download | /data/audio (rw) |
| nats | NATS JetStream | — |
| mariadb | MariaDB | — |
| whisper | pherb-worker + whisper-cli + models | /data/audio (rw) |
| pyannote | pherb-worker + pyannote (Python) | /data/audio (rw) |
| wav2vec2 | pherb-worker + wav2vec2 (Python) | /data/audio (rw) |

Workers can also run on remote hosts (GPU machines) connected via NATS +
NFS-mounted shared storage.

## NATS Subjects

| Subject | Type | Purpose |
|---------|------|---------|
| `pherb.jobs.transcribe` | JetStream (PHERB stream) | New job requests (API → consumer) |
| `pherb.worker.convert` | Plain NATS | Consumer → worker: audio conversion |
| `pherb.worker.whisper` | Plain NATS | Consumer → worker: transcription |
| `pherb.worker.pyannote` | Plain NATS | Consumer → worker: speaker diarization |
| `pherb.worker.align` | Plain NATS | Consumer → worker: forced alignment |
| `pherb.pipeline.completed` | Plain NATS | Worker → consumer: stage completion |
| `pherb.worker.status` | Plain NATS req/reply | Stale job detection |

## NATS Payload Contract

**Dispatch (consumer → worker):**
```json
{"job_id": "abc123", "audio_path": "/data/audio/file.wav", "output_path": "/data/audio/outputs/abc123.whisper.json"}
```

**Completion (worker → consumer):**
```json
{"job_id": "abc123", "stage": "whisper", "status": "completed", "output_path": "/data/audio/outputs/abc123.whisper.json"}
```

## Pipeline Flow

```
API POST /jobs → JetStream (pherb.jobs.transcribe)
  → Consumer dispatches pherb.worker.convert
    → Worker runs ffmpeg, writes .convert.wav
    → Worker publishes pherb.pipeline.completed {stage: "convert"}
  → Consumer dispatches pherb.worker.whisper
    → Worker runs whisper-cli, writes .whisper.json
    → Worker publishes pherb.pipeline.completed {stage: "whisper"}
  → Consumer dispatches pherb.worker.pyannote (if diarize=true)
    → Worker runs pyannote, writes .pyannote.json
    → Worker publishes pherb.pipeline.completed {stage: "pyannote"}
  → Consumer dispatches pherb.worker.align (if align=true)
    → Worker runs wav2vec2, writes .align.json
    → Worker publishes pherb.pipeline.completed {stage: "alignment"}
  → Consumer finalizes: merge outputs, format, write result, mark completed
```

## Shared Storage

ZFS dataset `models/audio` mounted at `/data/audio` (lz4 compression):

```
/data/audio/              — audio input files
/data/audio/outputs/      — stage outputs + final results
```

nullfs-mounted into all worker jails (rw) and webdav (rw).

## Configuration

- **Worker**: `/usr/local/etc/pherb/worker.conf` (per-jail, defines stages)
- **Consumer**: `/usr/local/etc/pherb/settings.ini` (symlinked into libexec)
- **API**: same settings.ini via Apache in pherb jail

### Example worker.conf (pyannote jail)

```ini
nats_url = nats://nats.freebsd-dev1.morante.com:4222

[stages]
pherb.worker.pyannote = /usr/local/bin/python3 /usr/local/libexec/pherb/pherb-diarize.py {audio_path} {output_path}
```

## RC Services

### Host

```sh
service pherb_worker start|stop|restart|status
```

### Pherb Jail

```sh
jexec pherb service pherb_consumer start|stop|restart|status
jexec pherb service apache24 start|stop|restart|status
```

## Monit

All services monitored via monit (`/usr/local/etc/monit.d/`):

| Config File | Service | PID File |
|-------------|---------|----------|
| `pherb-worker.rc` | pherb_worker | `/var/run/pherb_worker/pherb_worker.pid` |
| `pherb-consumer.rc` | pherb_consumer | Jail: `/var/run/pherb_consumer/pherb_consumer.pid` |
| `nats.rc` | nats | Jail pidfile |
| `mariadb.rc` | mariadb | Jail pidfile |
| `haproxy.rc` | haproxy | Host pidfile |
| `pyannote.rc` | pyannote_diarize | Jail pidfile |
| `wav2vec2.rc` | wav2vec2_align | Jail pidfile |

## Updating pherb-worker

1. Tag release in the Pherb repo (`git tag vX.Y.Z && git push --tags`)
2. Update `www/pherb-worker` FreeBSD port (DISTVERSION, distinfo)
3. Build updated package via poudriere
4. Deploy:
   ```sh
   service pherb_worker stop
   pkg update && pkg upgrade pherb-worker
   service pherb_worker start
   ```

## Updating pherb-consumer / pherb-api

1. Tag release, update `www/pherb` master port + slave ports
2. Build updated packages via poudriere
3. Deploy consumer:
   ```sh
   jexec pherb service pherb_consumer stop
   jexec pherb pkg update && jexec pherb pkg upgrade pherb-consumer
   jexec pherb service pherb_consumer start
   ```

## Bootstrap Path Fix

The consumer binary path in the package assumes `bin/pherb-consumer` with
`require_once __DIR__ . '/../system/bootstrap.inc.php'`. When installed to
`/usr/local/libexec/pherb/pherb-consumer` (flat layout), the port Makefile
must apply a REINPLACE_CMD to change `/../system` to `/system`.

## Troubleshooting

### Worker not processing jobs

1. Check NATS connectivity: `tail /var/log/pherb_worker.log`
2. Verify subscription: look for `subscribed to pherb.worker.>`
3. Check if worker is busy: `nats req pherb.worker.status ''` from nats jail

### Consumer not advancing pipeline

1. Check consumer log: `jexec pherb tail /var/log/pherb_consumer.log`
2. Verify NATS subscription to `pherb.pipeline.completed`
3. Check DB connectivity: verify settings.ini symlink resolves

### Stage output missing

- All temp/output files must be on the same filesystem (`/data/audio/outputs/`)
- Worker writes temp files as `.tmp_whisper_*` and `.tmp_wav_*` in output_dir
- Cross-mount rename will fail if temp files are on `/tmp/` (root UFS)
