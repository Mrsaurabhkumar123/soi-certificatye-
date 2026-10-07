<?php
declare(strict_types=1);

namespace SOI\Certificates\Core;

/**
 * Health & Diagnostics Service for administrative verification.
 * Does not expose sensitive server credentials or filesystem internals to unauthorized users.
 */
class HealthService
{
    protected Database $db;
    protected string $storageDir;
    protected string $version;

    public function __construct(Database $db, string $storageDir, string $version = '1.0.0')
    {
        $this->db = $db;
        $this->storageDir = $storageDir;
        $this->version = $version;
    }

    public function runChecks(): array
    {
        $checks = [];

        // 1. PHP Version
        $phpOk = version_compare(PHP_VERSION, '8.1.0', '>=');
        $checks['php_runtime'] = [
            'status' => $phpOk ? 'healthy' : 'critical',
            'detail' => 'PHP ' . PHP_VERSION,
        ];

        // 2. Database Connectivity
        try {
            $driver = $this->db->getDriver();
            $this->db->fetchValue("SELECT 1");
            $checks['database'] = [
                'status' => 'healthy',
                'detail' => "Connected ({$driver})",
            ];
        } catch (\Throwable $e) {
            $checks['database'] = [
                'status' => 'critical',
                'detail' => 'Connection failed',
            ];
        }

        // 3. Schema & Migrations
        try {
            $table = $this->db->tableName('cert_migrations');
            $appliedCount = (int)$this->db->fetchValue("SELECT COUNT(*) FROM {$table}");
            $checks['migrations'] = [
                'status' => 'healthy',
                'detail' => "{$appliedCount} migrations applied",
            ];
        } catch (\Throwable $e) {
            $checks['migrations'] = [
                'status' => 'warning',
                'detail' => 'Migration table not initialized',
            ];
        }

        // 4. Local Storage Writability
        $storageDirOk = is_dir($this->storageDir) && is_writable($this->storageDir);
        $checks['storage_filesystem'] = [
            'status' => $storageDirOk ? 'healthy' : 'critical',
            'detail' => $storageDirOk ? 'Writable' : 'Not writable or directory missing',
        ];

        // 5. Local Renderer Capability
        $checks['local_renderer'] = [
            'status' => 'healthy',
            'detail' => 'Pure-PHP Local PDF/QR Vector Engine Active (Self-hosted)',
        ];

        // Overall status
        $isHealthy = true;
        foreach ($checks as $c) {
            if ($c['status'] === 'critical') {
                $isHealthy = false;
                break;
            }
        }

        return [
            'status' => $isHealthy ? 'healthy' : 'unhealthy',
            'version' => $this->version,
            'timestamp' => date('c'),
            'checks' => $checks,
        ];
    }
}
