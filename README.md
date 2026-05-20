# Pherb

Speech processing orchestrator that coordinates whisper.cpp, pyannote, and wav2vec2 services via NATS JetStream, with WebDAV file storage and async job processing.

## Architecture

```
Client → WebDAV (upload audio) → POST /api/v1/jobs → NATS → Consumer
         ↓
         whisper.cpp (transcription)
         pyannote (speaker diarization)
         wav2vec2 (forced alignment)
         ↓
         Merge → Output (JSON/SRT/VTT) → WebDAV (download result)
```

## Requirements

- PHP 8.4+ with curl, openssl, pdo_mysql
- MariaDB 10.11+
- NATS Server with JetStream
- whisper.cpp HTTP server
- pyannote FastAPI service
- wav2vec2 FastAPI service

## Quick Start

```sh
# 1. Copy config
cp config/settings.ini.sample config/settings.ini
# Edit with your service URLs and database credentials

# 2. Start the consumer daemon
php bin/pherb-consumer

# 3. Submit a job via API
curl -X POST http://localhost/api/v1/jobs \
  -H 'Content-Type: application/json' \
  -d '{"audio": "meeting.wav", "options": {"model": "medium.en", "diarize": true}}'
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
