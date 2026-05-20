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
$natsPort = getenv('NATS_PORT') ?: ($SETTINGS ? $SETTINGS->getString('nats', 'port', '4222') : '4222');

// Audio storage base path
$audioBasePath = getenv('PHERB_AUDIO_PATH') ?: ($SETTINGS ? $SETTINGS->getString('storage', 'audio_path', '/data/audio') : '/data/audio');

// --- API Router ---

$api = new \EnchiladaREST('/api/v1');
$api->enableCors(['origins' => '*']);

// Health check
$api->get('/health', function($req, $res) {
    $res->success([
        'status' => 'ok',
        'version' => APPLICATION_VERSION,
        'timestamp' => date('c'),
    ]);
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
