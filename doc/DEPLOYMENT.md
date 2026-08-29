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

The worker is stage-agnostic — a generic NATS-to-process bridge. Each instance
pulls from a single JetStream durable consumer and runs the configured command.
Multiple workers for the same stage compete for jobs (load balancing). The
consumer (orchestrator) owns all paths and pipeline logic.

```
┌──────────────────────────────────────────────────────────────────────┐
│ Consumer (orchestrator)                                               │
│   - Publishes stage messages to JetStream subjects                    │
│   - Advances pipeline on completion events                            │
│   - Merges + formats final output                                     │
│   - Optionally dispatches delivery stage                              │
└───────────┬──────────────┬──────────────┬──────────┬──────────┬──────┘
            │              │              │          │          │
    ┌───────▼──────┐ ┌────▼─────┐ ┌──────▼──────┐ ┌▼────────┐ ┌▼────────┐
    │ convert      │ │ whisper  │ │ pyannote    │ │ align   │ │ deliver │
    │ worker(s)    │ │ worker(s)│ │ worker(s)   │ │ worker  │ │ worker  │
    │ (any host)   │ │ (GPU)    │ │ (GPU)       │ │ (CPU)   │ │ (CPU)   │
    └──────────────┘ └──────────┘ └─────────────┘ └─────────┘ └─────────┘
         ↑                ↑              ↑             ↑            ↑
    Pull consumer    Pull consumer  Pull consumer Pull consumer Pull consumer
    pherb-stage-     pherb-stage-   pherb-stage-  pherb-stage- pherb-stage-
    convert          whisper        pyannote      align        deliver
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

All subjects are captured by the PHERB JetStream stream. Workers use pull
consumers for at-most-once delivery with competing consumer load balancing.

| Subject | Purpose |
|---------|---------|
| `pherb.jobs.transcribe` | New job requests (API → consumer) |
| `pherb.stage.convert` | Consumer → convert workers |
| `pherb.stage.whisper` | Consumer → whisper workers |
| `pherb.stage.pyannote` | Consumer → pyannote workers |
| `pherb.stage.align` | Consumer → alignment workers |
| `pherb.stage.deliver` | Consumer → delivery workers (optional) |
| `pherb.pipeline.completed` | Worker → consumer: stage completion/failure |

### Durable Consumers

| Consumer Name | Filter Subject | Ack Wait | Purpose |
|---|---|---|---|
| `pherb-jobs` | `pherb.jobs.transcribe` | 120s | Orchestrator pulls new jobs |
| `pherb-stage-convert` | `pherb.stage.convert` | 60s | Convert workers compete |
| `pherb-stage-whisper` | `pherb.stage.whisper` | 3600s | Whisper workers compete |
| `pherb-stage-pyannote` | `pherb.stage.pyannote` | 300s | Pyannote workers compete |
| `pherb-stage-align` | `pherb.stage.align` | 300s | Alignment workers compete |
| `pherb-stage-deliver` | `pherb.stage.deliver` | 120s | Delivery workers compete |
| `pherb-pipeline` | `pherb.pipeline.completed` | 30s | Orchestrator processes completions |

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
  → Consumer dispatches pherb.stage.convert
    → Worker pulls from pherb-stage-convert, runs ffmpeg, writes .convert.wav
    → Worker publishes pherb.pipeline.completed {stage: "convert"}
  → Consumer dispatches pherb.stage.whisper
    → Worker pulls from pherb-stage-whisper, runs whisper-cli, writes .whisper.json
    → Worker publishes pherb.pipeline.completed {stage: "whisper"}
  → Consumer dispatches pherb.stage.pyannote (if diarize=true)
    → Worker pulls from pherb-stage-pyannote, runs pyannote, writes .pyannote.json
    → Worker publishes pherb.pipeline.completed {stage: "pyannote"}
  → Consumer dispatches pherb.stage.align (if align=true)
    → Worker pulls from pherb-stage-align, runs wav2vec2, writes .align.json
    → Worker publishes pherb.pipeline.completed {stage: "alignment"}
  → Consumer finalizes: merge outputs, format, write result
  → Consumer dispatches pherb.stage.deliver (if delivery config present)
    → Worker pulls from pherb-stage-deliver, routes artifact to destination
    → Worker publishes pherb.pipeline.completed {stage: "deliver"}
  → Consumer marks job completed
```

## Shared Storage

ZFS dataset `models/audio` mounted at `/data/audio` (lz4 compression):

```
/data/audio/              — audio input files
/data/audio/outputs/      — stage outputs + final results
```

nullfs-mounted into all worker jails (rw) and webdav (rw).

Each jail that needs audio access must have `mount.fstab` set in its
jail.conf.d entry:

```sh
# /etc/jail.conf.d/pherb.conf
pherb { mount.fstab = /etc/fstab.pherb; }

# /etc/fstab.pherb
/data/audio /usr/local/jails/pherb/data/audio nullfs rw 0 0
```

Repeat for webdav, ffmpeg, pyannote, wav2vec2, and any other jails
that run workers. Without `mount.fstab`, the nullfs entry in fstab
will not be mounted when the jail starts.

## Configuration

- **Worker**: `/usr/local/etc/pherb/worker.conf` (per-jail, defines stages)
- **Consumer**: `/usr/local/etc/pherb/settings.ini` (symlinked into libexec)
- **API**: same settings.ini via Apache in pherb jail

### Example worker.conf (pyannote jail)

```ini
nats_url = nats://nats.transcribe.morante.com:4222

[stage]
subject = pherb.stage.pyannote
consumer = pherb-stage-pyannote
command = /usr/local/bin/python3.11 /usr/local/libexec/pherb/pherb-diarize.py
ack_wait = 300
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
2. Verify pull consumer: look for `pull consumer ready, waiting for jobs...`
3. Check consumer pending: `nats consumer info PHERB pherb-stage-whisper`
4. Check stream messages: `nats stream info PHERB`

### Consumer not advancing pipeline

1. Check consumer log: `jexec pherb tail /var/log/pherb_consumer.log`
2. Verify NATS subscription to `pherb.pipeline.completed`
3. Check DB connectivity: verify settings.ini symlink resolves

### Stage output missing

- All temp/output files must be on the same filesystem (`/data/audio/outputs/`)
- Worker writes temp files as `.tmp_whisper_*` and `.tmp_wav_*` in output_dir
- Cross-mount rename will fail if temp files are on `/tmp/` (root UFS)
