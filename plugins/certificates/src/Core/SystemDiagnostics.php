<?php
declare(strict_types=1);

namespace SOI\Certificates\Core;

final class SystemDiagnostics
{
    public function __construct(
        private readonly Database $db,
        private readonly MigrationRunner $migrations,
        private readonly HealthService $health,
        private readonly string $storageDirectory,
        private readonly int $minimumFreeBytes = 104857600
    ) {
    }

    public function runChecks(): array
    {
        $healthReport = $this->health->runChecks();
        $checks = $healthReport['checks'];
        $freeBytes = disk_free_space($this->storageDirectory);
        $checks['storage_capacity'] = [
            'status' => $freeBytes !== false && $freeBytes >= $this->minimumFreeBytes ? 'healthy' : 'warning',
            'detail' => $freeBytes === false
                ? 'Free-space information is unavailable.'
                : ($freeBytes >= $this->minimumFreeBytes ? 'Capacity threshold met.' : 'Available storage is below the configured threshold.'),
        ];
        $pending = $this->migrations->getPendingMigrations();
        $checks['schema'] = [
            'status' => $pending === [] ? 'healthy' : 'warning',
            'detail' => count($pending) . ' pending migration(s).',
        ];
        $webhooks = $this->db->tableName('cert_webhook_deliveries');
        try {
            $backlog = (int)$this->db->fetchValue(
                "SELECT COUNT(*) FROM {$webhooks} WHERE status IN ('pending', 'retry')"
            );
            $checks['webhook_backlog'] = [
                'status' => $backlog > 1000 ? 'warning' : 'healthy',
                'detail' => $backlog . ' pending delivery(ies).',
            ];
        } catch (\Throwable $e) {
            error_log('SOI webhook backlog diagnostic failed: ' . $e->getMessage());
            $checks['webhook_backlog'] = ['status' => 'warning', 'detail' => 'Webhook backlog status is unavailable.'];
        }

        return [
            'status' => self::isHealthy($checks) ? 'healthy' : 'unhealthy',
            'version' => $healthReport['version'],
            'checks' => $checks,
        ];
    }

    private static function isHealthy(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['status'] ?? null) === 'critical') {
                return false;
            }
        }
        return true;
    }
}
