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
    public function transcribe(string $filePath, string $model = 'medium.en', ?callable $heartbeat = null): array
    {
        if (!file_exists($filePath)) {
            throw new \RuntimeException("Audio file not found: {$filePath}");
        }

        $payload = json_encode([
            'job_id' => basename($filePath, '.' . pathinfo($filePath, PATHINFO_EXTENSION)),
            'audio_path' => $filePath,
            'model' => $model,
        ]);

        // Create a dedicated NATS client — short connection timeout, long overall deadline
        $config = new NatsConfiguration(
            host: $this->natsHost,
            port: $this->natsPort,
            timeout: 60.0,
        );

        $client = new NatsClient($config);
        $client->setName('pherb-whisper-requester');
        $client->skipInvalidMessages(true);

        $result = null;
        $error = null;

        $handler = function ($response) use (&$result, &$error) {
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

            if (isset($decoded['status']) && $decoded['status'] === 'busy') {
                $activeJob = $decoded['active_job'] ?? 'unknown';
                $error = "Whisper worker is busy processing job: {$activeJob}";
                return;
            }

            $result = $decoded;
        };

        // Manual request/reply with heartbeat loop — replaces blocking Client::request().
        // Subscribe to a unique reply subject, publish request with reply-to, then
        // loop with 60s iterations calling the heartbeat callback between each.
        // This allows the consumer to send JetStream InProgress signals.
        $replyTo = '_REPLY.' . bin2hex(random_bytes(12));
        $client->subscribe($replyTo, $handler);
        $client->publish($this->subject, $payload, $replyTo);

        $deadline = microtime(true) + $this->timeout;
        while ($result === null && $error === null) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }

            $wait = min(60.0, $remaining);
            $client->process($wait);

            if ($result === null && $error === null && $heartbeat !== null) {
                $heartbeat();
            }
        }

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
