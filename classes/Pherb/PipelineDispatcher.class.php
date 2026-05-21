<?php
namespace Pherb;

use Basis\Nats\Client as NatsClient;
use Basis\Nats\Configuration as NatsConfiguration;

/**
 * PipelineDispatcher — Fire-and-forget dispatch of pipeline stages via NATS.
 *
 * Publishes small control messages to pherb-worker. Does NOT wait for a reply.
 * The worker processes each stage independently and publishes completion events
 * to pherb.pipeline.completed, which the consumer listens for.
 *
 * Subjects:
 *   pherb.worker.whisper   — transcription stage
 *   pherb.worker.pyannote  — speaker diarization stage
 *   pherb.worker.align     — forced alignment stage
 */
class PipelineDispatcher
{
    private string $natsHost;
    private int $natsPort;

    public function __construct(string $natsHost = '127.0.0.1', int $natsPort = 4222)
    {
        $this->natsHost = $natsHost;
        $this->natsPort = $natsPort;
    }

    /**
     * Dispatch whisper transcription stage.
     */
    public function dispatchWhisper(string $jobId, string $audioPath, string $model = 'medium.en'): void
    {
        $this->publish('pherb.worker.whisper', [
            'job_id' => $jobId,
            'audio_path' => $audioPath,
            'model' => $model,
        ]);
    }

    /**
     * Dispatch pyannote diarization stage.
     */
    public function dispatchPyannote(string $jobId, string $audioPath): void
    {
        $this->publish('pherb.worker.pyannote', [
            'job_id' => $jobId,
            'audio_path' => $audioPath,
        ]);
    }

    /**
     * Dispatch wav2vec2 forced alignment stage.
     */
    public function dispatchAlignment(string $jobId, string $audioPath, string $transcriptPath): void
    {
        $this->publish('pherb.worker.align', [
            'job_id' => $jobId,
            'audio_path' => $audioPath,
            'transcript_path' => $transcriptPath,
        ]);
    }

    private function publish(string $subject, array $payload): void
    {
        $config = new NatsConfiguration(
            host: $this->natsHost,
            port: $this->natsPort,
            timeout: 5.0,
        );

        $client = new NatsClient($config);
        $client->setName('pherb-pipeline-dispatcher');
        $client->publish($subject, json_encode($payload));
        $client->disconnect();
    }
}
