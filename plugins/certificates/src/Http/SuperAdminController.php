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
        $health = $this->plugin->systemDiagnostics->runChecks();
        $pendingMigrations = $this->plugin->migrationRunner->getPendingMigrations();
        $appliedMigrations = $this->plugin->migrationRunner->getAppliedMigrations();
        $activeTenantCount = count(array_filter($tenants, static fn($tenant): bool => $tenant->status === 'active'));
        $suspendedTenantCount = count(array_filter($tenants, static fn($tenant): bool => $tenant->status === 'suspended'));

        $cTable = $this->plugin->db->tableName('cert_certificates');
        $totalCerts = (int)$this->plugin->db->fetchValue("SELECT COUNT(*) FROM {$cTable}");

        require $this->plugin->baseDir . '/views/super-admin.php';
    }

    public function createTenant(): void
    {
        $name = trim($_POST['display_name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');

        if (!empty($name) && !empty($slug)) {
            try {
                $this->plugin->tenantRepo->create($slug, $name);
                Session::flash('success', "Tenant '{$name}' created successfully.");
            } catch (\InvalidArgumentException $e) {
                Session::flash('error', $e->getMessage());
            } catch (\Throwable $e) {
                error_log('SOI tenant creation failed: ' . $e->getMessage());
                Session::flash('error', 'The tenant could not be created.');
            }
        } else {
            Session::flash('error', "Tenant name and slug are required.");
        }

        header('Location: ' . $this->plugin->router->url('/super-admin'));
        exit;
    }

    public function suspendTenant(): void
    {
        $id = (int)($_POST['tenant_id'] ?? 0);
        try {
            $current = $this->plugin->tenantRepo->findById($id);
            if ($current === null) {
                throw new \InvalidArgumentException('Tenant not found.');
            }
            $status = $current->status === 'active' ? 'suspended' : 'active';
            $this->plugin->tenantRepo->updateStatus($id, $status);
            $this->plugin->audit->log(null, 'platform_admin', $this->plugin->tenantContext->getCurrentUserId(), 'tenant.status.updated', 'tenant', (string)$id, ['status' => $status]);
            Session::flash('success', "Tenant status updated to {$status}.");
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI tenant status update failed: ' . $e->getMessage());
            Session::flash('error', 'Tenant status could not be updated.');
        }

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
            error_log('SOI migration run failed: ' . $e->getMessage());
            Session::flash('error', 'Database migrations could not be completed. Check the server log.');
        }

        header('Location: ' . $this->plugin->router->url('/super-admin'));
        exit;
    }
}
