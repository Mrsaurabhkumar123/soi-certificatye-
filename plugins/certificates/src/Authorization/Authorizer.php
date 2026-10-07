<?php
declare(strict_types=1);

namespace SOI\Certificates\Authorization;

use SOI\Certificates\Tenancy\TenantContext;

/**
 * Enterprise RBAC and policy engine.
 * Keeps authorization out of presentation templates/views.
 */
class Authorizer
{
    protected TenantContext $tenantContext;
    protected bool $isPlatformAdmin = false;

    public function __construct(TenantContext $tenantContext, bool $isPlatformAdmin = false)
    {
        $this->tenantContext = $tenantContext;
        $this->isPlatformAdmin = $isPlatformAdmin;
    }

    public function isPlatformAdmin(): bool
    {
        return $this->isPlatformAdmin;
    }

    public function can(string $permission): bool
    {
        // 1. Explicit platform admin bypass
        if ($this->isPlatformAdmin) {
            return true;
        }

        // 2. Tenant access requires active tenant
        if (!$this->tenantContext->hasTenant()) {
            return false;
        }

        $role = $this->tenantContext->getRole();
        $rolePerms = Role::getDefaultRolePermissions();

        $allowed = $rolePerms[$role] ?? [];
        return in_array($permission, $allowed, true);
    }

    public function require(string $permission): void
    {
        if (!$this->can($permission)) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo "403 Forbidden: Insufficient permissions for [{$permission}].";
            exit;
        }
    }
}
