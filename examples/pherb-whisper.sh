#!/bin/sh
#
# pherb-whisper.sh — Whisper transcription wrapper for pherb-worker
#
# Assumes input audio is already in WAV format (conversion is a separate stage).
# Writes JSON output to the path specified by the orchestrator.
#
# Usage: pherb-whisper.sh <audio_path> <output_path> <job_id>
#
# Environment variables (optional):
#   WHISPER_BIN        — path to whisper-cli (default: /usr/local/bin/whisper-cli)
#   WHISPER_MODEL      — model name (default: medium.en)
#   WHISPER_MODELS_DIR — directory containing ggml-*.bin (default: /models/whisper)
#   WHISPER_THREADS    — number of threads (default: 8)
#

set -e

AUDIO_PATH="$1"
OUTPUT_PATH="$2"
JOB_ID="$3"

if [ -z "$AUDIO_PATH" ] || [ -z "$OUTPUT_PATH" ] || [ -z "$JOB_ID" ]; then
    echo "Usage: pherb-whisper.sh <audio_path> <output_path> <job_id>" >&2
    exit 1
fi

WHISPER_BIN="${WHISPER_BIN:-/usr/local/bin/whisper-cli}"
WHISPER_MODEL="${WHISPER_MODEL:-medium.en}"
WHISPER_MODELS_DIR="${WHISPER_MODELS_DIR:-/models/whisper}"
WHISPER_THREADS="${WHISPER_THREADS:-8}"

MODEL_PATH="${WHISPER_MODELS_DIR}/ggml-${WHISPER_MODEL}.bin"

if [ ! -f "$MODEL_PATH" ]; then
    echo "Model not found: $MODEL_PATH" >&2
    exit 1
fi

# whisper-cli appends .json to the -of path, so use a temp base
OUTPUT_DIR=$(dirname "$OUTPUT_PATH")
TMP_BASE="${OUTPUT_DIR}/.tmp_whisper_${JOB_ID}"

cleanup() {
    rm -f "${TMP_BASE}" "${TMP_BASE}.json"
}
trap cleanup EXIT

"$WHISPER_BIN" \
    -m "$MODEL_PATH" \
    -f "$AUDIO_PATH" \
    -t "$WHISPER_THREADS" \
    --output-json-full \
    -of "$TMP_BASE"

# Move whisper output to the requested output_path
mv "${TMP_BASE}.json" "$OUTPUT_PATH"
