#!/usr/local/bin/python3
"""
pherb-diarize.py — Speaker diarization wrapper for pherb-worker

Uses pyannote.audio 3.x Pipeline API to perform speaker diarization.
Writes JSON output in the format expected by the Pherb consumer.

Usage: pherb-diarize.py <audio_path> <output_path>

Environment variables (optional):
    HF_TOKEN          — HuggingFace auth token (for gated models)
    PYANNOTE_MODEL    — model name (default: pyannote/speaker-diarization-3.1)
"""

import json
import os
import sys


def main():
    if len(sys.argv) < 3:
        print("Usage: pherb-diarize.py <audio_path> <output_path>", file=sys.stderr)
        sys.exit(1)

    audio_path = sys.argv[1]
    output_path = sys.argv[2]

    if not os.path.exists(audio_path):
        print(f"Audio file not found: {audio_path}", file=sys.stderr)
        sys.exit(1)

    model_name = os.environ.get("PYANNOTE_MODEL", "pyannote/speaker-diarization-3.1")
    hf_token = os.environ.get("HF_TOKEN") or None

    from pyannote.audio import Pipeline

    pipeline = Pipeline.from_pretrained(model_name, token=hf_token)

    result = pipeline(audio_path)

    # pyannote 4.x returns DiarizeOutput dataclass; 3.x returns Annotation directly
    if hasattr(result, 'speaker_diarization'):
        diarization = result.speaker_diarization
    else:
        diarization = result

    segments = []
    for turn, _, speaker in diarization.itertracks(yield_label=True):
        segments.append({
            "start": round(turn.start, 3),
            "end": round(turn.end, 3),
            "speaker": speaker,
        })

    output = {"segments": segments}

    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    with open(output_path, "w") as f:
        json.dump(output, f)

    print(f"Diarization complete: {len(segments)} segments written to {output_path}")


if __name__ == "__main__":
    main()
