<?php
namespace Pherb;

use Basis\Nats\Client as NatsClient;
use Basis\Nats\Configuration as NatsConfiguration;

/**
 * WhisperNatsBackend — Transcription via NATS request/reply to a host-side worker.
 *
 * Publishes a transcription request to NATS and waits for the worker process
 * (whisper-worker binary on the host) to execute whisper-cli and reply with
 * the result. No HTTP timeouts — the NATS request blocks until the worker
 * replies or the timeout expires.
 *
 * Requires: whisper-worker running on the host, connected to the same NATS server.
 */
class WhisperNatsBackend implements WhisperBackend
{
    private string $natsHost;
    private int $natsPort;
    private string $subject;
    private float $timeout;

    /**
     * @param string $natsHost NATS server hostname
     * @param int    $natsPort NATS server port
     * @param string $subject  NATS subject for whisper requests
     * @param float  $timeout  Request timeout in seconds
     */
    public function __construct(
        string $natsHost = '127.0.0.1',
        int $natsPort = 4222,
        string $subject = 'pherb.whisper.transcribe',
        float $timeout = 900.0
    ) {
        $this->natsHost = $natsHost;
        $this->natsPort = $natsPort;
        $this->subject = $subject;
        $this->timeout = $timeout;
    }

    /**
     * {@inheritdoc}
     */
    public function transcribe(string $filePath, string $model = 'medium.en'): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Audio file not found: {$filePath}");
        }

        $payload = json_encode([
            'job_id' => basename($filePath, '.' . pathinfo($filePath, PATHINFO_EXTENSION)),
            'audio_path' => $filePath,
            'model' => $model,
        ]);

        // Create a dedicated NATS client with long timeout for request/reply
        $config = new NatsConfiguration(
            host: $this->natsHost,
            port: $this->natsPort,
            timeout: $this->timeout,
        );

        $client = new NatsClient($config);
        $client->setName('pherb-whisper-requester');

        $result = null;
        $error = null;

        $client->request($this->subject, $payload, function ($response) use (&$result, &$error) {
            $body = $response->body ?? (string)$response;
            $decoded = json_decode($body, true);

            if (!is_array($decoded)) {
                $error = "Whisper worker returned invalid JSON";
                return;
            }

            if (isset($decoded['error'])) {
                $error = "Whisper worker error: " . $decoded['error'];
                return;
            }

            $result = $decoded;
        });

        $client->disconnect();

        if ($error !== null) {
            throw new \RuntimeException($error);
        }

        if ($result === null) {
            throw new \RuntimeException("Whisper worker did not respond within {$this->timeout}s timeout");
        }

        // Normalize CLI output format if needed
        return $this->normalizeOutput($result);
    }

    /**
     * Normalize whisper-cli --output-json-full format to verbose_json structure.
     */
    private function normalizeOutput(array $data): array
    {
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
