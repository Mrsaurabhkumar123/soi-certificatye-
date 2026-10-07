<?php
declare(strict_types=1);

namespace SOI\Certificates\Templates;

/**
 * Immutable Template Version snapshot with structured layout and variable schema.
 */
class TemplateVersion
{
    public int $id;
    public int $templateId;
    public int $tenantId;
    public int $versionNumber;
    public string $pageFormat; // 'A4_LANDSCAPE', 'A4_PORTRAIT', 'LETTER_LANDSCAPE'
    public array $layout = [];
    public array $variableSchema = [];
    public string $canonicalHash;
    public ?string $publishedAt;

    public function __construct(array $data)
    {
        $this->id = (int)($data['id'] ?? 0);
        $this->templateId = (int)($data['template_id'] ?? 0);
        $this->tenantId = (int)($data['tenant_id'] ?? 0);
        $this->versionNumber = (int)($data['version_number'] ?? 1);
        $this->pageFormat = (string)($data['page_format'] ?? 'A4_LANDSCAPE');
        $this->layout = is_string($data['layout_json'] ?? null)
            ? (json_decode($data['layout_json'], true) ?: [])
            : ($data['layout'] ?? []);
        $this->variableSchema = is_string($data['variable_schema_json'] ?? null)
            ? (json_decode($data['variable_schema_json'], true) ?: [])
            : ($data['variable_schema'] ?? []);
        $this->canonicalHash = (string)($data['canonical_hash'] ?? '');
        $this->publishedAt = $data['published_at'] ?? null;
    }
}
