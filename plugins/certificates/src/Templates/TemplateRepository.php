<?php
declare(strict_types=1);

namespace SOI\Certificates\Templates;

use RuntimeException;
use SOI\Certificates\Core\Database;
use SOI\Certificates\Tenancy\TenantContext;

final class TemplateRepository
{
    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenantContext
    ) {
    }

    public function findById(int $id): ?Template
    {
        $table = $this->db->tableName('cert_templates');
        $row = $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE id = :id AND tenant_id = :tenant_id",
            ['id' => $id, 'tenant_id' => $this->tenantContext->getTenantId()]
        );
        return $row ? new Template($row) : null;
    }

    public function findVersionById(int $id): ?TemplateVersion
    {
        $table = $this->db->tableName('cert_template_versions');
        $row = $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE id = :id AND tenant_id = :tenant_id",
            ['id' => $id, 'tenant_id' => $this->tenantContext->getTenantId()]
        );
        return $row ? new TemplateVersion($row) : null;
    }

    public function saveDraft(int $templateId, array $layout, array $variableSchema): TemplateVersion
    {
        $tenantId = $this->tenantContext->getTenantId();
        $template = $this->findById($templateId);
        if ($template === null) {
            throw new RuntimeException('Template not found.');
        }

        $this->db->beginTransaction();
        try {
            $versions = $this->db->tableName('cert_template_versions');
            $templates = $this->db->tableName('cert_templates');
            $draft = $template->draftVersionId ? $this->findVersionById($template->draftVersionId) : null;
            $now = date('Y-m-d H:i:s');
            $layoutJson = self::encode($layout);
            $schemaJson = self::encode($variableSchema);
            $hash = self::canonicalHash($layout, $variableSchema);

            if ($draft !== null && $draft->publishedAt === null) {
                $this->db->execute(
                    "UPDATE {$versions}
                     SET layout_json = :layout, variable_schema_json = :schema, canonical_hash = :hash, page_format = :page_format
                     WHERE id = :id AND tenant_id = :tenant_id AND published_at IS NULL",
                    [
                        'layout' => $layoutJson,
                        'schema' => $schemaJson,
                        'hash' => $hash,
                        'page_format' => self::pageFormat($layout),
                        'id' => $draft->id,
                        'tenant_id' => $tenantId,
                    ]
                );
                $versionId = $draft->id;
            } else {
                $versionNumber = (int)$this->db->fetchValue(
                    "SELECT COALESCE(MAX(version_number), 0) + 1
                     FROM {$versions} WHERE template_id = :template_id AND tenant_id = :tenant_id",
                    ['template_id' => $templateId, 'tenant_id' => $tenantId]
                );
                $this->db->execute(
                    "INSERT INTO {$versions}
                     (template_id, tenant_id, version_number, page_format, layout_json, variable_schema_json, canonical_hash, created_at)
                     VALUES (:template_id, :tenant_id, :version, :page_format, :layout, :schema, :hash, :created_at)",
                    [
                        'template_id' => $templateId,
                        'tenant_id' => $tenantId,
                        'version' => $versionNumber,
                        'page_format' => self::pageFormat($layout),
                        'layout' => $layoutJson,
                        'schema' => $schemaJson,
                        'hash' => $hash,
                        'created_at' => $now,
                    ]
                );
                $versionId = $this->db->lastInsertId();
                $this->db->execute(
                    "UPDATE {$templates} SET draft_version_id = :version_id, updated_at = :updated_at
                     WHERE id = :id AND tenant_id = :tenant_id",
                    ['version_id' => $versionId, 'updated_at' => $now, 'id' => $templateId, 'tenant_id' => $tenantId]
                );
            }
            $this->db->commit();
            return $this->findVersionById($versionId) ?? throw new RuntimeException('Saved template draft could not be reloaded.');
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function publishDraft(int $templateId, ?int $userId = null): TemplateVersion
    {
        $tenantId = $this->tenantContext->getTenantId();
        $template = $this->findById($templateId);
        if ($template === null || !$template->draftVersionId) {
            throw new RuntimeException('Template or draft version not found.');
        }
        $draft = $this->findVersionById($template->draftVersionId);
        if ($draft === null || $draft->publishedAt !== null) {
            throw new RuntimeException('A published template version is immutable. Save a new draft before publishing.');
        }

        $hash = self::canonicalHash($draft->layout, $draft->variableSchema);
        $versions = $this->db->tableName('cert_template_versions');
        $templates = $this->db->tableName('cert_templates');
        $this->db->beginTransaction();
        try {
            $updated = $this->db->execute(
                "UPDATE {$versions}
                 SET canonical_hash = :hash, published_at = :published_at, published_by = :user_id
                 WHERE id = :id AND tenant_id = :tenant_id AND published_at IS NULL",
                ['hash' => $hash, 'published_at' => date('Y-m-d H:i:s'), 'user_id' => $userId, 'id' => $draft->id, 'tenant_id' => $tenantId]
            );
            if ($updated !== 1) {
                throw new RuntimeException('Draft changed during publish; reload and try again.');
            }
            $this->db->execute(
                "UPDATE {$templates}
                 SET status = 'published', published_version_id = :version_id, updated_at = :updated_at
                 WHERE id = :id AND tenant_id = :tenant_id",
                ['version_id' => $draft->id, 'updated_at' => date('Y-m-d H:i:s'), 'id' => $templateId, 'tenant_id' => $tenantId]
            );
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->findVersionById($draft->id) ?? throw new RuntimeException('Published template version could not be reloaded.');
    }

    public static function canonicalHash(array $layout, array $variableSchema): string
    {
        $content = ['layout' => self::sortKeys($layout), 'variable_schema' => self::sortKeys($variableSchema)];
        return hash('sha256', self::encode($content));
    }

    private static function encode(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }

    private static function sortKeys(array $value): array
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::sortKeys($item);
            }
        }
        return $value;
    }

    private static function pageFormat(array $layout): string
    {
        $size = strtoupper((string)($layout['page']['size'] ?? 'A4'));
        $orientation = strtoupper((string)($layout['page']['orientation'] ?? 'LANDSCAPE'));
        return substr($size . '_' . $orientation, 0, 32);
    }
}
