<?php
namespace Pherb;

/**
 * Wav2vec2Client — Calls wav2vec2 forced alignment FastAPI service.
 */
class Wav2vec2Client
{
    private \EnchiladaHTTP $http;

    public function __construct(string $baseUrl = 'http://127.0.0.1:9091')
    {
        $this->http = new \EnchiladaHTTP(rtrim($baseUrl, '/'));
        $this->http->setTimeout(900); // 15 min for long audio alignment on CPU
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

        $data = [
            'file' => new \CURLFile($filePath, 'audio/wav', basename($filePath)),
            'transcript' => json_encode($transcript),
        ];

        $result = $this->http->call('align', $data, 'POST', [], null, 'multipart');
        $httpCode = $this->http->getHttpCode();

        if ($result === false) {
            throw new \RuntimeException("Wav2vec2 request failed (HTTP {$httpCode})");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Wav2vec2 returned HTTP {$httpCode}");
        }

        $decoded = is_array($result) ? $result : json_decode($result, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Wav2vec2 returned invalid JSON");
        }
        return $decoded['words'] ?? $decoded;
    }
}
