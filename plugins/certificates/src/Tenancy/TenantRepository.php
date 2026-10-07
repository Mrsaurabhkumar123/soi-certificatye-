<?php
declare(strict_types=1);

namespace SOI\Certificates\Tenancy;

use SOI\Certificates\Core\Database;

/**
 * Tenant Repository handling persistence and user memberships.
 */
class TenantRepository
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
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

    public function create(string $slug, string $displayName, array $branding = []): Tenant
    {
        $table = $this->db->tableName('cert_tenants');
        $this->db->execute(
            "INSERT INTO {$table} (slug, display_name, status, branding_json, created_at, updated_at)
             VALUES (:slug, :name, 'active', :branding, datetime('now'), datetime('now'))",
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
        $table = $this->db->tableName('cert_tenants');
        return $this->db->execute(
            "UPDATE {$table} SET status = :status WHERE id = :id",
            ['status' => $status, 'id' => $id]
        ) > 0;
    }

    public function addMembership(int $tenantId, int $userId, string $roleKey = 'viewer'): bool
    {
        $table = $this->db->tableName('cert_memberships');
        return $this->db->execute(
            "INSERT OR REPLACE INTO {$table} (tenant_id, user_id, role_key, status, created_at)
             VALUES (:tid, :uid, :role, 'active', datetime('now'))",
            ['tid' => $tenantId, 'uid' => $userId, 'role' => $roleKey]
        ) > 0;
    }

    public function getUserMembership(int $tenantId, int $userId): ?array
    {
        $table = $this->db->tableName('cert_memberships');
        return $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE tenant_id = :tid AND user_id = :uid AND status = 'active'",
            ['tid' => $tenantId, 'uid' => $userId]
        );
    }
}
