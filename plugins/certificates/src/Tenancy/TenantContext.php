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

    public function setTenant(Tenant $tenant, string $role = 'viewer', ?int $userId = null): void
    {
        $this->currentTenant = $tenant;
        $this->currentRole = $role;
        $this->currentUserId = $userId;
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

    public function getCurrentUserId(): ?int
    {
        return $this->currentUserId;
    }

    public function clear(): void
    {
        $this->currentTenant = null;
        $this->currentUserId = null;
        $this->currentRole = 'viewer';
    }

    public function snapshot(): array
    {
        return [
            'tenant' => $this->currentTenant,
            'user_id' => $this->currentUserId,
            'role' => $this->currentRole,
        ];
    }

    public function restore(array $context): void
    {
        $tenant = $context['tenant'] ?? null;
        $userId = $context['user_id'] ?? null;
        $role = $context['role'] ?? null;
        if (($tenant !== null && !$tenant instanceof Tenant)
            || ($userId !== null && (!is_int($userId) || $userId < 1))
            || !is_string($role)) {
            throw new Exception('Invalid tenant context snapshot.');
        }

        $this->currentTenant = $tenant;
        $this->currentUserId = $userId;
        $this->currentRole = $role;
    }

    public function hasTenant(): bool
    {
        return $this->currentTenant !== null && $this->currentTenant->isActive();
    }
}
