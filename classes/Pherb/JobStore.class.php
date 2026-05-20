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
    public function markProcessing(string $id): void
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'processing', started_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $id]);
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
    public function markFailed(string $id, string $errorMessage): void
    {
        $stmt = $this->db->prepare(
            "UPDATE jobs SET status = 'failed', error_message = :error, completed_at = NOW() WHERE id = :id"
        );
        $stmt->execute(['id' => $id, 'error' => $errorMessage]);
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
