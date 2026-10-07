<?php
declare(strict_types=1);

namespace SOI\Certificates\Scheduling;

use SOI\Certificates\Core\Database;

/**
 * Database-backed scheduler runner with atomic lease acquisition.
 * Prevents race conditions when web runners overlap without requiring Redis.
 */
class SchedulerService
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function enqueueJob(int $tenantId, string $jobType, array $payload, ?string $runAfter = null): int
    {
        $table = $this->db->tableName('cert_jobs');
        $runAfter = $runAfter ?: date('Y-m-d H:i:s');

        $this->db->execute(
            "INSERT INTO {$table} (tenant_id, job_type, payload_json, status, run_after, created_at)
             VALUES (:tid, :jtype, :payload, 'pending', :rafter, datetime('now'))",
            [
                'tid' => $tenantId,
                'jtype' => $jobType,
                'payload' => json_encode($payload),
                'rafter' => $runAfter,
            ]
        );
        return $this->db->lastInsertId();
    }

    public function acquireDueJobs(int $batchSize = 10, int $leaseSeconds = 60): array
    {
        $table = $this->db->tableName('cert_jobs');
        $token = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $leaseUntil = date('Y-m-d H:i:s', time() + $leaseSeconds);

        // Atomic lease acquisition update
        $updated = $this->db->execute(
            "UPDATE {$table}
             SET status = 'running', lease_token = :token, lease_until = :luntil, attempts = attempts + 1
             WHERE id IN (
                 SELECT id FROM {$table}
                 WHERE status = 'pending' AND run_after <= :now
                 LIMIT :batch
             )",
            ['token' => $token, 'luntil' => $leaseUntil, 'now' => $now, 'batch' => $batchSize]
        );

        if ($updated === 0) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT * FROM {$table} WHERE lease_token = :token",
            ['token' => $token]
        );
    }

    public function completeJob(int $jobId, string $leaseToken): void
    {
        $table = $this->db->tableName('cert_jobs');
        $this->db->execute(
            "UPDATE {$table} SET status = 'succeeded', lease_token = NULL WHERE id = :id AND lease_token = :token",
            ['id' => $jobId, 'token' => $leaseToken]
        );
    }
}
