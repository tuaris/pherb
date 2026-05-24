<?php
namespace Pherb;

/**
 * JobStore — Manages job state in MariaDB.
 */
class JobStore
{
    private \PDO $db;

    public function __construct(\PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Generate a unique job ID.
     */
    public static function generateId(): string
    {
        return 'pherb_' . bin2hex(random_bytes(12));
    }

    /**
     * Create a new job record.
     */
    public function create(string $id, string $audioPath, array $options, ?string $callbackUrl = null): array
    {
        $stmt = $this->db->prepare(
            "INSERT INTO jobs (id, audio_path, status, options, callback_url, created_at)
             VALUES (:id, :audio_path, 'queued', :options, :callback_url, NOW())"
        );
        $stmt->execute([
            'id' => $id,
            'audio_path' => $audioPath,
            'options' => json_encode($options),
            'callback_url' => $callbackUrl,
        ]);

        return $this->get($id);
    }

    /**
     * Get a job by ID.
     */
    public function get(string $id): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM jobs WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$row) return null;

        if (isset($row['options']) && is_string($row['options'])) {
            $row['options'] = json_decode($row['options'], true);
        }
        return $row;
    }

    /**
     * Mark a job as processing.
     */
    public function markProcessing(string $id): int
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'processing', started_at = NOW() WHERE id = :id AND status = 'queued'"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount();
    }

    /**
     * Update the current pipeline stage for a job.
     */
    public function updateStage(string $id, string $stage): void
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET current_stage = :stage WHERE id = :id"
        );
        $stmt->execute(['id' => $id, 'stage' => $stage]);
    }

    /**
     * Reset a failed job back to processing so it can be resumed.
     *
     * @return int Number of rows affected (0 if job was not in failed state)
     */
    public function markRetrying(string $id): int
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'processing', error_message = NULL, completed_at = NULL
             WHERE id = :id AND status = 'failed'"
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount();
    }

    /**
     * Mark a job as completed.
     */
    public function markCompleted(string $id, string $resultPath): void
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'completed', result_path = :result_path, completed_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $id, 'result_path' => $resultPath]);
    }

    /**
     * Mark a job as failed.
     */
    public function markFailed(string $id, string $errorMessage): int
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'failed', error_message = :error, completed_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $id, 'error' => $errorMessage]);
        return $stmt->rowCount();
    }

    /**
     * Recover stale jobs stuck in 'processing' state (e.g., consumer crashed).
     * Marks them as failed if they've been processing longer than the timeout.
     *
     * @param int $timeoutMinutes Minutes before a processing job is considered stale
     * @return int Number of jobs recovered
     */
    public function recoverStaleJobs(int $timeoutMinutes = 30): int
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'failed', error_message = 'Stale: exceeded processing timeout', completed_at = NOW()
             WHERE status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL :timeout MINUTE)"
        );
        $stmt->execute(['timeout' => $timeoutMinutes]);
        return $stmt->rowCount();
    }

    /**
     * Clean up old completed/failed jobs beyond the retention period.
     * Returns the list of deleted job IDs and removes output files from disk.
     */
    public function cleanup(int $ttlDays = 7, ?string $outputPath = null): array
    {
        // Find expired jobs
        $stmt = $this->db->prepare(
            "SELECT id, result_path FROM jobs
             WHERE status IN ('completed', 'failed')
             AND completed_at < DATE_SUB(NOW(), INTERVAL :ttl DAY)"
        );
        $stmt->execute(['ttl' => $ttlDays]);
        $expired = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        if (empty($expired)) {
            return [];
        }

        $deletedIds = [];
        foreach ($expired as $row) {
            // Remove output file if it exists
            if (!empty($row['result_path'])) {
                $filePath = $row['result_path'];
                // If result_path is relative, prepend outputPath
                if ($outputPath && !str_starts_with($filePath, '/')) {
                    $filePath = rtrim($outputPath, '/') . '/' . $filePath;
                }
                if (is_file($filePath)) {
                    @unlink($filePath);
                }
            }
            $deletedIds[] = $row['id'];
        }

        // Delete the DB records
        $placeholders = implode(',', array_fill(0, count($deletedIds), '?'));
        $stmt = $this->db->prepare("DELETE FROM jobs WHERE id IN ({$placeholders})");
        $stmt->execute($deletedIds);

        return $deletedIds;
    }

    /**
     * Get job counts grouped by status for metrics.
     */
    public function getStatusCounts(): array
    {
        $stmt = $this->db->query(
            "SELECT status, COUNT(*) as count FROM jobs GROUP BY status"
        );
        $counts = ['queued' => 0, 'processing' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $counts[$row['status']] = (int)$row['count'];
        }
        return $counts;
    }

    /**
     * Get average processing duration for completed jobs (last 24h).
     */
    public function getAvgDuration(): float
    {
        $stmt = $this->db->query(
            "SELECT AVG(TIMESTAMPDIFF(SECOND, started_at, completed_at)) as avg_sec
             FROM jobs
             WHERE status = 'completed'
             AND completed_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
             AND started_at IS NOT NULL"
        );
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return (float)($row['avg_sec'] ?? 0);
    }

    /**
     * List recent jobs.
     */
    public function listRecent(int $limit = 50, ?string $status = null): array
    {
        $sql = "SELECT id, audio_path, status, created_at, started_at, completed_at FROM jobs";
        $params = [];

        if ($status !== null) {
            $sql .= " WHERE status = :status";
            $params['status'] = $status;
        }

        $sql .= " ORDER BY created_at DESC LIMIT :limit";

        $stmt = $this->db->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }
}
