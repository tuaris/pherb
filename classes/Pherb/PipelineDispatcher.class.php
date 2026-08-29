<?php
namespace Pherb;

use Basis\Nats\Client as NatsClient;
use Basis\Nats\Configuration as NatsConfiguration;

/**
 * PipelineDispatcher — Durable dispatch of pipeline stages via NATS JetStream.
 *
 * Publishes control messages to JetStream subjects. Workers pull from durable
 * consumers and compete for jobs. The worker concatenates its configured binary
 * path with the args array from the payload and spawns the process directly.
 *
 * The consumer (orchestrator) owns all paths and arguments. Every dispatch
 * includes output_path and a complete args array so workers never compute
 * paths or options internally.
 *
 * Subjects (captured by PHERB JetStream stream):
 *   pherb.stage.convert   — audio format conversion stage
 *   pherb.stage.whisper   — transcription stage
 *   pherb.stage.pyannote  — speaker diarization stage
 *   pherb.stage.align     — forced alignment stage
 *   pherb.stage.deliver   — artifact delivery stage (optional)
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
     * Dispatch audio conversion stage (ffmpeg → WAV).
     *
     * @param string $jobId      Job identifier
     * @param string $audioPath  Input audio file path
     * @param string $outputPath Desired output WAV path
     */
    public function dispatchConvert(string $jobId, string $audioPath, string $outputPath): void
    {
        $this->publish('pherb.stage.convert', [
            'job_id' => $jobId,
            'output_path' => $outputPath,
            'args' => ['-y', '-i', $audioPath, '-ar', '16000', '-ac', '1', $outputPath],
        ]);
    }

    /**
     * Dispatch whisper transcription stage.
     *
     * @param string $jobId      Job identifier
     * @param string $audioPath  Input WAV file path
     * @param string $outputPath Desired output JSON path
     * @param string $model      Whisper model name (e.g. "medium.en")
     * @param string $modelsDir  Directory containing ggml-*.bin models
     * @param int    $threads    Number of threads for inference
     */
    public function dispatchWhisper(
        string $jobId,
        string $audioPath,
        string $outputPath,
        string $model = 'medium.en',
        string $modelsDir = '/models/whisper',
        int $threads = 8,
    ): void {
        $modelPath = rtrim($modelsDir, '/') . "/ggml-{$model}.bin";
        $tmpBase = dirname($outputPath) . "/.tmp_whisper_{$jobId}";

        $this->publish('pherb.stage.whisper', [
            'job_id' => $jobId,
            'output_path' => $outputPath,
            'post_rename' => "{$tmpBase}.json",
            'args' => [
                '-m', $modelPath,
                '-f', $audioPath,
                '-t', (string)$threads,
                '--output-json-full',
                '-of', $tmpBase,
            ],
        ]);
    }

    /**
     * Dispatch pyannote diarization stage.
     *
     * @param string $jobId      Job identifier
     * @param string $audioPath  Input WAV file path
     * @param string $outputPath Desired output JSON path
     */
    public function dispatchPyannote(string $jobId, string $audioPath, string $outputPath): void
    {
        $this->publish('pherb.stage.pyannote', [
            'job_id' => $jobId,
            'output_path' => $outputPath,
            'args' => [$audioPath, $outputPath],
        ]);
    }

    /**
     * Dispatch wav2vec2 forced alignment stage.
     *
     * @param string $jobId      Job identifier
     * @param string $audioPath  Input WAV file path
     * @param string $outputPath Desired output JSON path
     */
    public function dispatchAlignment(string $jobId, string $audioPath, string $outputPath): void
    {
        $this->publish('pherb.stage.align', [
            'job_id' => $jobId,
            'output_path' => $outputPath,
            'args' => [$audioPath, $outputPath],
        ]);
    }

    /**
     * Dispatch artifact delivery stage (optional).
     *
     * @param string $jobId        Job identifier
     * @param string $artifactPath Path to the finalized artifact
     * @param array  $delivery     Delivery config (method, url, credentials_ref, etc.)
     */
    public function dispatchDeliver(string $jobId, string $artifactPath, array $delivery): void
    {
        $this->publish('pherb.stage.deliver', [
            'job_id' => $jobId,
            'output_path' => $artifactPath,
            'args' => [$artifactPath, json_encode($delivery)],
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
