<?php
declare(strict_types=1);

namespace SOI\Certificates\Forms;

use SOI\Certificates\Core\Database;

/**
 * Dynamic form service for mapping public/internal submission forms to certificate templates.
 */
class DynamicFormService
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function createForm(int $tenantId, string $formKey, string $title, int $templateId, array $mapping = []): int
    {
        $table = $this->db->tableName('cert_forms');
        $this->db->execute(
            "INSERT INTO {$table} (tenant_id, form_key, title, template_id, visibility, field_mapping_json, is_active, created_at)
             VALUES (:tid, :fkey, :title, :tpl, 'public', :map, 1, datetime('now'))",
            [
                'tid' => $tenantId,
                'fkey' => $formKey,
                'title' => $title,
                'tpl' => $templateId,
                'map' => json_encode($mapping),
            ]
        );
        return $this->db->lastInsertId();
    }

    public function submitForm(int $formId, int $tenantId, array $data, ?string $ip = null): int
    {
        $table = $this->db->tableName('cert_form_submissions');
        $this->db->execute(
            "INSERT INTO {$table} (form_id, tenant_id, submitter_ip, payload_json, status, created_at)
             VALUES (:fid, :tid, :ip, :payload, 'pending', datetime('now'))",
            [
                'fid' => $formId,
                'tid' => $tenantId,
                'ip' => $ip,
                'payload' => json_encode($data),
            ]
        );
        return $this->db->lastInsertId();
    }
}
