<?php
declare(strict_types=1);

namespace SOI\Certificates\Bulk;

use InvalidArgumentException;
use SOI\Certificates\Authorization\Authorizer;
use SOI\Certificates\Authorization\Permissions;
use SOI\Certificates\Core\Database;
use SOI\Certificates\Issuance\CertificateIssuanceService;
use SOI\Certificates\Issuance\IssuanceCommand;
use SOI\Certificates\Tenancy\TenantContext;
use Throwable;

final class BulkImportEngine
{
    private const MAX_UPLOAD_BYTES = 2_097_152;
    private const MAX_ROWS = 5000;
    private const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenantContext,
        private readonly Authorizer $authorizer,
        private readonly CertificateIssuanceService $issuanceService
    ) {
    }

    public function createBatch(int $templateId, string $csv, array $columnMapping, ?int $actorId): int
    {
        $this->authorizer->require(Permissions::CERTIFICATES_ISSUE);
        $tenantId = $this->tenantContext->getTenantId();
        if ($templateId < 1 || $tenantId < 1 || $csv === '' || strlen($csv) > self::MAX_UPLOAD_BYTES) {
            throw new InvalidArgumentException('Select a template and provide a CSV file under 2 MB.');
        }

        $templateTable = $this->db->tableName('cert_templates');
        if ($this->db->fetchOne(
            "SELECT id FROM {$templateTable} WHERE id = :id AND tenant_id = :tenant_id AND status = 'published'",
            ['id' => $templateId, 'tenant_id' => $tenantId]
        ) === null) {
            throw new InvalidArgumentException('The published template was not found in the active tenant.');
        }

        $rows = $this->parseCsv($csv, $columnMapping);
        $batchTable = $this->db->tableName('cert_bulk_batches');
        $rowTable = $this->db->tableName('cert_bulk_rows');
        $this->db->beginTransaction();
        try {
            $this->db->execute(
                "INSERT INTO {$batchTable} (tenant_id, template_id, created_by, status, total_rows, created_at, updated_at)
                 VALUES (:tenant_id, :template_id, :created_by, 'pending', :total, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                ['tenant_id' => $tenantId, 'template_id' => $templateId, 'created_by' => $actorId, 'total' => count($rows)]
            );
            $batchId = $this->db->lastInsertId();
            foreach ($rows as $index => $payload) {
                $this->db->execute(
                    "INSERT INTO {$rowTable} (batch_id, tenant_id, row_number, payload_json, status, created_at, updated_at)
                     VALUES (:batch_id, :tenant_id, :row_number, :payload, 'pending', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                    [
                        'batch_id' => $batchId,
                        'tenant_id' => $tenantId,
                        'row_number' => $index + 2,
                        'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                    ]
                );
            }
            $this->db->commit();
            return $batchId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function processBatch(int $batchId, int $limit = 25, ?int $actorId = null): array
    {
        $this->authorizer->require(Permissions::CERTIFICATES_ISSUE);
        $tenantId = $this->tenantContext->getTenantId();
        $limit = max(1, min($limit, 100));
        $batchTable = $this->db->tableName('cert_bulk_batches');
        $rowTable = $this->db->tableName('cert_bulk_rows');
        $batch = $this->db->fetchOne(
            "SELECT id, template_id, status FROM {$batchTable} WHERE id = :id AND tenant_id = :tenant_id",
            ['id' => $batchId, 'tenant_id' => $tenantId]
        );
        if ($batch === null) {
            throw new InvalidArgumentException('The import batch was not found in the active tenant.');
        }
        if ($batch['status'] === 'cancelled') {
            throw new InvalidArgumentException('A cancelled import batch cannot be resumed.');
        }

        $staleProcessing = $this->db->getDriver() === 'sqlite'
            ? "processing_at <= datetime('now', '-10 minutes')"
            : "processing_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE)";
        $this->db->execute(
            "UPDATE {$rowTable} SET status = 'failed', error_message = 'Interrupted processing; safe to retry',
             processing_at = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE batch_id = :batch_id AND tenant_id = :tenant_id AND status = 'processing'
               AND {$staleProcessing}",
            ['batch_id' => $batchId, 'tenant_id' => $tenantId]
        );

        $pending = $this->db->fetchAll(
            "SELECT id, row_number, payload_json, attempts FROM {$rowTable}
             WHERE batch_id = :batch_id AND tenant_id = :tenant_id
               AND status IN ('pending', 'failed') AND attempts < :max_attempts
             ORDER BY row_number ASC LIMIT {$limit}",
            ['batch_id' => $batchId, 'tenant_id' => $tenantId, 'max_attempts' => self::MAX_ATTEMPTS]
        );
        $processed = 0;
        foreach ($pending as $row) {
            $this->db->beginTransaction();
            try {
                $claimed = $this->db->execute(
                    "UPDATE {$rowTable}
                     SET status = 'processing', attempts = attempts + 1,
                         processing_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND tenant_id = :tenant_id AND status IN ('pending', 'failed')",
                    ['id' => (int)$row['id'], 'tenant_id' => $tenantId]
                );
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            if ($claimed !== 1) {
                continue;
            }

            try {
                $payload = json_decode((string)$row['payload_json'], true, 32, JSON_THROW_ON_ERROR);
                $recipientName = trim((string)($payload['recipient_name'] ?? ''));
                $recipientEmail = isset($payload['recipient_email']) ? trim((string)$payload['recipient_email']) : null;
                unset($payload['recipient_name'], $payload['recipient_email']);
                if ($recipientName === '' || strlen($recipientName) > 255) {
                    throw new InvalidArgumentException('Recipient name is required and must not exceed 255 characters.');
                }
                if ($recipientEmail !== null && $recipientEmail !== ''
                    && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
                    throw new InvalidArgumentException('Recipient email is invalid.');
                }
                $certificate = $this->issuanceService->issue(
                    new IssuanceCommand(
                        (int)$batch['template_id'],
                        $recipientName,
                        $payload,
                        $recipientEmail !== '' ? $recipientEmail : null,
                        date('Y-m-d'),
                        null,
                        'bulk',
                        'bulk:' . $batchId . ':' . (int)$row['row_number']
                    ),
                    $actorId
                );
                $this->db->execute(
                    "UPDATE {$rowTable} SET status = 'succeeded', certificate_id = :certificate_id,
                     error_message = NULL, processing_at = NULL, updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND tenant_id = :tenant_id AND status = 'processing'",
                    ['certificate_id' => $certificate->id, 'id' => (int)$row['id'], 'tenant_id' => $tenantId]
                );
            } catch (Throwable $e) {
                error_log('SOI bulk row ' . (int)$row['row_number'] . ' failed: ' . $e->getMessage());
                $safeError = $e instanceof InvalidArgumentException
                    ? substr($e->getMessage(), 0, 500)
                    : 'Issuance failed; contact an administrator.';
                $this->db->execute(
                    "UPDATE {$rowTable} SET status = 'failed', error_message = :error,
                     processing_at = NULL, updated_at = CURRENT_TIMESTAMP
                     WHERE id = :id AND tenant_id = :tenant_id AND status = 'processing'",
                    ['error' => $safeError, 'id' => (int)$row['id'], 'tenant_id' => $tenantId]
                );
            }
            $processed++;
        }

        $counts = $this->db->fetchOne(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'succeeded' THEN 1 ELSE 0 END) AS succeeded,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                    SUM(CASE WHEN status IN ('pending', 'processing')
                        OR (status = 'failed' AND attempts < :max_attempts) THEN 1 ELSE 0 END) AS remaining
             FROM {$rowTable} WHERE batch_id = :batch_id AND tenant_id = :tenant_id",
            ['max_attempts' => self::MAX_ATTEMPTS, 'batch_id' => $batchId, 'tenant_id' => $tenantId]
        ) ?? ['total' => 0, 'succeeded' => 0, 'failed' => 0, 'remaining' => 0];
        $status = (int)$counts['remaining'] > 0
            ? 'processing'
            : ((int)$counts['failed'] > 0 ? 'completed_with_errors' : 'completed');
        $this->db->execute(
            "UPDATE {$batchTable} SET status = :status, succeeded_rows = :succeeded,
             failed_rows = :failed, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND tenant_id = :tenant_id",
            [
                'status' => $status,
                'succeeded' => (int)$counts['succeeded'],
                'failed' => (int)$counts['failed'],
                'id' => $batchId,
                'tenant_id' => $tenantId,
            ]
        );
        return [
            'batch_id' => $batchId,
            'status' => $status,
            'processed' => $processed,
            'total' => (int)$counts['total'],
            'succeeded' => (int)$counts['succeeded'],
            'failed' => (int)$counts['failed'],
            'remaining' => (int)$counts['remaining'],
        ];
    }

    public function getBatch(int $batchId): ?array
    {
        $table = $this->db->tableName('cert_bulk_batches');
        $rowTable = $this->db->tableName('cert_bulk_rows');
        $batch = $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE id = :id AND tenant_id = :tenant_id",
            ['id' => $batchId, 'tenant_id' => $this->tenantContext->getTenantId()]
        );
        if ($batch === null) {
            return null;
        }
        $batch['rows'] = $this->db->fetchAll(
            "SELECT row_number, status, attempts, certificate_id, error_message
             FROM {$rowTable} WHERE batch_id = :batch_id AND tenant_id = :tenant_id ORDER BY row_number",
            ['batch_id' => $batchId, 'tenant_id' => $this->tenantContext->getTenantId()]
        );
        return $batch;
    }

    public function listBatches(int $limit = 20): array
    {
        $limit = max(1, min($limit, 100));
        $table = $this->db->tableName('cert_bulk_batches');
        return $this->db->fetchAll(
            "SELECT id, template_id, status, total_rows, succeeded_rows, failed_rows, created_at, updated_at
             FROM {$table} WHERE tenant_id = :tenant_id ORDER BY id DESC LIMIT {$limit}",
            ['tenant_id' => $this->tenantContext->getTenantId()]
        );
    }

    private function parseCsv(string $csv, array $columnMapping): array
    {
        if ($columnMapping === [] || !in_array('recipient_name', $columnMapping, true)) {
            throw new InvalidArgumentException('Column mapping must include recipient_name.');
        }
        $allowedTargets = ['recipient_name', 'recipient_email', 'course_name'];
        foreach ($columnMapping as $source => $target) {
            if (!is_string($source) || $source === '' || !is_string($target)
                || !in_array($target, $allowedTargets, true)) {
                throw new InvalidArgumentException('The CSV column mapping contains an unsupported field.');
            }
        }
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('Unable to open CSV data for processing.');
        }
        fwrite($stream, $csv);
        rewind($stream);
        $headers = fgetcsv($stream);
        if (!is_array($headers)) {
            fclose($stream);
            throw new InvalidArgumentException('The CSV file must contain a header row.');
        }
        $headers = array_map(static fn($value): string => trim((string)$value), $headers);
        if (count($headers) !== count(array_unique($headers)) || array_diff(array_keys($columnMapping), $headers) !== []) {
            fclose($stream);
            throw new InvalidArgumentException('CSV headers must be unique and match the selected columns.');
        }
        $rows = [];
        while (($values = fgetcsv($stream)) !== false) {
            if ($values === [null] || $values === []) {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS || count($values) !== count($headers)) {
                fclose($stream);
                throw new InvalidArgumentException('The CSV has too many rows or an inconsistent number of columns.');
            }
            $sourceValues = array_combine($headers, array_map(static fn($value): string => trim((string)$value), $values));
            $payload = [];
            foreach ($columnMapping as $source => $target) {
                $payload[$target] = $sourceValues[$source];
            }
            $rows[] = $payload;
        }
        fclose($stream);
        if ($rows === []) {
            throw new InvalidArgumentException('The CSV file contains no data rows.');
        }
        return $rows;
    }
}
