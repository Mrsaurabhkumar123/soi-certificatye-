<?php
declare(strict_types=1);

namespace SOI\Certificates\Templates;

use Exception;
use SOI\Certificates\Core\Database;
use SOI\Certificates\Tenancy\TenantContext;

/**
 * Service managing template lifecycle, schemas, and immutable version publishing.
 */
class TemplateService
{
    protected Database $db;
    protected TenantContext $tenantContext;

    public function __construct(Database $db, TenantContext $tenantContext)
    {
        $this->db = $db;
        $this->tenantContext = $tenantContext;
    }

    public function getTenantTemplates(): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $table = $this->db->tableName('cert_templates');
        $rows = $this->db->fetchAll(
            "SELECT * FROM {$table} WHERE tenant_id = :tid ORDER BY id DESC",
            ['tid' => $tenantId]
        );
        return array_map(fn($r) => new Template($r), $rows);
    }

    public function getPublishedTemplates(): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $table = $this->db->tableName('cert_templates');
        $rows = $this->db->fetchAll(
            "SELECT * FROM {$table} WHERE tenant_id = :tid AND status = 'published' ORDER BY name ASC",
            ['tid' => $tenantId]
        );
        return array_map(fn($r) => new Template($r), $rows);
    }

    public function findById(int $id): ?Template
    {
        $tenantId = $this->tenantContext->getTenantId();
        $table = $this->db->tableName('cert_templates');
        $row = $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE id = :id AND tenant_id = :tid",
            ['id' => $id, 'tid' => $tenantId]
        );
        return $row ? new Template($row) : null;
    }

    public function findVersionById(int $versionId): ?TemplateVersion
    {
        $tenantId = $this->tenantContext->getTenantId();
        $table = $this->db->tableName('cert_template_versions');
        $row = $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE id = :id AND tenant_id = :tid",
            ['id' => $versionId, 'tid' => $tenantId]
        );
        return $row ? new TemplateVersion($row) : null;
    }

    public function createTemplate(string $slug, string $name, ?string $category = null): Template
    {
        $tenantId = $this->tenantContext->getTenantId();
        $table = $this->db->tableName('cert_templates');

        $this->db->execute(
            "INSERT INTO {$table} (tenant_id, slug, name, category, status, created_at, updated_at)
             VALUES (:tid, :slug, :name, :cat, 'draft', datetime('now'), datetime('now'))",
            ['tid' => $tenantId, 'slug' => $slug, 'name' => $name, 'cat' => $category]
        );
        $id = $this->db->lastInsertId();

        // Create default draft version with initial certificate layout
        $defaultLayout = [
            'page' => ['size' => 'A4', 'orientation' => 'landscape'],
            'theme' => ['primary_color' => '#1e3a8a', 'accent_color' => '#f59e0b'],
            'elements' => [
                ['type' => 'text', 'id' => 'el_title', 'x' => 100, 'y' => 80, 'w' => 642, 'h' => 60, 'value' => 'CERTIFICATE OF ACHIEVEMENT', 'font_size' => 28, 'align' => 'center', 'bold' => true],
                ['type' => 'text', 'id' => 'el_sub', 'x' => 100, 'y' => 150, 'w' => 642, 'h' => 30, 'value' => 'This certificate is proudly presented to', 'font_size' => 14, 'align' => 'center'],
                ['type' => 'variable', 'id' => 'el_recipient', 'key' => 'recipient_name', 'x' => 100, 'y' => 200, 'w' => 642, 'h' => 50, 'font_size' => 24, 'align' => 'center', 'bold' => true],
                ['type' => 'text', 'id' => 'el_reason', 'x' => 150, 'y' => 260, 'w' => 542, 'h' => 40, 'value' => 'For outstanding performance and successful completion of the curriculum.', 'font_size' => 12, 'align' => 'center'],
                ['type' => 'variable', 'id' => 'el_course', 'key' => 'course_name', 'x' => 150, 'y' => 300, 'w' => 542, 'h' => 30, 'font_size' => 14, 'align' => 'center', 'bold' => true],
                ['type' => 'variable', 'id' => 'el_date', 'key' => 'issue_date', 'x' => 120, 'y' => 420, 'w' => 200, 'h' => 30, 'font_size' => 12, 'align' => 'left'],
                ['type' => 'qr', 'id' => 'el_qr', 'x' => 640, 'y' => 380, 'size' => 85],
            ]
        ];

        $defaultVariables = [
            ['key' => 'recipient_name', 'label' => 'Recipient Name', 'type' => 'string', 'required' => true],
            ['key' => 'course_name', 'label' => 'Program / Course Name', 'type' => 'string', 'required' => true],
            ['key' => 'issue_date', 'label' => 'Date of Issue', 'type' => 'date', 'required' => true],
        ];

        $vTable = $this->db->tableName('cert_template_versions');
        $canon = hash('sha256', json_encode($defaultLayout));
        $this->db->execute(
            "INSERT INTO {$vTable} 
            (template_id, tenant_id, version_number, page_format, layout_json, variable_schema_json, canonical_hash, created_at)
            VALUES (:tid_fk, :tid, 1, 'A4_LANDSCAPE', :layout, :schema, :hash, datetime('now'))",
            [
                'tid_fk' => $id,
                'tid' => $tenantId,
                'layout' => json_encode($defaultLayout),
                'schema' => json_encode($defaultVariables),
                'hash' => $canon,
            ]
        );
        $vId = $this->db->lastInsertId();

        $this->db->execute(
            "UPDATE {$table} SET draft_version_id = :vid WHERE id = :id",
            ['vid' => $vId, 'id' => $id]
        );

        return $this->findById($id);
    }

    public function publish(int $templateId, ?int $userId = null): TemplateVersion
    {
        $template = $this->findById($templateId);
        if (!$template || !$template->draftVersionId) {
            throw new Exception("Template or draft version not found.");
        }

        $draft = $this->findVersionById($template->draftVersionId);
        if (!$draft) {
            throw new Exception("Draft version data missing.");
        }

        // Validate layout structure
        if (empty($draft->layout['elements'])) {
            throw new Exception("Template layout must contain elements.");
        }

        // Compute deterministic immutable canonical hash
        $canonicalJson = json_encode([
            'page' => $draft->layout['page'] ?? [],
            'elements' => $draft->layout['elements'] ?? [],
            'schema' => $draft->variableSchema,
        ], JSON_UNESCAPED_SLASHES);
        $canonicalHash = hash('sha256', $canonicalJson);

        $vTable = $this->db->tableName('cert_template_versions');
        $this->db->execute(
            "UPDATE {$vTable} 
             SET canonical_hash = :hash, published_at = datetime('now'), published_by = :uid 
             WHERE id = :id",
            ['hash' => $canonicalHash, 'uid' => $userId, 'id' => $draft->id]
        );

        $tTable = $this->db->tableName('cert_templates');
        $this->db->execute(
            "UPDATE {$tTable} 
             SET status = 'published', published_version_id = :vid, updated_at = datetime('now') 
             WHERE id = :id",
            ['vid' => $draft->id, 'id' => $template->id]
        );

        return $this->findVersionById($draft->id);
    }
}
