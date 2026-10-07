<?php
declare(strict_types=1);

namespace SOI\Certificates\Http;

use SOI\Certificates\Core\Plugin;
use SOI\Certificates\Core\Session;

class SuperAdminController
{
    protected Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function index(): void
    {
        $tenants = $this->plugin->tenantRepo->all();
        $health = $this->plugin->healthService->runChecks();
        $pendingMigrations = $this->plugin->migrationRunner->getPendingMigrations();
        $appliedMigrations = $this->plugin->migrationRunner->getAppliedMigrations();

        $cTable = $this->plugin->db->tableName('cert_certificates');
        $totalCerts = (int)$this->plugin->db->fetchValue("SELECT COUNT(*) FROM {$cTable}");

        require $this->plugin->baseDir . '/views/super-admin.php';
    }

    public function createTenant(): void
    {
        $name = trim($_POST['display_name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');

        if (!empty($name) && !empty($slug)) {
            $this->plugin->tenantRepo->create($slug, $name);
            Session::flash('success', "Tenant '{$name}' created successfully.");
        } else {
            Session::flash('error', "Tenant name and slug are required.");
        }

        header('Location: ' . $this->plugin->router->url('/super-admin'));
        exit;
    }

    public function suspendTenant(): void
    {
        $id = (int)($_POST['tenant_id'] ?? 0);
        $status = $_POST['status'] === 'active' ? 'suspended' : 'active';
        $this->plugin->tenantRepo->updateStatus($id, $status);
        Session::flash('success', "Tenant status updated to {$status}.");

        header('Location: ' . $this->plugin->router->url('/super-admin'));
        exit;
    }

    public function runMigrations(): void
    {
        try {
            $applied = $this->plugin->migrationRunner->runPending();
            $count = count($applied);
            Session::flash('success', "Applied {$count} pending database migration(s).");
        } catch (\Throwable $e) {
            Session::flash('error', "Migration error: " . $e->getMessage());
        }

        header('Location: ' . $this->plugin->router->url('/super-admin'));
        exit;
    }
}
