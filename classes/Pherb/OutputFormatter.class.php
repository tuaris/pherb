<?php
namespace Pherb;

/**
 * OutputFormatter — Converts merged transcript segments to various output formats.
 */
class OutputFormatter
{
    /**
     * Format as JSON.
     */
    public function toJson(array $segments, array $meta = []): string
    {
        // Collect unique speakers
        $speakers = array_values(array_unique(array_column($segments, 'speaker')));

        $output = array_merge($meta, [
            'speakers' => $speakers,
            'segments' => array_map(function ($seg) {
                return [
                    'start' => $seg['start'],
                    'end' => $seg['end'],
                    'speaker' => $seg['speaker'],
                    'text' => $seg['text'],
                    'words' => $seg['words'] ?? [],
                ];
            }, $segments),
        ]);

        return json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Format as SRT (SubRip).
     */
    public function toSrt(array $segments): string
    {
        $lines = [];
        $index = 1;

        foreach ($segments as $seg) {
            $start = $this->formatSrtTime($seg['start']);
            $end = $this->formatSrtTime($seg['end']);
            $speaker = $seg['speaker'] ?? 'UNKNOWN';
            $text = $seg['text'] ?? '';

            $lines[] = (string)$index;
            $lines[] = "{$start} --> {$end}";
            $lines[] = "[{$speaker}] {$text}";
            $lines[] = '';
            $index++;
        }

        return implode("\n", $lines);
    }

    /**
     * Format as WebVTT.
     */
    public function toVtt(array $segments): string
    {
        $lines = ['WEBVTT', ''];

        foreach ($segments as $seg) {
            $start = $this->formatVttTime($seg['start']);
            $end = $this->formatVttTime($seg['end']);
            $speaker = $seg['speaker'] ?? 'UNKNOWN';
            $text = $seg['text'] ?? '';

            $lines[] = "{$start} --> {$end}";
            $lines[] = "<v {$speaker}>{$text}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /**
     * Format seconds as SRT timestamp (HH:MM:SS,mmm).
     */
    private function formatSrtTime(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);
        $millis = round(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d,%03d', $hours, $minutes, $secs, $millis);
    }

    /**
     * Format seconds as VTT timestamp (HH:MM:SS.mmm).
     */
    private function formatVttTime(float $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = floor($seconds % 60);
        $millis = round(($seconds - floor($seconds)) * 1000);

        return sprintf('%02d:%02d:%02d.%03d', $hours, $minutes, $secs, $millis);
    }
}
