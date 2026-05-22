#!/usr/local/bin/python3
"""
pherb-align.py — Forced word-level alignment wrapper for pherb-worker

Uses torchaudio's forced alignment utilities (wav2vec2-based) to produce
precise word-level timestamps from a transcript and audio file.

Usage: pherb-align.py <audio_path> <output_path>

The transcript is read from the whisper output file at the same directory
level as output_path, keyed by job_id (derived from output_path filename).

Environment variables (optional):
    ALIGN_MODEL — alignment model (default: WAV2VEC2_ASR_BASE_960H)
"""

import json
import os
import sys


def main():
    if len(sys.argv) < 3:
        print("Usage: pherb-align.py <audio_path> <output_path>", file=sys.stderr)
        sys.exit(1)

    audio_path = sys.argv[1]
    output_path = sys.argv[2]

    if not os.path.exists(audio_path):
        print(f"Audio file not found: {audio_path}", file=sys.stderr)
        sys.exit(1)

    import torch
    import torchaudio

    device = torch.device("cuda" if torch.cuda.is_available() else "cpu")

    # Load audio
    waveform, sample_rate = torchaudio.load(audio_path)
    if sample_rate != 16000:
        waveform = torchaudio.functional.resample(waveform, sample_rate, 16000)
        sample_rate = 16000

    # Load transcript — derive whisper output path from output_path
    output_dir = os.path.dirname(output_path)
    basename = os.path.basename(output_path)
    job_id = basename.split(".")[0]
    whisper_path = os.path.join(output_dir, f"{job_id}.whisper.json")

    if not os.path.exists(whisper_path):
        print(f"Whisper output not found: {whisper_path}", file=sys.stderr)
        sys.exit(1)

    with open(whisper_path) as f:
        whisper_data = json.load(f)

    # Extract words from whisper output
    transcript_words = []
    for seg in whisper_data.get("transcription", whisper_data.get("segments", [])):
        if "tokens" in seg:
            for token in seg["tokens"]:
                word = token.get("text", "").strip()
                if word and word != "[BLANK_AUDIO]":
                    transcript_words.append(word)
        elif "words" in seg:
            for w in seg["words"]:
                word = w.get("word", w.get("text", "")).strip()
                if word:
                    transcript_words.append(word)
        elif "text" in seg:
            for word in seg["text"].split():
                if word.strip():
                    transcript_words.append(word.strip())

    if not transcript_words:
        print("No words found in whisper output", file=sys.stderr)
        sys.exit(1)

    # Perform forced alignment
    bundle = torchaudio.pipelines.WAV2VEC2_ASR_BASE_960H
    model = bundle.get_model().to(device)
    labels = bundle.get_labels()

    with torch.inference_mode():
        emissions, _ = model(waveform.to(device))

    transcript_str = "|".join(transcript_words).upper()
    dictionary = {c: i for i, c in enumerate(labels)}

    tokens = []
    for char in transcript_str:
        if char in dictionary:
            tokens.append(dictionary[char])
        else:
            tokens.append(dictionary.get("-", 0))

    targets = torch.tensor([tokens], dtype=torch.int32, device=device)

    aligned_tokens, scores = torchaudio.functional.forced_align(
        emissions, targets, blank=0
    )

    # Convert token-level alignment to word-level
    ratio = waveform.shape[1] / emissions.shape[1] / sample_rate
    words_out = []
    word_idx = 0
    current_start = None
    current_end = None

    token_spans = aligned_tokens[0].tolist()
    score_spans = scores[0].tolist()

    i = 0
    for token_idx, score in zip(token_spans, score_spans):
        time_pos = i * ratio
        if token_idx != 0:  # not blank
            if current_start is None:
                current_start = time_pos
            current_end = time_pos
            char = labels[token_idx] if token_idx < len(labels) else ""
            if char == "|" and word_idx < len(transcript_words):
                words_out.append({
                    "word": transcript_words[word_idx],
                    "start": round(current_start, 3),
                    "end": round(current_end, 3),
                })
                word_idx += 1
                current_start = None
                current_end = None
        i += 1

    # Flush last word
    if current_start is not None and word_idx < len(transcript_words):
        words_out.append({
            "word": transcript_words[word_idx],
            "start": round(current_start, 3),
            "end": round(current_end, 3),
        })

    output = {"words": words_out}

    os.makedirs(os.path.dirname(output_path), exist_ok=True)
    with open(output_path, "w") as f:
        json.dump(output, f)

    print(f"Alignment complete: {len(words_out)} words written to {output_path}")


if __name__ == "__main__":
    main()
