<?php
declare(strict_types=1);

namespace SOI\Certificates\Bulk;

use InvalidArgumentException;
use SOI\Certificates\Core\Database;
use Throwable;

/**
 * Manages bulk import batch progress, row-level error handling, batch status,
 * and resumable import state transitions.
 */
class BatchProgressTracker
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Reclaim rows that were marked 'processing' but abandoned due to timeout or crash.
     */
    public function reclaimStaleProcessing(int $batchId, int $tenantId, int $staleMinutes = 10): int
    {
        $rowTable = $this->db->tableName('cert_bulk_rows');
        $staleSql = $this->db->getDriver() === 'sqlite'
            ? "processing_at <= datetime('now', '-{$staleMinutes} minutes')"
            : "processing_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL {$staleMinutes} MINUTE)";

        return $this->db->execute(
            "UPDATE {$rowTable} 
             SET status = 'failed', error_message = 'Interrupted processing; safe to retry',
                 processing_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE batch_id = :batch_id AND tenant_id = :tenant_id AND status = 'processing'
               AND {$staleSql}",
            ['batch_id' => $batchId, 'tenant_id' => $tenantId]
        );
    }

    /**
     * Mark a row as processing and increment attempt counter.
     */
    public function claimRow(int $rowId, int $tenantId): bool
    {
        $rowTable = $this->db->tableName('cert_bulk_rows');
        $claimed = $this->db->execute(
            "UPDATE {$rowTable}
             SET status = 'processing', attempts = attempts + 1,
                 processing_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND tenant_id = :tenant_id AND status IN ('pending', 'failed')",
            ['id' => $rowId, 'tenant_id' => $tenantId]
        );
        return $claimed === 1;
    }

    /**
     * Record successful certificate issuance for a row.
     */
    public function recordRowSuccess(int $rowId, int $tenantId, int $certificateId): void
    {
        $rowTable = $this->db->tableName('cert_bulk_rows');
        $this->db->execute(
            "UPDATE {$rowTable} 
             SET status = 'succeeded', certificate_id = :certificate_id,
                 error_message = NULL, processing_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND tenant_id = :tenant_id AND status = 'processing'",
            ['certificate_id' => $certificateId, 'id' => $rowId, 'tenant_id' => $tenantId]
        );
    }

    /**
     * Record row error with sanitized error message without leaking internal database traces.
     */
    public function recordRowFailure(int $rowId, int $tenantId, Throwable|string $error): void
    {
        $rowTable = $this->db->tableName('cert_bulk_rows');
        $safeError = $error instanceof InvalidArgumentException
            ? substr($error->getMessage(), 0, 500)
            : ($error instanceof Throwable
                ? 'Issuance failed; contact an administrator.'
                : substr($error, 0, 500));

        $this->db->execute(
            "UPDATE {$rowTable} 
             SET status = 'failed', error_message = :error,
                 processing_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND tenant_id = :tenant_id AND status = 'processing'",
            ['error' => $safeError, 'id' => $rowId, 'tenant_id' => $tenantId]
        );
    }

    /**
     * Recompute batch status and aggregate statistics based on all row states.
     */
    public function recomputeBatchStatus(int $batchId, int $tenantId, int $maxAttempts = self::MAX_ATTEMPTS): array
    {
        $batchTable = $this->db->tableName('cert_bulk_batches');
        $rowTable = $this->db->tableName('cert_bulk_rows');

        $counts = $this->db->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succeeded,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN status IN ('pending', 'processing')
                        OR (status = 'failed' AND attempts < :max_attempts) THEN 1 ELSE 0 END) AS remaining
             FROM {$rowTable} WHERE batch_id = :batch_id AND tenant_id = :tenant_id",
            ['max_attempts' => $maxAttempts, 'batch_id' => $batchId, 'tenant_id' => $tenantId]
        ) ?? ['total' => 0, 'succeeded' => 0, 'failed' => 0, 'remaining' => 0];

        $total = (int)$counts['total'];
        $succeeded = (int)$counts['succeeded'];
        $failed = (int)$counts['failed'];
        $remaining = (int)$counts['remaining'];

        $status = $remaining > 0
            ? 'processing'
            : ($failed > 0 ? 'completed_with_errors' : 'completed');

        $this->db->execute(
            "UPDATE {$batchTable} 
             SET status = :status, succeeded_rows = :succeeded,
                 failed_rows = :failed, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND tenant_id = :tenant_id",
            [
                'status' => $status,
                'succeeded' => $succeeded,
                'failed' => $failed,
                'id' => $batchId,
                'tenant_id' => $tenantId,
            ]
        );

        $percentComplete = $total > 0
            ? min(100, (int)round((($succeeded + $failed) / $total) * 100))
            : 0;

        return [
            'batch_id' => $batchId,
            'status' => $status,
            'total' => $total,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'remaining' => $remaining,
            'percent' => $percentComplete,
        ];
    }

    /**
     * Retrieve current batch progress summary.
     */
    public function getProgress(int $batchId, int $tenantId): ?array
    {
        $batchTable = $this->db->tableName('cert_bulk_batches');
        $batch = $this->db->fetchOne(
            "SELECT id, status, total_rows, succeeded_rows, failed_rows, created_at, updated_at
             FROM {$batchTable} WHERE id = :id AND tenant_id = :tenant_id",
            ['id' => $batchId, 'tenant_id' => $tenantId]
        );

        if ($batch === null) {
            return null;
        }

        $total = (int)$batch['total_rows'];
        $succeeded = (int)$batch['succeeded_rows'];
        $failed = (int)$batch['failed_rows'];
        $done = $succeeded + $failed;
        $remaining = max(0, $total - $done);
        $percent = $total > 0 ? min(100, (int)round(($done / $total) * 100)) : 0;

        return [
            'batch_id' => (int)$batch['id'],
            'status' => $batch['status'],
            'total' => $total,
            'succeeded' => $succeeded,
            'failed' => $failed,
            'remaining' => $remaining,
            'percent' => $percent,
            'created_at' => $batch['created_at'],
            'updated_at' => $batch['updated_at'],
        ];
    }
}
