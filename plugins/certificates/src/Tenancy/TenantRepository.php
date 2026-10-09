<?php
declare(strict_types=1);

namespace SOI\Certificates\Tenancy;

use SOI\Certificates\Core\Database;
use InvalidArgumentException;

/**
 * Tenant Repository handling persistence and user memberships.
 */
class TenantRepository
{
    protected Database $db;
    protected TenantThemeManager $themeManager;

    public function __construct(Database $db, ?TenantThemeManager $themeManager = null)
    {
        $this->db = $db;
        $this->themeManager = $themeManager ?? new TenantThemeManager();
    }

    public function findById(int $id): ?Tenant
    {
        $table = $this->db->tableName('cert_tenants');
        $row = $this->db->fetchOne("SELECT * FROM {$table} WHERE id = :id", ['id' => $id]);
        return $row ? new Tenant($row) : null;
    }

    public function findBySlug(string $slug): ?Tenant
    {
        $table = $this->db->tableName('cert_tenants');
        $row = $this->db->fetchOne("SELECT * FROM {$table} WHERE slug = :slug", ['slug' => $slug]);
        return $row ? new Tenant($row) : null;
    }

    public function all(): array
    {
        $table = $this->db->tableName('cert_tenants');
        $rows = $this->db->fetchAll("SELECT * FROM {$table} ORDER BY id ASC");
        return array_map(fn($r) => new Tenant($r), $rows);
    }

    public function findFirstActive(): ?Tenant
    {
        $table = $this->db->tableName('cert_tenants');
        $row = $this->db->fetchOne("SELECT * FROM {$table} WHERE status = 'active' ORDER BY id ASC LIMIT 1");
        return $row ? new Tenant($row) : null;
    }

    public function listTenantsForUser(int $userId): array
    {
        if ($userId < 1) {
            return [];
        }
        $memberships = $this->db->tableName('cert_memberships');
        $tenants = $this->db->tableName('cert_tenants');
        return $this->db->fetchAll(
            "SELECT t.*, m.role_key
             FROM {$tenants} t
             JOIN {$memberships} m ON m.tenant_id = t.id
             WHERE m.user_id = :user_id AND m.status = 'active' AND t.status = 'active'
             ORDER BY t.id ASC",
            ['user_id' => $userId]
        );
    }

    public function create(string $slug, string $displayName, array $branding = []): Tenant
    {
        $slug = strtolower(trim($slug));
        $displayName = trim($displayName);
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $slug) || $displayName === '' || strlen($displayName) > 128) {
            throw new InvalidArgumentException('Tenant slug or display name is invalid.');
        }
        $table = $this->db->tableName('cert_tenants');
        $this->db->execute(
            "INSERT INTO {$table} (slug, display_name, status, branding_json, created_at, updated_at)
             VALUES (:slug, :name, 'active', :branding, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            [
                'slug' => $slug,
                'name' => $displayName,
                'branding' => json_encode($branding),
            ]
        );
        $id = $this->db->lastInsertId();
        return $this->findById($id);
    }

    public function updateStatus(int $id, string $status): bool
    {
        if ($id < 1 || !in_array($status, ['active', 'suspended', 'archived'], true)) {
            throw new InvalidArgumentException('Tenant status transition is invalid.');
        }
        $table = $this->db->tableName('cert_tenants');
        return $this->db->execute(
            "UPDATE {$table} SET status = :status, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
            ['status' => $status, 'id' => $id]
        ) > 0;
    }

    public function updateBranding(int $tenantId, array $branding): bool
    {
        if ($tenantId < 1) {
            throw new InvalidArgumentException('Tenant context is required to update branding.');
        }
        $cleanBranding = $this->themeManager->validateAndNormalize($branding);

        $table = $this->db->tableName('cert_tenants');
        if ($this->findById($tenantId) === null) {
            return false;
        }
        $this->db->execute(
            "UPDATE {$table} SET branding_json = :branding, updated_at = CURRENT_TIMESTAMP WHERE id = :id",
            ['branding' => json_encode($cleanBranding, JSON_THROW_ON_ERROR), 'id' => $tenantId]
        );
        return true;
    }

    public function addMembership(int $tenantId, int $userId, string $roleKey = 'viewer'): bool
    {
        if ($userId < 1 || !$this->isTenantRole($roleKey)) {
            throw new InvalidArgumentException('Membership requires a valid tenant, user and built-in role.');
        }

        return $this->withTenantMembershipLock($tenantId, function () use ($tenantId, $userId, $roleKey): bool {
            $existing = $this->getUserMembershipIncludingInactive($tenantId, $userId);
            if ($existing !== null && $existing['status'] === 'active'
                && $existing['role_key'] === 'tenant_owner' && $roleKey !== 'tenant_owner') {
                $this->assertNotLastActiveOwner($tenantId);
            }

            $table = $this->db->tableName('cert_memberships');
            if ($this->db->getDriver() === 'sqlite') {
                return $this->db->execute(
                    "INSERT INTO {$table} (tenant_id, user_id, role_key, status, created_at)
                     VALUES (:tid, :uid, :role, 'active', datetime('now'))
                     ON CONFLICT(tenant_id, user_id) DO UPDATE SET role_key = excluded.role_key, status = 'active'",
                    ['tid' => $tenantId, 'uid' => $userId, 'role' => $roleKey]
                ) > 0;
            }

            return $this->db->execute(
                "INSERT INTO {$table} (tenant_id, user_id, role_key, status, created_at)
                 VALUES (:tid, :uid, :role, 'active', CURRENT_TIMESTAMP)
                 ON DUPLICATE KEY UPDATE role_key = VALUES(role_key), status = 'active'",
                ['tid' => $tenantId, 'uid' => $userId, 'role' => $roleKey]
            ) > 0;
        });
    }

    public function listMemberships(int $tenantId): array
    {
        if ($tenantId < 1) {
            throw new InvalidArgumentException('A valid tenant is required to list memberships.');
        }

        $memberships = $this->db->tableName('cert_memberships');
        return $this->db->fetchAll(
            "SELECT user_id, role_key, status, created_at
             FROM {$memberships}
             WHERE tenant_id = :tenant_id AND status = 'active'
             ORDER BY user_id ASC",
            ['tenant_id' => $tenantId]
        );
    }

    public function updateMembershipRole(int $tenantId, int $userId, string $roleKey): bool
    {
        if ($userId < 1 || !$this->isTenantRole($roleKey)) {
            throw new InvalidArgumentException('A valid user and built-in tenant role are required.');
        }

        return $this->withTenantMembershipLock($tenantId, function () use ($tenantId, $userId, $roleKey): bool {
            $current = $this->getUserMembership($tenantId, $userId);
            if ($current === null) {
                return false;
            }
            if ($current['role_key'] === $roleKey) {
                return true;
            }
            if ($current['role_key'] === 'tenant_owner' && $roleKey !== 'tenant_owner') {
                $this->assertNotLastActiveOwner($tenantId);
            }
            $table = $this->db->tableName('cert_memberships');
            return $this->db->execute(
                "UPDATE {$table} SET role_key = :role
                 WHERE tenant_id = :tenant_id AND user_id = :user_id AND status = 'active'",
                ['role' => $roleKey, 'tenant_id' => $tenantId, 'user_id' => $userId]
            ) > 0;
        });
    }

    public function deactivateMembership(int $tenantId, int $userId): bool
    {
        if ($userId < 1) {
            throw new InvalidArgumentException('A valid user is required to deactivate a membership.');
        }

        return $this->withTenantMembershipLock($tenantId, function () use ($tenantId, $userId): bool {
            $current = $this->getUserMembership($tenantId, $userId);
            if ($current === null) {
                return false;
            }
            if ($current['role_key'] === 'tenant_owner') {
                $this->assertNotLastActiveOwner($tenantId);
            }
            $table = $this->db->tableName('cert_memberships');
            return $this->db->execute(
                "UPDATE {$table} SET status = 'inactive'
                 WHERE tenant_id = :tenant_id AND user_id = :user_id AND status = 'active'",
                ['tenant_id' => $tenantId, 'user_id' => $userId]
            ) > 0;
        });
    }

    public function getUserMembership(int $tenantId, int $userId): ?array
    {
        $table = $this->db->tableName('cert_memberships');
        return $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE tenant_id = :tid AND user_id = :uid AND status = 'active'",
            ['tid' => $tenantId, 'uid' => $userId]
        );
    }

    public function resolveMembership(int $tenantId, int $userId): ?array
    {
        if ($tenantId < 1 || $userId < 1) {
            return null;
        }

        $memberships = $this->db->tableName('cert_memberships');
        $tenants = $this->db->tableName('cert_tenants');
        return $this->db->fetchOne(
            "SELECT m.*, t.slug, t.display_name, t.status AS tenant_status, t.branding_json
             FROM {$memberships} m
             JOIN {$tenants} t ON t.id = m.tenant_id
             WHERE m.tenant_id = :tenant_id AND m.user_id = :user_id
               AND m.status = 'active' AND t.status = 'active'",
            ['tenant_id' => $tenantId, 'user_id' => $userId]
        );
    }

    private function getUserMembershipIncludingInactive(int $tenantId, int $userId): ?array
    {
        $table = $this->db->tableName('cert_memberships');
        return $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE tenant_id = :tenant_id AND user_id = :user_id",
            ['tenant_id' => $tenantId, 'user_id' => $userId]
        );
    }

    private function assertNotLastActiveOwner(int $tenantId): void
    {
        $memberships = $this->db->tableName('cert_memberships');
        $owners = (int)$this->db->fetchValue(
            "SELECT COUNT(*) FROM {$memberships}
             WHERE tenant_id = :tenant_id AND role_key = 'tenant_owner' AND status = 'active'",
            ['tenant_id' => $tenantId]
        );
        if ($owners <= 1) {
            throw new \RuntimeException('The last active tenant owner cannot be demoted or deactivated.');
        }
    }

    private function withTenantMembershipLock(int $tenantId, callable $callback): mixed
    {
        if ($tenantId < 1) {
            throw new InvalidArgumentException('A valid tenant is required to modify memberships.');
        }

        $pdo = $this->db->getPdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }

        try {
            $tenants = $this->db->tableName('cert_tenants');
            $lockSuffix = $this->db->getDriver() === 'sqlite' ? '' : ' FOR UPDATE';
            $tenant = $this->db->fetchOne(
                "SELECT id FROM {$tenants} WHERE id = :tenant_id{$lockSuffix}",
                ['tenant_id' => $tenantId]
            );
            if ($tenant === null) {
                throw new InvalidArgumentException('Tenant does not exist.');
            }
            $result = $callback();
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function isTenantRole(string $roleKey): bool
    {
        return in_array($roleKey, [
            'tenant_owner',
            'tenant_admin',
            'template_designer',
            'issuer',
            'viewer',
        ], true);
    }
}
