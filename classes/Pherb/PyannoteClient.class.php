<?php
namespace Pherb;

/**
 * PyannoteClient — Calls pyannote speaker diarization FastAPI service.
 */
class PyannoteClient
{
    private string $baseUrl;

    public function __construct(string $baseUrl = 'http://127.0.0.1:9090')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    /**
     * Perform speaker diarization on an audio file.
     *
     * @param string $filePath Absolute path to the audio file
     * @return array           Array of speaker segments [{start, end, speaker}, ...]
     */
    public function diarize(string $filePath): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Audio file not found: {$filePath}");
        }

        $postFields = [
            'file' => new \CURLFile($filePath, 'audio/wav', basename($filePath)),
        ];

        $ch = curl_init($this->baseUrl . '/diarize');
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
            throw new \RuntimeException("Pyannote request failed: {$error}");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Pyannote returned HTTP {$httpCode}: " . substr($response, 0, 500));
        }

        $data = json_decode($response, true);
        if ($data === null) {
            throw new \RuntimeException("Pyannote returned invalid JSON");
        }

        return $data['segments'] ?? $data;
    }
}
