<?php
namespace Pherb;

/**
 * TranscriptMerger — Merges whisper transcript words with speaker diarization segments.
 *
 * Attributes each word to a speaker based on temporal overlap.
 */
class TranscriptMerger
{
    /**
     * Merge whisper word timestamps with pyannote speaker segments.
     *
     * @param array $whisperResult  Whisper verbose_json output (has 'segments' with 'words')
     * @param array $speakerSegments Pyannote diarization [{start, end, speaker}, ...]
     * @return array Merged segments grouped by speaker turns
     */
    public function merge(array $whisperResult, array $speakerSegments): array
    {
        // Extract all words with timestamps from Whisper output
        $words = $this->extractWords($whisperResult);

        // Assign each word to a speaker
        foreach ($words as &$word) {
            $word['speaker'] = $this->findSpeaker($word['start'], $word['end'], $speakerSegments);
        }
        unset($word);

        // Group consecutive words by speaker into segments
        return $this->groupBySpeaker($words);
    }

    /**
     * Extract flat word list from Whisper verbose_json output.
     */
    private function extractWords(array $whisperResult): array
    {
        $words = [];

        $segments = $whisperResult['segments'] ?? [];
        foreach ($segments as $segment) {
            $segmentWords = $segment['words'] ?? [];
            foreach ($segmentWords as $w) {
                $words[] = [
                    'word' => trim($w['word'] ?? $w['text'] ?? ''),
                    'start' => (float)($w['start'] ?? 0),
                    'end' => (float)($w['end'] ?? 0),
                    'probability' => (float)($w['probability'] ?? $w['confidence'] ?? 0),
                ];
            }
        }

        return $words;
    }

    /**
     * Find which speaker owns a given time range.
     * Uses midpoint overlap: the speaker whose segment contains the word's midpoint wins.
     */
    private function findSpeaker(float $wordStart, float $wordEnd, array $speakerSegments): string
    {
        $midpoint = ($wordStart + $wordEnd) / 2.0;

        foreach ($speakerSegments as $seg) {
            $segStart = (float)($seg['start'] ?? 0);
            $segEnd = (float)($seg['end'] ?? 0);

            if ($midpoint >= $segStart && $midpoint <= $segEnd) {
                return $seg['speaker'] ?? 'UNKNOWN';
            }
        }

        return 'UNKNOWN';
    }

    /**
     * Group words into speaker-turn segments.
     * Consecutive words by the same speaker are merged into one segment.
     */
    private function groupBySpeaker(array $words): array
    {
        if (empty($words)) return [];

        $segments = [];
        $currentSegment = null;

        foreach ($words as $word) {
            if ($currentSegment === null || $word['speaker'] !== $currentSegment['speaker']) {
                if ($currentSegment !== null) {
                    $currentSegment['text'] = implode(' ', array_column($currentSegment['words'], 'word'));
                    $currentSegment['end'] = end($currentSegment['words'])['end'];
                    $segments[] = $currentSegment;
                }
                $currentSegment = [
                    'start' => $word['start'],
                    'end' => $word['end'],
                    'speaker' => $word['speaker'],
                    'words' => [],
                    'text' => '',
                ];
            }
            $currentSegment['words'][] = $word;
        }

        // Final segment
        if ($currentSegment !== null) {
            $currentSegment['text'] = implode(' ', array_column($currentSegment['words'], 'word'));
            $currentSegment['end'] = end($currentSegment['words'])['end'];
            $segments[] = $currentSegment;
        }

        return $segments;
    }
}
