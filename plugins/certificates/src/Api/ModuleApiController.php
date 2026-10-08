<?php
declare(strict_types=1);

namespace SOI\Certificates\Api;

use SOI\Certificates\Core\CmsUserDirectory;
use SOI\Certificates\Core\Plugin;
use SOI\Certificates\Authorization\Role;
use SOI\Certificates\Authorization\Permissions;

/**
 * Controller providing one clear, versioned API contract per major module:
 * Templates, Certificates, Users, Roles, and Verification.
 * Strictly enforces tenant context, least-privilege RBAC, and retry-safe idempotency.
 */
class ModuleApiController
{
    protected Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    /* -------------------------------------------------------------
     * Module 1: Templates API (/api/v1/templates)
     * ------------------------------------------------------------- */

    public function listTemplates(): void
    {
        $this->jsonHeader();
        $filters = [
            'query' => $_GET['q'] ?? $_GET['query'] ?? null,
            'category' => $_GET['category'] ?? null,
            'status' => $_GET['status'] ?? null,
            'limit' => isset($_GET['limit']) ? (int)$_GET['limit'] : null,
            'offset' => isset($_GET['offset']) ? (int)$_GET['offset'] : null,
        ];
        $templates = $this->plugin->templateService->searchTemplates($filters);
        $this->respond([
            'data' => array_map(fn($t) => $t->toArray(), $templates),
            'total' => count($templates),
        ]);
    }

    public function createTemplate(): void
    {
        $this->jsonHeader();
        $requestId = $this->requestId();
        $data = $this->parseJsonBody($requestId);
        if ($data === null) {
            return;
        }

        $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
        $tenantId = $this->plugin->tenantContext->getTenantId();
        $clientId = (int)($this->plugin->getApiClientContext()['id'] ?? 0);
        $fingerprint = $this->plugin->idempotencyService->fingerprint($data, $clientId);

        if ($idempotencyKey !== '') {
            try {
                $replay = $this->plugin->idempotencyService->replayOrReserve($tenantId, $idempotencyKey, $fingerprint);
                if ($replay !== null) {
                    http_response_code($replay['status']);
                    echo $replay['body'];
                    return;
                }
            } catch (\InvalidArgumentException $e) {
                http_response_code(400);
                echo json_encode(['error' => ['code' => 'IDEMPOTENCY_INVALID', 'message' => $e->getMessage()], 'request_id' => $requestId]);
                return;
            } catch (\RuntimeException $e) {
                http_response_code(409);
                echo json_encode(['error' => ['code' => 'REQUEST_IN_PROGRESS', 'message' => $e->getMessage()], 'request_id' => $requestId]);
                return;
            }
        }

        $name = trim((string)($data['name'] ?? ''));
        $slug = trim((string)($data['slug'] ?? ''));
        $category = trim((string)($data['category'] ?? 'Certificates')) ?: 'Certificates';

        if ($name === '' || $slug === '') {
            $this->error(400, 'VALIDATION_FAILED', 'Template name and slug are required.', $requestId);
            return;
        }

        try {
            $template = $this->plugin->templateService->createTemplate($slug, $name, $category);
            $response = [
                'data' => $template->toArray(),
                'request_id' => $requestId,
            ];
            if ($idempotencyKey !== '') {
                $this->plugin->idempotencyService->complete($tenantId, $idempotencyKey, $fingerprint, 201, $response);
            }
            http_response_code(201);
            echo json_encode($response);
        } catch (\Throwable $e) {
            error_log('SOI API create template failed: ' . $e->getMessage());
            $this->error(422, 'CREATE_FAILED', 'The template could not be created.', $requestId);
        }
    }

    public function getTemplate(array $params): void
    {
        $this->jsonHeader();
        $id = (int)($params['id'] ?? 0);
        $template = $this->plugin->templateService->findById($id);
        if ($template === null) {
            $this->error(404, 'NOT_FOUND', 'Template not found.');
            return;
        }

        $versions = [];
        if ($template->publishedVersionId !== null) {
            $pv = $this->plugin->templateService->findVersionById($template->publishedVersionId);
            if ($pv !== null) {
                $versions['published'] = [
                    'version_number' => $pv->versionNumber,
                    'canonical_hash' => $pv->canonicalHash,
                    'published_at' => $pv->publishedAt,
                    'variable_schema' => $pv->variableSchema,
                ];
            }
        }
        if ($template->draftVersionId !== null) {
            $dv = $this->plugin->templateService->findVersionById($template->draftVersionId);
            if ($dv !== null) {
                $versions['draft'] = [
                    'version_number' => $dv->versionNumber,
                    'layout' => $dv->layout,
                    'variable_schema' => $dv->variableSchema,
                ];
            }
        }

        $res = $template->toArray();
        $res['versions'] = $versions;
        $this->respond(['data' => $res]);
    }

    public function updateTemplate(array $params): void
    {
        $this->jsonHeader();
        $id = (int)($params['id'] ?? 0);
        $template = $this->plugin->templateService->findById($id);
        if ($template === null) {
            $this->error(404, 'NOT_FOUND', 'Template not found.');
            return;
        }

        $data = $this->parseJsonBody();
        if ($data === null) {
            return;
        }

        try {
            if (isset($data['layout']) || isset($data['variable_schema'])) {
                $layout = is_array($data['layout'] ?? null) ? $data['layout'] : [];
                $schema = is_array($data['variable_schema'] ?? null) ? $data['variable_schema'] : [];
                $this->plugin->templateService->saveDraft($id, $layout, $schema);
            }

            if (isset($data['name']) || isset($data['category']) || isset($data['status'])) {
                $table = $this->plugin->db->tableName('cert_templates');
                $tenantId = $this->plugin->tenantContext->getTenantId();
                $updates = ['updated_at = :now'];
                $bind = ['now' => date('Y-m-d H:i:s'), 'id' => $id, 'tid' => $tenantId];

                if (!empty($data['name'])) {
                    $updates[] = 'name = :name';
                    $bind['name'] = trim((string)$data['name']);
                }
                if (isset($data['category'])) {
                    $updates[] = 'category = :category';
                    $bind['category'] = trim((string)$data['category']);
                }
                if (!empty($data['status']) && in_array($data['status'], ['draft', 'archived', 'superseded'], true)) {
                    $updates[] = 'status = :status';
                    $bind['status'] = $data['status'];
                }

                $setClause = implode(', ', $updates);
                $this->plugin->db->execute(
                    "UPDATE {$table} SET {$setClause} WHERE id = :id AND tenant_id = :tid",
                    $bind
                );
            }

            $updated = $this->plugin->templateService->findById($id);
            $this->respond(['data' => $updated?->toArray()]);
        } catch (\InvalidArgumentException $e) {
            $this->error(422, 'VALIDATION_FAILED', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI API update template failed: ' . $e->getMessage());
            $this->error(500, 'UPDATE_FAILED', 'The template could not be updated.');
        }
    }

    /* -------------------------------------------------------------
     * Module 2: Certificates API (/api/v1/certificates)
     * ------------------------------------------------------------- */

    public function getCertificate(array $params): void
    {
        $this->jsonHeader();
        $param = trim((string)($params['id'] ?? ''));
        $tenantId = $this->plugin->tenantContext->getTenantId();
        $cTable = $this->plugin->db->tableName('cert_certificates');

        $row = is_numeric($param)
            ? $this->plugin->db->fetchOne("SELECT * FROM {$cTable} WHERE id = :p AND tenant_id = :tid", ['p' => (int)$param, 'tid' => $tenantId])
            : $this->plugin->db->fetchOne("SELECT * FROM {$cTable} WHERE certificate_number = :p AND tenant_id = :tid", ['p' => $param, 'tid' => $tenantId]);

        if (!$row) {
            $this->error(404, 'NOT_FOUND', 'Certificate not found.');
            return;
        }

        $auditTable = $this->plugin->db->tableName('cert_audit_log');
        $timeline = $this->plugin->db->fetchAll(
            "SELECT event_key, created_at, metadata_json FROM {$auditTable}
             WHERE tenant_id = :tid AND target_type = 'certificate' AND target_id = :cid
             ORDER BY id ASC",
            ['tid' => $tenantId, 'cid' => (string)$row['id']]
        );

        $this->respond([
            'data' => [
                'id' => (int)$row['id'],
                'certificate_number' => $row['certificate_number'],
                'recipient_name' => $row['recipient_name'],
                'recipient_email' => $row['recipient_email'],
                'status' => $row['status'],
                'issue_date' => $row['issued_at'],
                'expires_at' => $row['expires_at'],
                'file_sha256' => $row['file_sha256'],
                'verification_url' => $this->plugin->router->url('/verify/' . ($row['verification_token'] ?? '')),
                'replaced_by_certificate_id' => $row['replaced_by_certificate_id'] ?? null,
                'replaces_certificate_id' => $row['replaces_certificate_id'] ?? null,
                'timeline' => array_map(fn($t) => [
                    'event' => $t['event_key'],
                    'time' => $t['created_at'],
                    'details' => json_decode((string)($t['metadata_json'] ?? '{}'), true),
                ], $timeline),
            ],
        ]);
    }

    /* -------------------------------------------------------------
     * Module 3: Users API (/api/v1/users)
     * ------------------------------------------------------------- */

    public function listUsers(): void
    {
        $this->jsonHeader();
        $tenantId = $this->plugin->tenantContext->getTenantId();
        $members = $this->plugin->tenantRepo->listMemberships($tenantId);
        $this->respond([
            'data' => array_map(fn($m) => [
                'user_id' => (int)$m['user_id'],
                'role_key' => $m['role_key'],
                'status' => $m['status'],
                'created_at' => $m['created_at'],
            ], $members),
        ]);
    }

    public function createUser(): void
    {
        $this->jsonHeader();
        $requestId = $this->requestId();
        $data = $this->parseJsonBody($requestId);
        if ($data === null) {
            return;
        }

        $userId = filter_var($data['user_id'] ?? null, FILTER_VALIDATE_INT);
        $roleKey = trim((string)($data['role_key'] ?? 'viewer'));
        $tenantId = $this->plugin->tenantContext->getTenantId();

        if ($userId === false || $userId === null || $userId < 1) {
            $this->error(400, 'INVALID_USER_ID', 'A valid CMS user ID is required.', $requestId);
            return;
        }

        $userDir = new CmsUserDirectory();
        if (!$userDir->userExists((int)$userId)) {
            $this->error(404, 'USER_NOT_FOUND', 'The CMS user account was not found.', $requestId);
            return;
        }

        try {
            $this->plugin->tenantRepo->addMembership($tenantId, (int)$userId, $roleKey);
            $this->plugin->audit->log(
                $tenantId,
                'api',
                null,
                'api.user.added',
                'membership',
                (string)$userId,
                ['role_key' => $roleKey]
            );
            http_response_code(201);
            $this->respond([
                'data' => [
                    'user_id' => (int)$userId,
                    'role_key' => $roleKey,
                    'status' => 'active',
                ],
                'request_id' => $requestId,
            ]);
        } catch (\Throwable $e) {
            error_log('SOI API add user failed: ' . $e->getMessage());
            $this->error(422, 'USER_ADD_FAILED', 'Could not add user to workspace.', $requestId);
        }
    }

    public function getUser(array $params): void
    {
        $this->jsonHeader();
        $userId = (int)($params['id'] ?? 0);
        $tenantId = $this->plugin->tenantContext->getTenantId();
        $member = $this->plugin->tenantRepo->resolveMembership($tenantId, $userId);

        if (!$member) {
            $this->error(404, 'NOT_FOUND', 'User membership not found.');
            return;
        }

        $this->respond([
            'data' => [
                'user_id' => (int)$member['user_id'],
                'role_key' => $member['role_key'],
                'status' => $member['status'],
                'created_at' => $member['created_at'],
            ],
        ]);
    }

    public function updateUser(array $params): void
    {
        $this->jsonHeader();
        $userId = (int)($params['id'] ?? 0);
        $tenantId = $this->plugin->tenantContext->getTenantId();
        $data = $this->parseJsonBody();
        if ($data === null) {
            return;
        }

        if (isset($data['role_key'])) {
            $roleKey = trim((string)$data['role_key']);
            if (!$this->plugin->tenantRepo->updateMembershipRole($tenantId, $userId, $roleKey)) {
                $this->error(404, 'NOT_FOUND', 'Active user membership not found.');
                return;
            }
        }

        if (isset($data['status']) && $data['status'] === 'deactivated') {
            if (!$this->plugin->tenantRepo->deactivateMembership($tenantId, $userId)) {
                $this->error(400, 'OPERATION_DENIED', 'Cannot deactivate this user (may be last active owner).');
                return;
            }
        }

        $member = $this->plugin->tenantRepo->resolveMembership($tenantId, $userId);
        $this->respond(['data' => $member]);
    }

    /* -------------------------------------------------------------
     * Module 4: Roles API (/api/v1/roles)
     * ------------------------------------------------------------- */

    public function listRoles(): void
    {
        $this->jsonHeader();
        $tenantId = $this->plugin->tenantContext->getTenantId();
        $rTable = $this->plugin->db->tableName('cert_roles');
        $customRoles = $this->plugin->db->fetchAll(
            "SELECT * FROM {$rTable} WHERE tenant_id = :tid OR is_system = 1",
            ['tid' => $tenantId]
        );

        $defaultPerms = Role::getDefaultRolePermissions();
        $roles = [
            ['role_key' => Role::TENANT_OWNER, 'name' => 'Tenant Owner', 'is_system' => true, 'permissions' => $defaultPerms[Role::TENANT_OWNER] ?? []],
            ['role_key' => Role::TENANT_ADMIN, 'name' => 'Tenant Administrator', 'is_system' => true, 'permissions' => $defaultPerms[Role::TENANT_ADMIN] ?? []],
            ['role_key' => Role::TEMPLATE_DESIGNER, 'name' => 'Template Designer', 'is_system' => true, 'permissions' => $defaultPerms[Role::TEMPLATE_DESIGNER] ?? []],
            ['role_key' => Role::ISSUER, 'name' => 'Issuer / Operator', 'is_system' => true, 'permissions' => $defaultPerms[Role::ISSUER] ?? []],
            ['role_key' => Role::VIEWER, 'name' => 'Viewer / Auditor', 'is_system' => true, 'permissions' => $defaultPerms[Role::VIEWER] ?? []],
        ];

        foreach ($customRoles as $cr) {
            if ($cr['is_system']) continue;
            $roles[] = [
                'id' => (int)$cr['id'],
                'role_key' => $cr['role_key'],
                'name' => $cr['name'],
                'is_system' => false,
                'permissions' => [],
            ];
        }

        $this->respond(['data' => $roles]);
    }

    public function createRole(): void
    {
        $this->jsonHeader();
        $requestId = $this->requestId();
        $data = $this->parseJsonBody($requestId);
        if ($data === null) {
            return;
        }

        $roleKey = trim((string)($data['role_key'] ?? ''));
        $name = trim((string)($data['name'] ?? ''));
        $perms = (array)($data['permissions'] ?? []);
        $tenantId = $this->plugin->tenantContext->getTenantId();

        if ($roleKey === '' || $name === '') {
            $this->error(400, 'VALIDATION_FAILED', 'Role key and name are required.', $requestId);
            return;
        }

        try {
            $rTable = $this->plugin->db->tableName('cert_roles');
            $pTable = $this->plugin->db->tableName('cert_role_permissions');

            $this->plugin->db->execute(
                "INSERT INTO {$rTable} (tenant_id, role_key, name, is_system, created_at)
                 VALUES (:tid, :rk, :name, 0, CURRENT_TIMESTAMP)",
                ['tid' => $tenantId, 'rk' => $roleKey, 'name' => $name]
            );
            $roleId = $this->plugin->db->lastInsertId();

            foreach ($perms as $p) {
                if (is_string($p) && $p !== '') {
                    $this->plugin->db->execute(
                        "INSERT INTO {$pTable} (role_id, permission_key) VALUES (:rid, :pk)",
                        ['rid' => $roleId, 'pk' => $p]
                    );
                }
            }

            http_response_code(201);
            $this->respond([
                'data' => [
                    'id' => $roleId,
                    'role_key' => $roleKey,
                    'name' => $name,
                    'permissions' => $perms,
                ],
                'request_id' => $requestId,
            ]);
        } catch (\Throwable $e) {
            error_log('SOI API create role failed: ' . $e->getMessage());
            $this->error(422, 'ROLE_CREATE_FAILED', 'Could not create custom role.', $requestId);
        }
    }

    public function updateRole(array $params): void
    {
        $this->jsonHeader();
        $roleId = (int)($params['id'] ?? 0);
        $tenantId = $this->plugin->tenantContext->getTenantId();
        $rTable = $this->plugin->db->tableName('cert_roles');
        $role = $this->plugin->db->fetchOne(
            "SELECT * FROM {$rTable} WHERE id = :id AND (tenant_id = :tid OR is_system = 0)",
            ['id' => $roleId, 'tid' => $tenantId]
        );

        if (!$role) {
            $this->error(404, 'NOT_FOUND', 'Role not found or system role cannot be modified.');
            return;
        }

        $data = $this->parseJsonBody();
        if ($data === null) {
            return;
        }

        if (isset($data['name'])) {
            $this->plugin->db->execute(
                "UPDATE {$rTable} SET name = :name WHERE id = :id",
                ['name' => trim((string)$data['name']), 'id' => $roleId]
            );
        }

        if (isset($data['permissions']) && is_array($data['permissions'])) {
            $pTable = $this->plugin->db->tableName('cert_role_permissions');
            $this->plugin->db->execute("DELETE FROM {$pTable} WHERE role_id = :id", ['id' => $roleId]);
            foreach ($data['permissions'] as $p) {
                if (is_string($p) && $p !== '') {
                    $this->plugin->db->execute(
                        "INSERT INTO {$pTable} (role_id, permission_key) VALUES (:rid, :pk)",
                        ['rid' => $roleId, 'pk' => $p]
                    );
                }
            }
        }

        $this->respond(['data' => ['id' => $roleId, 'status' => 'updated']]);
    }

    /* -------------------------------------------------------------
     * Module 5: Verification API (/api/v1/verification/{token})
     * ------------------------------------------------------------- */

    public function verify(array $params): void
    {
        $this->jsonHeader();
        $token = trim((string)($params['token'] ?? ''));
        $pin = $_GET['pin'] ?? null;
        $result = $this->plugin->verificationService->verify($token, $pin, false);

        if (!$result->found && $result->status === 'unverified') {
            http_response_code(404);
            $errData = [
                'valid' => false,
                'status' => 'not_found',
                'message' => $result->message,
            ];
            $this->respond([
                'valid' => false,
                'status' => 'not_found',
                'message' => $result->message,
                'data' => $errData,
            ]);
            return;
        }

        $data = [
            'valid' => $result->status === 'valid',
            'status' => $result->status,
            'certificate_number' => $result->certificateNumber,
            'recipient_name' => $result->recipientName,
            'organization_name' => $result->organizationName,
            'issue_date' => $result->issueDate,
            'expires_at' => $result->expiresAt,
            'requires_pin' => $result->requiresPin,
            'message' => $result->message,
        ];

        $this->respond([
            'valid' => $result->status === 'valid',
            'status' => $result->status,
            'certificate_number' => $result->certificateNumber,
            'recipient_name' => $result->recipientName,
            'organization_name' => $result->organizationName,
            'issue_date' => $result->issueDate,
            'expires_at' => $result->expiresAt,
            'requires_pin' => $result->requiresPin,
            'message' => $result->message,
            'data' => $data,
        ]);
    }

    /* -------------------------------------------------------------
     * Helper Utilities
     * ------------------------------------------------------------- */

    protected function jsonHeader(): void
    {
        header('Content-Type: application/json; charset=utf-8');
    }

    protected function requestId(): string
    {
        return 'req_' . bin2hex(random_bytes(8));
    }

    protected function parseJsonBody(?string $requestId = null): ?array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            $this->error(400, 'EMPTY_BODY', 'A JSON request body is required.', $requestId);
            return null;
        }
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) {
            $this->error(400, 'INVALID_JSON', 'Request payload must be a valid JSON object.', $requestId);
            return null;
        }
        return $data;
    }

    protected function respond(array $payload): void
    {
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function error(int $status, string $code, string $message, ?string $requestId = null): void
    {
        http_response_code($status);
        echo json_encode([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'request_id' => $requestId ?? $this->requestId(),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
