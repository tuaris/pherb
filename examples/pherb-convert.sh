#!/bin/sh
#
# pherb-convert.sh — Audio conversion wrapper for pherb-worker
#
# Converts any audio format to 16kHz mono WAV suitable for ML inference.
# If input is already WAV, copies it to output_path unchanged.
#
# Usage: pherb-convert.sh <audio_path> <output_path> <job_id>
#
# Environment variables (optional):
#   FFMPEG_BIN — path to ffmpeg (default: /usr/local/bin/ffmpeg)
#

set -e

AUDIO_PATH="$1"
OUTPUT_PATH="$2"
JOB_ID="$3"

if [ -z "$AUDIO_PATH" ] || [ -z "$OUTPUT_PATH" ] || [ -z "$JOB_ID" ]; then
    echo "Usage: pherb-convert.sh <audio_path> <output_path> <job_id>" >&2
    exit 1
fi

FFMPEG_BIN="${FFMPEG_BIN:-/usr/local/bin/ffmpeg}"

if [ ! -f "$AUDIO_PATH" ]; then
    echo "Audio file not found: $AUDIO_PATH" >&2
    exit 1
fi

case "$AUDIO_PATH" in
    *.wav|*.WAV)
        cp "$AUDIO_PATH" "$OUTPUT_PATH"
        ;;
    *)
        "$FFMPEG_BIN" -y -i "$AUDIO_PATH" -ar 16000 -ac 1 "$OUTPUT_PATH" 2>/dev/null
        ;;
esac
