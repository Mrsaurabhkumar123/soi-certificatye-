<?php
declare(strict_types=1);

namespace SOI\Certificates\Templates;

/**
 * Template entity representing a certificate template design root.
 */
class Template
{
    public int $id;
    public int $tenantId;
    public string $slug;
    public string $name;
    public ?string $category;
    public string $status; // 'draft', 'published', 'archived'
    public ?int $draftVersionId;
    public ?int $publishedVersionId;
    public string $createdAt;

    public function __construct(array $data)
    {
        $this->id = (int)($data['id'] ?? 0);
        $this->tenantId = (int)($data['tenant_id'] ?? 0);
        $this->slug = (string)($data['slug'] ?? '');
        $this->name = (string)($data['name'] ?? '');
        $this->category = $data['category'] ?? null;
        $this->status = (string)($data['status'] ?? 'draft');
        $this->draftVersionId = isset($data['draft_version_id']) ? (int)$data['draft_version_id'] : null;
        $this->publishedVersionId = isset($data['published_version_id']) ? (int)$data['published_version_id'] : null;
        $this->createdAt = (string)($data['created_at'] ?? date('Y-m-d H:i:s'));
    }

    public function isPublished(): bool
    {
        return $this->status === 'published' && $this->publishedVersionId !== null;
    }
}
