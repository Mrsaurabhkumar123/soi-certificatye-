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
    protected ?array $apiScopes = null;

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
        if ($this->apiScopes !== null) {
            return $this->tenantContext->hasTenant()
                && in_array($permission, $this->apiScopes, true);
        }

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

    public function setApiScopes(?array $scopes): void
    {
        $this->apiScopes = $scopes;
    }

    public function require(string $permission): void
    {
        if (!$this->can($permission)) {
            throw new \RuntimeException('Permission denied: ' . $permission);
        }
    }
}
