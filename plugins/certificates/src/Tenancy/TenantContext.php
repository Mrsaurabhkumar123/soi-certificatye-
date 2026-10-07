<?php
declare(strict_types=1);

namespace SOI\Certificates\Tenancy;

use Exception;

/**
 * TenantContext resolves and enforces active tenant boundaries.
 * Fails closed if no authorized tenant is present.
 */
class TenantContext
{
    protected ?Tenant $currentTenant = null;
    protected ?int $currentUserId = null;
    protected string $currentRole = 'viewer';

    public function __construct(?Tenant $tenant = null, ?int $userId = null, string $role = 'viewer')
    {
        $this->currentTenant = $tenant;
        $this->currentUserId = $userId;
        $this->currentRole = $role;
    }

    public function setTenant(Tenant $tenant, string $role = 'viewer'): void
    {
        $this->currentTenant = $tenant;
        $this->currentRole = $role;
    }

    public function getTenant(): ?Tenant
    {
        return $this->currentTenant;
    }

    public function getTenantId(): int
    {
        if ($this->currentTenant === null) {
            throw new Exception("Security Violation: Access attempted without valid tenant context.");
        }
        return $this->currentTenant->id;
    }

    public function getRole(): string
    {
        return $this->currentRole;
    }

    public function hasTenant(): bool
    {
        return $this->currentTenant !== null && $this->currentTenant->isActive();
    }
}
