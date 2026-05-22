# Pherb Deployment Guide

Pherb is designed to run on FreeBSD with a jail-based architecture. The worker
runs on the host (alongside whisper-cli and audio models), while the consumer,
API, and supporting services each run in their own jail.

## Components

| Component | Location | Description |
|-----------|----------|-------------|
| pherb-worker | Host: `/usr/local/bin/pherb-worker` | Multi-stage ML pipeline worker (Zig binary) |
| pherb-consumer | Jail: `/usr/local/libexec/pherb/` | NATS JetStream consumer daemon (PHP) |
| pherb-api | Jail: `/usr/local/www/pherb/` | REST API (PHP, Apache) |
| whisper-cli | Host: `/usr/local/bin/whisper-cli` | Speech-to-text engine |
| pyannote | Jail: FastAPI on `:9090` | Speaker diarization service |
| wav2vec2 | Jail: FastAPI on `:9091` | Forced alignment service |
| NATS | Jail: `:4222` | Message broker with JetStream |
| MariaDB | Jail: `:3306` | Job database |
| HAProxy | Host | TLS termination and routing |

## Jails

Six jails, all using `ip4=inherit` (shared host IP):

| Jail | Purpose | Port | Audio Mount |
|------|---------|------|-------------|
| pherb | Consumer + API (PHP, Apache) | 8082 | /data/audio (rw) |
| webdav | Audio upload/download | 8081 | /data/audio (rw) |
| nats | NATS JetStream | 4222 | — |
| mariadb | MariaDB | 3306 | — |
| pyannote | Speaker diarization (Python venv) | 9090 | /data/audio (ro) |
| wav2vec2 | Forced alignment (Python venv) | 9091 | /data/audio (ro) |

## NATS Subjects

| Subject | Type | Purpose |
|---------|------|---------|
| `pherb.jobs.transcribe` | JetStream (PHERB stream) | New job requests (API → consumer) |
| `pherb.worker.whisper` | Plain NATS | Consumer → worker: transcription stage |
| `pherb.worker.pyannote` | Plain NATS | Consumer → worker: diarization stage |
| `pherb.worker.align` | Plain NATS | Consumer → worker: alignment stage |
| `pherb.pipeline.completed` | Plain NATS | Worker → consumer: stage completion events |
| `pherb.worker.status` | Plain NATS req/reply | Stale job detection |

## Pipeline Flow

```
API POST /jobs → JetStream (pherb.jobs.transcribe)
  → Consumer dispatches pherb.worker.whisper
    → Worker spawns whisper-cli (kqueue monitors child)
    → Worker publishes pherb.pipeline.completed {stage: "whisper"}
  → Consumer dispatches pherb.worker.pyannote (if diarize=true)
    → Worker spawns curl → pyannote FastAPI
    → Worker publishes pherb.pipeline.completed {stage: "pyannote"}
  → Consumer dispatches pherb.worker.align (if align=true)
    → Worker spawns curl → wav2vec2 FastAPI
    → Worker publishes pherb.pipeline.completed {stage: "alignment"}
  → Consumer finalizes: merge outputs, format, write result, mark completed
```

## Shared Storage

ZFS dataset `models/audio` mounted at `/data/audio` (lz4 compression):

```
/data/audio/              — audio input files
/data/audio/outputs/      — stage outputs + final results
```

nullfs-mounted into jails: pherb (rw), webdav (rw), pyannote (ro), wav2vec2 (ro).

## Configuration

- **Worker**: no config file — uses compiled defaults (NATS `127.0.0.1:4222`,
  whisper-cli at `/usr/local/bin/whisper-cli`, models at `/models/whisper`,
  output at `/data/audio/outputs`)
- **Consumer**: `/usr/local/etc/pherb/settings.ini` (symlinked into libexec)
- **API**: same settings.ini via Apache in pherb jail

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
