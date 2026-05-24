<?php
/**
 * Pherb REST API Entry Point
 * Enchilada Framework 3.0
 *
 * Endpoints:
 *   POST /api/v1/jobs        — Submit a transcription job
 *   GET  /api/v1/jobs/{id}   — Get job status
 *   GET  /api/v1/jobs        — List recent jobs
 *   GET  /api/v1/health      — Health check
 */

require_once __DIR__ . '/../system/bootstrap.inc.php';

use Enchilada\Config\IniConfig;
use Pherb\JobStore;

// --- Composition Root ---

/** @var IniConfig|null $SETTINGS */
global $SETTINGS;

$pdo = pherb_create_mariadb($SETTINGS);
$jobStore = new JobStore($pdo);

// NATS connection info for publishing
$natsHost = getenv('NATS_HOST') ?: ($SETTINGS ? $SETTINGS->getString('nats', 'host', '127.0.0.1') : '127.0.0.1');
$natsPort = getenv('NATS_PORT') ?: ($SETTINGS ? $SETTINGS->getInt('nats', 'port', 4222) : 4222);

// Audio storage base path
$audioBasePath = getenv('PHERB_AUDIO_PATH') ?: ($SETTINGS ? $SETTINGS->getString('storage', 'audio_path', '/data/audio') : '/data/audio');

// --- API Router ---

$api = new \EnchiladaREST\EnchiladaREST('/api/v1');
$api->enableCors(['origins' => '*']);

// Health check
$api->get('/health', function($req, $res) {
    $res->success([
        'status' => 'ok',
        'version' => APPLICATION_VERSION,
        'timestamp' => date('c'),
    ]);
});

// Prometheus metrics
$api->get('/metrics', function($req, $res) use ($jobStore) {
    $counts = $jobStore->getStatusCounts();
    $avgDuration = $jobStore->getAvgDuration();
    $total = array_sum($counts);

    $lines = [];

    // Job gauges by status
    $lines[] = '# HELP pherb_jobs_total Current number of jobs by status';
    $lines[] = '# TYPE pherb_jobs_total gauge';
    foreach ($counts as $status => $count) {
        $lines[] = "pherb_jobs_total{status=\"{$status}\"} {$count}";
    }

    $lines[] = '# HELP pherb_jobs_count Total jobs in database';
    $lines[] = '# TYPE pherb_jobs_count gauge';
    $lines[] = "pherb_jobs_count {$total}";

    $lines[] = '# HELP pherb_job_duration_avg_seconds Average processing duration (last 24h)';
    $lines[] = '# TYPE pherb_job_duration_avg_seconds gauge';
    $lines[] = "pherb_job_duration_avg_seconds {$avgDuration}";

    // Consumer daemon metrics (from health file)
    $healthFile = getenv('HEALTH_FILE') ?: '/var/run/pherb-consumer.health';
    $consumerUp = 0;
    if (is_file($healthFile)) {
        $health = json_decode(@file_get_contents($healthFile), true);
        if ($health && ($health['status'] ?? '') === 'running') {
            // Consider consumer up if health file updated within last 120 seconds
            $updatedAt = strtotime($health['updated_at'] ?? '');
            if ($updatedAt && (time() - $updatedAt) < 120) {
                $consumerUp = 1;
            }
        }

        if ($health) {
            $processed = $health['metrics']['processed'] ?? 0;
            $errors = $health['metrics']['errors'] ?? 0;
            $memMb = $health['memory_mb'] ?? 0;
            $memPeakMb = $health['memory_peak_mb'] ?? 0;

            $lines[] = '# HELP pherb_consumer_processed_total Total jobs processed by consumer';
            $lines[] = '# TYPE pherb_consumer_processed_total counter';
            $lines[] = "pherb_consumer_processed_total {$processed}";

            $lines[] = '# HELP pherb_consumer_errors_total Total consumer errors';
            $lines[] = '# TYPE pherb_consumer_errors_total counter';
            $lines[] = "pherb_consumer_errors_total {$errors}";

            $lines[] = '# HELP pherb_consumer_memory_megabytes Consumer memory usage';
            $lines[] = '# TYPE pherb_consumer_memory_megabytes gauge';
            $lines[] = "pherb_consumer_memory_megabytes {$memMb}";

            $lines[] = '# HELP pherb_consumer_memory_peak_megabytes Consumer peak memory';
            $lines[] = '# TYPE pherb_consumer_memory_peak_megabytes gauge';
            $lines[] = "pherb_consumer_memory_peak_megabytes {$memPeakMb}";
        }
    }

    $lines[] = '# HELP pherb_consumer_up Consumer daemon is running';
    $lines[] = '# TYPE pherb_consumer_up gauge';
    $lines[] = "pherb_consumer_up {$consumerUp}";

    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
    echo implode("\n", $lines) . "\n";
    exit;
});

// Submit a new transcription job
$api->post('/jobs', function($req, $res) use ($jobStore, $natsHost, $natsPort, $audioBasePath) {
    $body = $req->getJsonBody() ?? [];

    if (empty($body['audio'])) {
        $res->error('Missing required field: audio', 400);
        return;
    }

    $audioFile = $body['audio'];
    $audioFullPath = $audioBasePath . '/' . ltrim($audioFile, '/');

    if (!file_exists($audioFullPath)) {
        $res->error("Audio file not found: {$audioFile}", 404);
        return;
    }

    $options = $body['options'] ?? [];

    // Accept top-level convenience keys and merge into options
    foreach (['model', 'diarize', 'align', 'format', 'threads'] as $key) {
        if (isset($body[$key]) && !isset($options[$key])) {
            $options[$key] = $body[$key];
        }
    }

    $callbackUrl = $body['callback_url'] ?? null;

    // Create job record
    $jobId = JobStore::generateId();
    $job = $jobStore->create($jobId, $audioFile, $options, $callbackUrl);

    // Publish to NATS JetStream
    $published = false;
    try {
        $natsPayload = json_encode([
            'job_id' => $jobId,
            'audio_path' => $audioFile,
            'options' => $options,
        ]);

        $natsConfig = new \Basis\Nats\Configuration(
            host: $natsHost,
            port: (int)$natsPort,
            timeout: 5.0,
        );
        $natsClient = new \Basis\Nats\Client($natsConfig);
        $natsClient->publish('pherb.jobs.transcribe', $natsPayload);
        $natsClient->disconnect();
        $published = true;
    } catch (\Throwable $e) {
        // NATS publish failed — job is in DB, consumer will pick up on retry
    }

    $res->json([
        'job_id' => $jobId,
        'status' => 'queued',
        'poll_url' => "/api/v1/jobs/{$jobId}",
        'nats_published' => $published,
    ], 202);
});

// Get job status
$api->get('/jobs/{id}', function($req, $res) use ($jobStore) {
    $id = $req->getRouteParam('id');
    $job = $jobStore->get($id);

    if (!$job) {
        $res->error('Job not found', 404);
        return;
    }

    $response = [
        'job_id' => $job['id'],
        'status' => $job['status'],
        'current_stage' => $job['current_stage'] ?? null,
        'audio_path' => $job['audio_path'],
        'created_at' => $job['created_at'],
        'started_at' => $job['started_at'],
        'completed_at' => $job['completed_at'],
    ];

    if ($job['status'] === 'completed' && !empty($job['result_path'])) {
        $response['result_url'] = '/audio/outputs/' . basename($job['result_path']);
    }

    if ($job['status'] === 'failed' && !empty($job['error_message'])) {
        $response['error'] = $job['error_message'];
    }

    if (!empty($job['options'])) {
        $opts = is_array($job['options']) ? $job['options'] : json_decode($job['options'], true);
        $response['format'] = $opts['format'] ?? 'json';
    }

    $res->json($response);
});

// Retry a failed job (resumes from last completed stage)
$api->post('/jobs/{id}/retry', function($req, $res) use ($jobStore, $natsHost, $natsPort) {
    $id = $req->getRouteParam('id');
    $job = $jobStore->get($id);

    if (!$job) {
        $res->error('Job not found', 404);
        return;
    }

    if ($job['status'] !== 'failed') {
        $res->error('Only failed jobs can be retried', 409);
        return;
    }

    $affected = $jobStore->markRetrying($id);
    if ($affected === 0) {
        $res->error('Job could not be retried', 500);
        return;
    }

    // Re-publish to NATS — the consumer's dispatchJob will detect
    // existing intermediate outputs and resume from the failed stage
    $options = is_array($job['options']) ? $job['options'] : (json_decode($job['options'] ?? '{}', true) ?: []);
    $published = false;
    try {
        $natsPayload = json_encode([
            'job_id' => $id,
            'audio_path' => $job['audio_path'],
            'options' => $options,
        ]);

        $natsConfig = new \Basis\Nats\Configuration(
            host: $natsHost,
            port: (int)$natsPort,
            timeout: 5.0,
        );
        $natsClient = new \Basis\Nats\Client($natsConfig);
        $natsClient->publish('pherb.jobs.transcribe', $natsPayload);
        $natsClient->disconnect();
        $published = true;
    } catch (\Throwable $e) {
        // NATS publish failed
    }

    $res->json([
        'job_id' => $id,
        'status' => 'queued',
        'message' => 'Job retried, resuming from last completed stage',
        'nats_published' => $published,
    ]);
});

// List recent jobs
$api->get('/jobs', function($req, $res) use ($jobStore) {
    $limit = min(100, max(1, intval($req->getQueryParam('limit', 50))));
    $status = $req->getQueryParam('status');
    if ($status === '') $status = null;

    $jobs = $jobStore->listRecent($limit, $status);
    $res->json(['jobs' => $jobs]);
});

// Run the API
$api->run();
