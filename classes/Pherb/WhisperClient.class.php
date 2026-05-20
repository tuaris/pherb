<?php
namespace Pherb;

/**
 * WhisperClient — Calls whisper.cpp HTTP server for transcription.
 */
class WhisperClient
{
    private \EnchiladaHTTP $http;

    public function __construct(string $baseUrl = 'http://127.0.0.1:8080')
    {
        $this->http = new \EnchiladaHTTP(rtrim($baseUrl, '/'));
        $this->http->setTimeout(900); // 15 min for long audio files
    }

    /**
     * Transcribe an audio file.
     *
     * @param string $filePath Absolute path to the audio file
     * @param string $model    Model name (e.g., 'medium.en')
     * @return array           Whisper verbose_json response
     */
    public function transcribe(string $filePath, string $model = 'medium.en'): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Audio file not found: {$filePath}");
        }

        $data = [
            'file' => new \CURLFile($filePath, 'audio/wav', basename($filePath)),
            'model' => $model,
            'response_format' => 'verbose_json',
            'word_timestamps' => 'true',
        ];

        $result = $this->http->call('inference', $data, 'POST', [], null, 'multipart');
        $httpCode = $this->http->getHttpCode();

        if ($result === false) {
            throw new \RuntimeException("Whisper request failed (HTTP {$httpCode})");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Whisper returned HTTP {$httpCode}");
        }

        $decoded = is_array($result) ? $result : json_decode($result, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("Whisper returned invalid JSON");
        }
        return $decoded;
    }
}
