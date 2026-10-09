<?php
declare(strict_types=1);

namespace SOI\Certificates\Tenancy;

/**
 * Tenant entity representing an organization.
 */
class Tenant
{
    public int $id;
    public string $slug;
    public string $displayName;
    public string $status; // 'active', 'suspended', 'archived'
    public array $branding = [];
    public string $createdAt;

    public function __construct(array $data)
    {
        $this->id = (int)($data['id'] ?? 0);
        $this->slug = (string)($data['slug'] ?? '');
        $this->displayName = (string)($data['display_name'] ?? '');
        $this->status = (string)($data['status'] ?? 'active');
        $this->branding = is_string($data['branding_json'] ?? null) 
            ? (json_decode($data['branding_json'], true) ?: []) 
            : ($data['branding'] ?? []);
        $this->createdAt = (string)($data['created_at'] ?? date('Y-m-d H:i:s'));
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    public function isArchived(): bool
    {
        return $this->status === 'archived';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'display_name' => $this->displayName,
            'status' => $this->status,
            'branding' => $this->branding,
            'created_at' => $this->createdAt,
        ];
    }
}
