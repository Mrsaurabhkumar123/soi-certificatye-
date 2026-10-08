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

    public function createForm(
        int $tenantId,
        string $formKey,
        string $title,
        int $templateId,
        array $mapping = [],
        bool $requiresApproval = false,
        array $fieldSchema = [],
        ?string $issueMode = null
    ): int
    {
        $formKey = strtolower(trim($formKey));
        $title = trim($title);
        if ($tenantId < 1 || $templateId < 1 || !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $formKey)
            || $title === '' || strlen($title) > 128) {
            throw new \InvalidArgumentException('Form tenant, key, title, or template is invalid.');
        }
        $issueMode ??= $requiresApproval ? 'approval' : 'request_only';
        if (!in_array($issueMode, ['approval', 'request_only', 'immediate'], true)) {
            throw new \InvalidArgumentException('The form issue mode is invalid.');
        }
        $requiresApproval = $issueMode === 'approval';

        $templates = $this->db->tableName('cert_templates');
        $versions = $this->db->tableName('cert_template_versions');
        $template = $this->db->fetchOne(
            "SELECT v.variable_schema_json FROM {$templates} t
             JOIN {$versions} v ON v.id = t.published_version_id AND v.tenant_id = t.tenant_id
             WHERE t.id = :template_id AND t.tenant_id = :tenant_id
               AND t.status = 'published' AND v.published_at IS NOT NULL",
            ['template_id' => $templateId, 'tenant_id' => $tenantId]
        );
        if ($template === null) {
            throw new \InvalidArgumentException('Forms must use a published template from the same tenant.');
        }
        $templateSchema = json_decode((string)$template['variable_schema_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($templateSchema)) {
            throw new \RuntimeException('The published template variable schema is invalid.');
        }
        $targetDefinitions = [
            'recipient_name' => ['type' => 'text', 'required' => true, 'options' => []],
            'recipient_email' => ['type' => 'email', 'required' => false, 'options' => []],
        ];
        foreach ($templateSchema as $variable) {
            if (!is_array($variable) || !is_string($variable['key'] ?? null)
                || in_array($variable['key'], ['certificate_number', 'verification_url', 'issue_date', 'recipient_name', 'recipient_email', 'tenant_name'], true)) {
                continue;
            }
            $targetDefinitions[$variable['key']] = [
                'type' => self::formFieldType((string)$variable['type']),
                'required' => ($variable['required'] ?? false) === true,
                'options' => $variable['type'] === 'enum' ? $variable['options'] : [],
            ];
        }

        foreach ($mapping as $sourceField => $targetVariable) {
            if (!is_string($sourceField) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $sourceField)
                || !is_string($targetVariable) || !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,63}$/', $targetVariable)
                || !isset($targetDefinitions[$targetVariable])
                || in_array($targetVariable, ['certificate_number', 'verification_url', 'tenant_name', 'issue_date'], true)) {
                throw new \InvalidArgumentException('Form mappings must target permitted template variables.');
            }
        }
        if (count(array_unique(array_values($mapping))) !== count($mapping)) {
            throw new \InvalidArgumentException('Each form field must map to a distinct template variable.');
        }
        foreach ($targetDefinitions as $target => $definition) {
            if ($definition['required'] && !in_array($target, $mapping, true)) {
                throw new \InvalidArgumentException('A required template variable is missing from the public form: ' . $target . '.');
            }
        }
        if ($fieldSchema === []) {
            foreach ($mapping as $sourceField => $targetVariable) {
                $fieldSchema[] = [
                    'name' => $sourceField,
                    'label' => ucwords(str_replace('_', ' ', $sourceField)),
                    'type' => $targetDefinitions[$targetVariable]['type'],
                    'required' => $targetDefinitions[$targetVariable]['required'],
                ];
            }
        }
        $schema = [];
        foreach ($fieldSchema as $field) {
            if (!is_array($field) || !is_string($field['name'] ?? null)
                || !is_string($field['label'] ?? null) || !is_string($field['type'] ?? null)
                || !is_bool($field['required'] ?? null)) {
                throw new \InvalidArgumentException('Form field definitions must be objects.');
            }
            $name = $field['name'];
            $label = trim((string)($field['label'] ?? ''));
            $type = $field['type'];
            $required = $field['required'];
            if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $name) || $label === '' || strlen($label) > 128
                || !in_array($type, ['text', 'email', 'date', 'enum'], true)
                || in_array($name, ['certificate_number', 'verification_url', 'issue_date', 'tenant_name'], true)) {
                throw new \InvalidArgumentException('A form field has an unsupported name, type, or label.');
            }
            if (!isset($mapping[$name])) {
                throw new \InvalidArgumentException('Every public form field must map to a template variable.');
            }
            $target = $targetDefinitions[$mapping[$name]];
            if ($type !== $target['type']) {
                throw new \InvalidArgumentException('A form field type must match its mapped template variable.');
            }
            if ($target['required'] && !$required) {
                throw new \InvalidArgumentException('Fields mapped to required template variables must also be required.');
            }
            $schema[] = [
                'name' => $name,
                'label' => $label,
                'type' => $type,
                'required' => $required,
                ...($type === 'enum' ? ['options' => $target['options']] : []),
            ];
        }
        if ($schema === [] || count($schema) > 30
            || count(array_unique(array_column($schema, 'name'))) !== count($schema)
            || count($schema) !== count($mapping) || !in_array('recipient_name', $mapping, true)) {
            throw new \InvalidArgumentException('Form fields must be unique, mapped, and include recipient name.');
        }

        $table = $this->db->tableName('cert_forms');
        $this->db->execute(
            "INSERT INTO {$table}
             (tenant_id, form_key, title, template_id, visibility, field_mapping_json, field_schema_json,
              issue_mode, requires_approval, is_active, created_at, updated_at)
             VALUES (:tid, :fkey, :title, :tpl, 'public', :map, :schema, :mode, :approval, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            [
                'tid' => $tenantId,
                'fkey' => $formKey,
                'title' => $title,
                'tpl' => $templateId,
                'map' => json_encode($mapping, JSON_THROW_ON_ERROR),
                'schema' => json_encode($schema, JSON_THROW_ON_ERROR),
                'mode' => $issueMode,
                'approval' => $requiresApproval,
            ]
        );
        return $this->db->lastInsertId();
    }

    public function submitForm(int $formId, int $tenantId, array $data, ?string $ip = null): int
    {
        if ($formId < 1 || $tenantId < 1 || strlen(json_encode($data, JSON_THROW_ON_ERROR)) > 65536) {
            throw new \InvalidArgumentException('Form submission exceeds the supported size or has an invalid reference.');
        }
        $forms = $this->db->tableName('cert_forms');
        $form = $this->db->fetchOne(
            "SELECT id, field_schema_json FROM {$forms}
             WHERE id = :form_id AND tenant_id = :tenant_id AND visibility = 'public' AND is_active = 1",
            ['form_id' => $formId, 'tenant_id' => $tenantId]
        );
        if ($form === null) {
            throw new \InvalidArgumentException('The active form was not found for this tenant.');
        }
        $schema = json_decode((string)$form['field_schema_json'], true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($schema)) {
            throw new \RuntimeException('The public form configuration is invalid.');
        }
        $allowedFields = [];
        foreach ($schema as $field) {
            $name = (string)($field['name'] ?? '');
            $allowedFields[$name] = $field;
            if (!array_key_exists($name, $data) && !empty($field['required'])) {
                throw new \InvalidArgumentException('A required form field is missing.');
            }
            if (!array_key_exists($name, $data)) {
                $data[$name] = '';
            }
        }
        foreach ($data as $name => $value) {
            if (!isset($allowedFields[$name]) || !is_scalar($value)) {
                throw new \InvalidArgumentException('The submission contains an unknown or invalid field.');
            }
            $value = trim((string)$value);
            if (strlen($value) > 2000) {
                throw new \InvalidArgumentException('A submitted form field is too long.');
            }
            $type = $allowedFields[$name]['type'] ?? 'text';
            if ($value !== '' && $type === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                throw new \InvalidArgumentException('Enter a valid email address.');
            }
            if ($value !== '' && $type === 'date') {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
                if ($date === false || $date->format('Y-m-d') !== $value) {
                    throw new \InvalidArgumentException('Enter a valid date in YYYY-MM-DD format.');
                }
            }
            if ($value !== '' && $type === 'enum'
                && !in_array($value, $allowedFields[$name]['options'] ?? [], true)) {
                throw new \InvalidArgumentException('Choose an available option for ' . ($allowedFields[$name]['label'] ?? $name) . '.');
            }
            $data[$name] = $value;
        }
        foreach ($schema as $field) {
            $name = (string)$field['name'];
            if (!empty($field['required']) && trim((string)($data[$name] ?? '')) === '') {
                throw new \InvalidArgumentException('A required form field cannot be empty.');
            }
        }
        $table = $this->db->tableName('cert_form_submissions');
        $this->db->execute(
            "INSERT INTO {$table} (form_id, tenant_id, submitter_ip, payload_json, status, created_at)
             VALUES (:fid, :tid, :ip, :payload, 'pending', CURRENT_TIMESTAMP)",
            [
                'fid' => $formId,
                'tid' => $tenantId,
                'ip' => $ip,
                'payload' => json_encode($data, JSON_THROW_ON_ERROR),
            ]
        );
        return $this->db->lastInsertId();
    }

    public function getPublicFormByKey(string $formKey): ?array
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $formKey)) {
            return null;
        }
        $forms = $this->db->tableName('cert_forms');
        $tenants = $this->db->tableName('cert_tenants');
        return $this->db->fetchOne(
            "SELECT f.id, f.tenant_id, f.form_key, f.title, f.template_id, f.field_mapping_json,
                    f.field_schema_json, f.issue_mode, f.requires_approval, t.slug AS tenant_slug,
                    t.display_name AS tenant_name, t.status AS tenant_status
             FROM {$forms} f
             JOIN {$tenants} t ON t.id = f.tenant_id
             WHERE f.form_key = :form_key AND f.visibility = 'public'
               AND f.is_active = 1 AND t.status = 'active'",
            ['form_key' => $formKey]
        );
    }

    public function listTenantForms(int $tenantId): array
    {
        $forms = $this->db->tableName('cert_forms');
        return $this->db->fetchAll(
            "SELECT id, form_key, title, template_id, field_schema_json, issue_mode, is_active, created_at
             FROM {$forms} WHERE tenant_id = :tenant_id ORDER BY id DESC",
            ['tenant_id' => $tenantId]
        );
    }

    public function getPublicForm(int $formId, int $tenantId): ?array
    {
        $forms = $this->db->tableName('cert_forms');
        return $this->db->fetchOne(
            "SELECT id, tenant_id, template_id, field_mapping_json, issue_mode, visibility
             FROM {$forms} WHERE id = :id AND tenant_id = :tenant_id AND is_active = 1",
            ['id' => $formId, 'tenant_id' => $tenantId]
        );
    }

    private static function formFieldType(string $variableType): string
    {
        return match ($variableType) {
            'email' => 'email',
            'date' => 'date',
            'enum' => 'enum',
            default => 'text',
        };
    }
}
