<?php
namespace Pherb;

/**
 * WhisperCliBackend — Transcription via whisper-cli command-line tool.
 *
 * Shells out to whisper-cli which reads the audio file and writes JSON output.
 * No HTTP timeout concerns — the process runs to completion regardless of duration.
 * Memory is released after each invocation (no persistent server process).
 *
 * Requires: whisper.cpp CLI binary installed at the configured path.
 */
class WhisperCliBackend implements WhisperBackend
{
    private string $binaryPath;
    private string $modelsDir;
    private int $threads;

    /**
     * @param string $binaryPath Path to whisper-cli binary
     * @param string $modelsDir  Directory containing ggml-*.bin model files
     * @param int    $threads    Number of threads for inference
     */
    public function __construct(
        string $binaryPath = '/usr/local/bin/whisper-cli',
        string $modelsDir = '/models/whisper',
        int $threads = 8
    ) {
        $this->binaryPath = $binaryPath;
        $this->modelsDir = rtrim($modelsDir, '/');
        $this->threads = $threads;
    }

    /**
     * {@inheritdoc}
     */
    public function transcribe(string $filePath, string $model = 'medium.en', ?callable $heartbeat = null): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Audio file not found: {$filePath}");
        }

        if (!is_executable($this->binaryPath)) {
            throw new \RuntimeException("whisper-cli not found at: {$this->binaryPath}");
        }

        $modelPath = $this->modelsDir . '/ggml-' . $model . '.bin';
        if (!file_exists($modelPath)) {
            throw new \RuntimeException("Whisper model not found: {$modelPath}");
        }

        // Output to a temp file (whisper-cli appends .json to the -of path)
        $tmpBase = tempnam(sys_get_temp_dir(), 'pherb_whisper_');

        $cmd = sprintf(
            '%s -m %s -f %s -t %d --output-json-full -of %s 2>&1',
            escapeshellarg($this->binaryPath),
            escapeshellarg($modelPath),
            escapeshellarg($filePath),
            $this->threads,
            escapeshellarg($tmpBase)
        );

        exec($cmd, $output, $exitCode);

        // whisper-cli appends .json to the output file path
        $jsonFile = $tmpBase . '.json';

        // Clean up the bare temp file (whisper-cli creates its own with .json)
        @unlink($tmpBase);

        if ($exitCode !== 0) {
            @unlink($jsonFile);
            $errorOutput = implode("\n", array_slice($output, -10));
            throw new \RuntimeException("whisper-cli failed (exit {$exitCode}): {$errorOutput}");
        }

        if (!file_exists($jsonFile)) {
            throw new \RuntimeException("whisper-cli did not produce output file: {$jsonFile}");
        }

        $json = file_get_contents($jsonFile);
        @unlink($jsonFile);

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("whisper-cli produced invalid JSON");
        }

        // Normalize the full JSON format to match the HTTP server's verbose_json structure
        return $this->normalizeOutput($decoded);
    }

    /**
     * Normalize whisper-cli --output-json-full format to match the HTTP server's verbose_json.
     *
     * The CLI full JSON wraps transcription in a "transcription" key with slightly
     * different field names. Normalize to the same structure the HTTP server returns.
     */
    private function normalizeOutput(array $data): array
    {
        // CLI --output-json-full format:
        // { "transcription": [ { "timestamps": { "from": "...", "to": "..." },
        //   "offsets": { "from": 0, "to": 1000 }, "text": "...",
        //   "tokens": [ { "text": "...", "timestamps": {...}, "offsets": {...}, "p": 0.99 } ] } ] }
        //
        // HTTP server verbose_json format:
        // { "segments": [ { "start": 0.0, "end": 1.0, "text": "...",
        //   "words": [ { "word": "...", "start": 0.0, "end": 0.5, "probability": 0.99 } ] } ] }

        if (isset($data['segments'])) {
            return $data;
        }

        if (!isset($data['transcription'])) {
            return $data;
        }

        $segments = [];
        foreach ($data['transcription'] as $seg) {
            $words = [];
            foreach ($seg['tokens'] ?? [] as $token) {
                $text = trim($token['text'] ?? '');
                if ($text === '' || $text === '[BLANK_AUDIO]') {
                    continue;
                }
                $words[] = [
                    'word' => $text,
                    'start' => ($token['offsets']['from'] ?? 0) / 1000.0,
                    'end' => ($token['offsets']['to'] ?? 0) / 1000.0,
                    'probability' => (float)($token['p'] ?? 0),
                ];
            }

            $segments[] = [
                'start' => ($seg['offsets']['from'] ?? 0) / 1000.0,
                'end' => ($seg['offsets']['to'] ?? 0) / 1000.0,
                'text' => $seg['text'] ?? '',
                'words' => $words,
            ];
        }

        return ['segments' => $segments];
    }
}
