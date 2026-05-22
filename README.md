# Pherb

Speech processing orchestrator. Coordinates audio conversion, whisper.cpp transcription, pyannote speaker diarization, and wav2vec2 forced alignment via NATS and generic workers.

## Architecture

```
Client → WebDAV (upload audio) → POST /api/v1/jobs → NATS JetStream
         ↓
         Consumer (orchestrator) dispatches stages via NATS:
           1. convert  — ffmpeg: any format → 16kHz mono WAV
           2. whisper  — whisper-cli: transcription
           3. pyannote — pyannote.audio: speaker diarization
           4. align    — torchaudio: forced word-level alignment
         ↓
         Consumer finalizes: merge → Output (JSON/SRT/VTT)
```

Each stage runs on a generic `pherb-worker` binary (Zig) that can be deployed anywhere — separate jails, remote GPU hosts, etc. Workers subscribe to NATS, run a configured command, and report back.

## Components

- **pherb-consumer** (PHP) — Pipeline orchestrator. Owns all paths and stage ordering.
- **pherb-worker** (Zig) — Generic NATS-to-process bridge. Configured per-stage via `worker.conf`.
- **pherb-api** (PHP) — REST API for job submission and status queries.
- **examples/** — Reference wrapper scripts for each stage (convert, whisper, diarize, align).

## Requirements

- PHP 8.4+ with curl, json, pcntl, pdo_mysql, posix
- MariaDB 10.11+
- NATS Server with JetStream
- ffmpeg (for audio conversion stage)
- whisper.cpp (for transcription stage)
- pyannote.audio + PyTorch (for diarization stage)
- torchaudio (for alignment stage, optional)

## Quick Start

```sh
# 1. Copy config
cp config/settings.ini.sample config/settings.ini
# Edit with your database credentials and NATS host

# 2. Create a worker config
cp config/worker.conf.sample /usr/local/etc/pherb/worker.conf
# Edit [stages] section with the commands for this worker

# 3. Start the consumer daemon
php bin/pherb-consumer

# 4. Start the worker
./cmd/pherb-worker/zig-out/bin/pherb-worker -c /usr/local/etc/pherb/worker.conf

# 5. Submit a job via API
curl -X POST http://localhost/api/v1/jobs \
  -H 'Content-Type: application/json' \
  -d '{"audio": "meeting.m4a", "options": {"model": "medium.en", "diarize": true}}'
```

## API Endpoints

| Method | Path | Description |
|--------|------|-------------|
| POST | /api/v1/jobs | Submit a transcription job |
| GET | /api/v1/jobs/{id} | Get job status |
| GET | /api/v1/jobs | List recent jobs |
| GET | /api/v1/health | Health check |

## Output Formats

- **json** — Full transcript with speaker segments and word-level timestamps
- **srt** — SubRip subtitles with `[Speaker]` prefix
- **vtt** — WebVTT with `<v Speaker>` voice cues

## License

BSD 2-Clause. Copyright 2026 The Daniel Morante Company, Inc.
