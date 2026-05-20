<?php
namespace Pherb;

/**
 * PyannoteClient — Calls pyannote speaker diarization FastAPI service.
 */
class PyannoteClient
{
    private \EnchiladaHTTP $http;

    public function __construct(string $baseUrl = 'http://127.0.0.1:9090')
    {
        $this->http = new \EnchiladaHTTP(rtrim($baseUrl, '/'));
        $this->http->setTimeout(300);
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

        $data = [
            'file' => new \CURLFile($filePath, 'audio/wav', basename($filePath)),
        ];

        $result = $this->http->call('diarize', $data, 'POST', [], null, 'multipart');
        $httpCode = $this->http->getHttpCode();

        if ($result === false) {
            throw new \RuntimeException("Pyannote request failed (HTTP {$httpCode})");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Pyannote returned HTTP {$httpCode}");
        }

        return $result['segments'] ?? $result;
    }
}
