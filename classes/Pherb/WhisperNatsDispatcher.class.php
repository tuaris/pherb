<?php
namespace Pherb;

use Basis\Nats\Client as NatsClient;
use Basis\Nats\Configuration as NatsConfiguration;

/**
 * WhisperNatsDispatcher — Fire-and-forget dispatch of transcription requests via NATS.
 *
 * Publishes a small control message to the whisper-worker. Does NOT wait for a reply.
 * The worker writes its output to shared storage and publishes a completion event
 * to pherb.whisper.completed, which the consumer listens for separately.
 */
class WhisperNatsDispatcher
{
    private string $natsHost;
    private int $natsPort;
    private string $subject;

    public function __construct(
        string $natsHost = '127.0.0.1',
        int $natsPort = 4222,
        string $subject = 'pherb.whisper.transcribe'
    ) {
        $this->natsHost = $natsHost;
        $this->natsPort = $natsPort;
        $this->subject = $subject;
    }

    /**
     * Dispatch a transcription request to the whisper-worker.
     *
     * Publishes a small JSON message (~200 bytes) to NATS and returns immediately.
     * The whisper-worker will process the file asynchronously and publish a
     * completion event when done.
     *
     * @param string $jobId     Unique job identifier
     * @param string $audioPath Absolute path to the audio file (accessible by worker)
     * @param string $model     Whisper model name (e.g., 'medium.en', 'small.en')
     * @throws \RuntimeException If NATS publish fails
     */
    public function dispatch(string $jobId, string $audioPath, string $model = 'medium.en'): void
    {
        $payload = json_encode([
            'job_id' => $jobId,
            'audio_path' => $audioPath,
            'model' => $model,
        ]);

        $config = new NatsConfiguration(
            host: $this->natsHost,
            port: $this->natsPort,
            timeout: 5.0,
        );

        $client = new NatsClient($config);
        $client->setName('pherb-whisper-dispatcher');
        $client->publish($this->subject, $payload);
        $client->disconnect();
    }
}
