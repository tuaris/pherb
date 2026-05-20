<?php
namespace Pherb;

/**
 * WhisperClient — Calls whisper.cpp HTTP server for transcription.
 */
class WhisperClient
{
    private string $baseUrl;
    private \EnchiladaHTTP $http;

    public function __construct(string $baseUrl = 'http://127.0.0.1:8080')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->http = new \EnchiladaHTTP($this->baseUrl);
        $this->http->setTimeout(300); // 5 min for long audio
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

        $postFields = [
            'file' => new \CURLFile($filePath, 'audio/wav', basename($filePath)),
            'model' => $model,
            'response_format' => 'verbose_json',
            'word_timestamps' => 'true',
        ];

        $ch = curl_init($this->baseUrl . '/inference');
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
            throw new \RuntimeException("Whisper request failed: {$error}");
        }

        if ($httpCode !== 200) {
            throw new \RuntimeException("Whisper returned HTTP {$httpCode}: " . substr($response, 0, 500));
        }

        $data = json_decode($response, true);
        if ($data === null) {
            throw new \RuntimeException("Whisper returned invalid JSON");
        }

        return $data;
    }
}
