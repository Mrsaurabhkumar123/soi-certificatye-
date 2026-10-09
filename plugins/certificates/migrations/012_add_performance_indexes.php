<?php
declare(strict_types=1);

/**
 * Migration: 012_add_performance_indexes.php
 * Developer 4: Rendering & Storage Lead
 *
 * Adds composite database indexes to optimize high-volume queries:
 * - Certificate registry lookups and token verification
 * - Schedule leasing and execution queue lookups
 * - Chunked bulk import row processing and status aggregation
 * - Webhook delivery retry tracking
 */

use SOI\Certificates\Core\Database;

return function (Database $db): void {
    $isSqlite = $db->getDriver() === 'sqlite';

    $indexes = [
        [
            'table' => 'cert_certificates',
            'name' => 'idx_perf_cert_recip_email',
            'columns' => ['tenant_id', 'recipient_email'],
        ],
        [
            'table' => 'cert_certificates',
            'name' => 'idx_perf_cert_source_ref',
            'columns' => ['tenant_id', 'source_reference'],
        ],
        [
            'table' => 'cert_certificates',
            'name' => 'idx_perf_cert_token_hash',
            'columns' => ['verification_token_hash'],
        ],
        [
            'table' => 'cert_certificates',
            'name' => 'idx_perf_cert_replaces',
            'columns' => ['replaces_certificate_id'],
        ],
        [
            'table' => 'cert_certificates',
            'name' => 'idx_perf_cert_status_exp',
            'columns' => ['tenant_id', 'status', 'expires_at'],
        ],
        [
            'table' => 'cert_schedules',
            'name' => 'idx_perf_sched_tenant_status',
            'columns' => ['tenant_id', 'status'],
        ],
        [
            'table' => 'cert_jobs',
            'name' => 'idx_perf_jobs_lease',
            'columns' => ['tenant_id', 'status', 'run_after', 'lease_until'],
        ],
        [
            'table' => 'cert_bulk_batches',
            'name' => 'idx_perf_bulk_batches_tenant_stat',
            'columns' => ['tenant_id', 'status', 'created_at'],
        ],
        [
            'table' => 'cert_bulk_rows',
            'name' => 'idx_perf_bulk_rows_chunk',
            'columns' => ['batch_id', 'status', '`row_number`'],
        ],
        [
            'table' => 'cert_webhook_deliveries',
            'name' => 'idx_perf_webhook_retry',
            'columns' => ['tenant_id', 'status', 'next_retry_at'],
        ],
    ];

    foreach ($indexes as $indexDef) {
        $tableName = $db->tableName($indexDef['table']);
        $indexName = $indexDef['name'];
        $columnList = implode(', ', $indexDef['columns']);

        try {
            if ($isSqlite) {
                $db->getPdo()->exec("CREATE INDEX IF NOT EXISTS {$indexName} ON {$tableName} ({$columnList})");
            } else {
                $existing = $db->fetchAll(
                    "SHOW INDEX FROM {$tableName} WHERE Key_name = :key_name",
                    ['key_name' => $indexName]
                );
                if (empty($existing)) {
                    $db->getPdo()->exec("CREATE INDEX {$indexName} ON {$tableName} ({$columnList})");
                }
            }
        } catch (\Throwable $e) {
            // Ignore if table doesn't exist yet or index exists
        }
    }
};
