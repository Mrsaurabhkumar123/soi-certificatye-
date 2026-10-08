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

    public function enqueueJob(
        int $tenantId,
        string $jobType,
        array $payload,
        ?string $runAfter = null,
        ?int $scheduleId = null,
        ?string $dedupeKey = null
    ): int
    {
        if ($tenantId < 1 || !preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $jobType)) {
            throw new \InvalidArgumentException('Job tenant and type are invalid.');
        }
        if (($scheduleId !== null && $scheduleId < 1)
            || ($dedupeKey !== null && !preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $dedupeKey))) {
            throw new \InvalidArgumentException('Job schedule reference or deduplication key is invalid.');
        }
        $table = $this->db->tableName('cert_jobs');
        $runAfter = $runAfter ?: date('Y-m-d H:i:s');

        if ($dedupeKey !== null) {
            $existing = $this->db->fetchOne(
                "SELECT id FROM {$table} WHERE tenant_id = :tenant_id AND dedupe_key = :dedupe_key",
                ['tenant_id' => $tenantId, 'dedupe_key' => $dedupeKey]
            );
            if ($existing !== null) {
                return (int)$existing['id'];
            }
        }

        $this->db->execute(
            "INSERT INTO {$table}
             (schedule_id, tenant_id, job_type, payload_json, status, run_after, dedupe_key, created_at)
             VALUES (:schedule_id, :tid, :jtype, :payload, 'pending', :rafter, :dedupe_key, CURRENT_TIMESTAMP)",
            [
                'schedule_id' => $scheduleId,
                'tid' => $tenantId,
                'jtype' => $jobType,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'rafter' => $runAfter,
                'dedupe_key' => $dedupeKey,
            ]
        );
        return $this->db->lastInsertId();
    }

    public function acquireDueJobs(
        int $batchSize = 10,
        int $leaseSeconds = 60,
        ?int $tenantId = null,
        ?string $jobType = null
    ): array
    {
        if ($batchSize < 1 || $batchSize > 100 || $leaseSeconds < 5 || $leaseSeconds > 3600) {
            throw new \InvalidArgumentException('Job lease batch size or duration is outside the supported range.');
        }
        if (($tenantId !== null && $tenantId < 1)
            || ($jobType !== null && !preg_match('/^[a-z][a-z0-9_.-]{0,63}$/', $jobType))) {
            throw new \InvalidArgumentException('Job tenant or type filter is invalid.');
        }
        $table = $this->db->tableName('cert_jobs');
        $token = bin2hex(random_bytes(16));
        $now = date('Y-m-d H:i:s');
        $leaseUntil = date('Y-m-d H:i:s', time() + $leaseSeconds);

        // Atomic lease acquisition update
        $tenantFilter = $tenantId === null ? '' : ' AND tenant_id = :tenant_id';
        $typeFilter = $jobType === null ? '' : ' AND job_type = :job_type';
        $params = ['token' => $token, 'luntil' => $leaseUntil, 'expired' => $now, 'due' => $now, 'batch' => $batchSize];
        if ($tenantId !== null) {
            $params['tenant_id'] = $tenantId;
        }
        if ($jobType !== null) {
            $params['job_type'] = $jobType;
        }
        if ($this->db->getDriver() === 'sqlite') {
            $updated = $this->db->execute(
                "UPDATE {$table}
                 SET status = 'running', lease_token = :token, lease_until = :luntil, attempts = attempts + 1
                 WHERE id IN (
                     SELECT id FROM {$table}
                     WHERE ((status = 'pending') OR (status = 'running' AND lease_until <= :expired))
                       AND run_after <= :due{$tenantFilter}{$typeFilter}
                     ORDER BY run_after ASC, id ASC
                     LIMIT :batch
                 )",
                $params
            );
        } else {
            $updated = $this->db->execute(
                "UPDATE {$table} AS job
                 INNER JOIN (
                     SELECT id FROM (
                         SELECT id FROM {$table}
                         WHERE ((status = 'pending') OR (status = 'running' AND lease_until <= :expired))
                           AND run_after <= :due{$tenantFilter}{$typeFilter}
                         ORDER BY run_after ASC, id ASC
                         LIMIT :batch
                     ) AS eligible_jobs
                 ) AS due_jobs ON due_jobs.id = job.id
                 SET job.status = 'running', job.lease_token = :token,
                     job.lease_until = :luntil, job.attempts = job.attempts + 1
                 WHERE ((job.status = 'pending') OR (job.status = 'running' AND job.lease_until <= :expired_again))
                   AND job.run_after <= :due_again",
                $params + ['expired_again' => $now, 'due_again' => $now]
            );
        }

        if ($updated === 0) {
            return [];
        }

        return $this->db->fetchAll(
            "SELECT * FROM {$table} WHERE lease_token = :token",
            ['token' => $token]
        );
    }

    public function completeJob(int $jobId, string $leaseToken): bool
    {
        $table = $this->db->tableName('cert_jobs');
        return $this->db->execute(
            "UPDATE {$table} SET status = 'succeeded', lease_token = NULL, lease_until = NULL
             WHERE id = :id AND status = 'running' AND lease_token = :token",
            ['id' => $jobId, 'token' => $leaseToken]
        ) === 1;
    }

    public function failJob(int $jobId, string $leaseToken, string $error, int $maxAttempts = 5): bool
    {
        if ($maxAttempts < 1 || $maxAttempts > 20) {
            throw new \InvalidArgumentException('Maximum job attempts are outside the supported range.');
        }
        $table = $this->db->tableName('cert_jobs');
        $safeError = substr(preg_replace('/[\r\n\t]+/', ' ', $error) ?? 'Job failed.', 0, 1000);
        $maxAttemptsSql = ':max_attempts_status';
        $retryAttemptsSql = ':max_attempts_retry';
        $retryExpression = $this->db->getDriver() === 'sqlite'
            ? "datetime('now', '+' || MIN(attempts * attempts, 300) || ' seconds')"
            : 'DATE_ADD(CURRENT_TIMESTAMP, INTERVAL LEAST(attempts * attempts, 300) SECOND)';
        return $this->db->execute(
            "UPDATE {$table}
             SET status = CASE WHEN attempts >= {$maxAttemptsSql} THEN 'failed' ELSE 'pending' END,
                 last_error = :error,
                 lease_token = NULL,
                 lease_until = NULL,
                 run_after = CASE WHEN attempts >= {$retryAttemptsSql} THEN run_after ELSE {$retryExpression} END
             WHERE id = :id AND status = 'running' AND lease_token = :token",
            [
                'max_attempts_status' => $maxAttempts,
                'max_attempts_retry' => $maxAttempts,
                'error' => $safeError,
                'id' => $jobId,
                'token' => $leaseToken,
            ]
        ) === 1;
    }
}
