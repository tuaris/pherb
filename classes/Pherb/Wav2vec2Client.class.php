<?php
namespace Pherb;

/**
 * Wav2vec2Client — Calls wav2vec2 forced alignment FastAPI service.
 */
class Wav2vec2Client
{
    private string $baseUrl;

    public function __construct(string $baseUrl = 'http://127.0.0.1:9091')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Perform forced word-level alignment.
     *
     * @param string $filePath   Absolute path to the audio file
     * @param array  $transcript Array of words/segments to align
     * @return array             Aligned word timestamps
     */
    public function align(string $filePath, array $transcript): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Audio file not found: {$filePath}");
        }

        $postFields = [
            'file' => new \CURLFile($filePath, 'audio/wav', basename($filePath)),
            'transcript' => json_encode($transcript),
        ];

        $ch = curl_init($this->baseUrl . '/align');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_USERAGENT => defined('APPLICATION_USERAGENT') ? APPLICATION_USERAGENT : 'Pherb/0.1',
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException("Wav2vec2 request failed: {$error}");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Wav2vec2 returned HTTP {$httpCode}: " . substr($response, 0, 500));
        }

        $data = json_decode($response, true);
        if ($data === null) {
            throw new \RuntimeException("Wav2vec2 returned invalid JSON");
        }

        return $data['words'] ?? $data;
    }
}
