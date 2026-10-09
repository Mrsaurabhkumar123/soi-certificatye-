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

    public static function generateRowIdempotencyKey(int $batchId, int $rowNumber): string
    {
        return 'bulk:' . $batchId . ':' . $rowNumber;
    }

    private readonly BatchProgressTracker $tracker;

    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenantContext,
        private readonly Authorizer $authorizer,
        private readonly CertificateIssuanceService $issuanceService,
        ?BatchProgressTracker $tracker = null
    ) {
        $this->tracker = $tracker ?? new BatchProgressTracker($db);
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
                    "INSERT INTO {$rowTable} (batch_id, tenant_id, `row_number`, payload_json, status, created_at, updated_at)
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

        $this->tracker->reclaimStaleProcessing($batchId, $tenantId);

        $pending = $this->db->fetchAll(
            "SELECT id, `row_number`, payload_json, attempts FROM {$rowTable}
             WHERE batch_id = :batch_id AND tenant_id = :tenant_id
               AND status IN ('pending', 'failed') AND attempts < :max_attempts
             ORDER BY `row_number` ASC LIMIT {$limit}",
            ['batch_id' => $batchId, 'tenant_id' => $tenantId, 'max_attempts' => self::MAX_ATTEMPTS]
        );
        $processed = 0;
        foreach ($pending as $row) {
            $this->db->beginTransaction();
            try {
                $claimed = $this->tracker->claimRow((int)$row['id'], $tenantId);
                $this->db->commit();
            } catch (Throwable $e) {
                $this->db->rollBack();
                throw $e;
            }
            if (!$claimed) {
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
                        self::generateRowIdempotencyKey($batchId, (int)$row['row_number'])
                    ),
                    $actorId
                );
                $this->tracker->recordRowSuccess((int)$row['id'], $tenantId, $certificate->id);
            } catch (Throwable $e) {
                error_log('SOI bulk row ' . (int)$row['row_number'] . ' failed: ' . $e->getMessage());
                $this->tracker->recordRowFailure((int)$row['id'], $tenantId, $e);
            }
            $processed++;
        }

        $progress = $this->tracker->recomputeBatchStatus($batchId, $tenantId, self::MAX_ATTEMPTS);
        return [
            'batch_id' => $batchId,
            'status' => $progress['status'],
            'processed' => $processed,
            'total' => $progress['total'],
            'succeeded' => $progress['succeeded'],
            'failed' => $progress['failed'],
            'remaining' => $progress['remaining'],
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
            "SELECT `row_number`, status, attempts, certificate_id, error_message
             FROM {$rowTable} WHERE batch_id = :batch_id AND tenant_id = :tenant_id ORDER BY `row_number`",
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
