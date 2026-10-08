<?php
declare(strict_types=1);

namespace SOI\Certificates\Issuance;

use Exception;
use SOI\Certificates\Audit\AuditService;
use SOI\Certificates\Authorization\Authorizer;
use SOI\Certificates\Authorization\Permissions;
use SOI\Certificates\Core\Database;
use SOI\Certificates\Rendering\CertificateRendererInterface;
use SOI\Certificates\Rendering\RenderRequest;
use SOI\Certificates\Rendering\RenderExceptionHandler;
use SOI\Certificates\Storage\ArtifactStorageService;
use SOI\Certificates\Storage\StorageAdapterInterface;
use SOI\Certificates\Templates\TemplateService;
use SOI\Certificates\Templates\VariableValidator;
use SOI\Certificates\Tenancy\TenantContext;
use SOI\Certificates\Verification\TokenGenerator;
use SOI\Certificates\Webhooks\WebhookService;

/**
 * THE SINGLE ISSUANCE SERVICE.
 * All manual, form, API, bulk, and scheduled triggers converge on this service.
 */
class CertificateIssuanceService
{
    protected Database $db;
    protected TenantContext $tenantContext;
    protected Authorizer $authorizer;
    protected TemplateService $templateService;
    protected CertificateRendererInterface $renderer;
    protected StorageAdapterInterface $storage;
    protected ArtifactStorageService $artifactStorage;
    protected AuditService $audit;
    protected string $baseUrl;
    protected TokenGenerator $tokenGenerator;
    protected VariableValidator $variableValidator;
    protected NumberGenerator $numberGenerator;
    protected RenderExceptionHandler $renderExceptionHandler;
    protected LifecycleManager $lifecycleManager;

    public function __construct(
        Database $db,
        TenantContext $tenantContext,
        Authorizer $authorizer,
        TemplateService $templateService,
        CertificateRendererInterface $renderer,
        StorageAdapterInterface $storage,
        AuditService $audit,
        string $baseUrl = ''
    ) {
        $this->db = $db;
        $this->tenantContext = $tenantContext;
        $this->authorizer = $authorizer;
        $this->templateService = $templateService;
        $this->renderer = $renderer;
        $this->storage = $storage;
        $this->artifactStorage = new ArtifactStorageService($storage);
        $this->audit = $audit;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->tokenGenerator = new TokenGenerator();
        $this->variableValidator = new VariableValidator();
        $this->numberGenerator = new NumberGenerator($db);
        $this->renderExceptionHandler = new RenderExceptionHandler();
        $this->lifecycleManager = new LifecycleManager($db, $tenantContext, $authorizer, $audit);
    }

    public function issue(IssuanceCommand $cmd, ?int $actorUserId = null): Certificate
    {
        return $this->issueInternal($cmd, $actorUserId, null, null);
    }

    public function issueFromPublicForm(IssuanceCommand $cmd, int $tenantId): Certificate
    {
        if ($tenantId < 1 || $this->tenantContext->getTenantId() !== $tenantId
            || $cmd->sourceType !== 'form'
            || !preg_match('/^form_submission:[1-9][0-9]*$/', (string)$cmd->idempotencyKey)) {
            throw new \InvalidArgumentException('Public form issuance context is invalid.');
        }
        return $this->issueInternal($cmd, null, null, null, true);
    }

    public function replace(
        int $certificateId,
        IssuanceCommand $replacementCommand,
        string $reason,
        ?int $actorUserId = null
    ): Certificate
    {
        $this->authorizer->require(Permissions::CERTIFICATES_REPLACE);
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 1000) {
            throw new \InvalidArgumentException('A replacement reason between 1 and 1000 characters is required.');
        }
        $existing = $this->findById($certificateId);
        if ($existing === null || $existing->status !== 'issued') {
            throw new \InvalidArgumentException('Only an issued certificate in the active tenant can be replaced.');
        }
        return $this->issueInternal($replacementCommand, $actorUserId, $existing->id, $reason);
    }

    private function issueInternal(
        IssuanceCommand $cmd,
        ?int $actorUserId,
        ?int $replacesCertificateId,
        ?string $replacementReason,
        bool $trustedPublicForm = false
    ): Certificate
    {
        // 1. Authorization check
        if (!$trustedPublicForm) {
            $this->authorizer->require(Permissions::CERTIFICATES_ISSUE);
        }

        // 2. Validate tenant status
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant || !$tenant->isActive()) {
            throw new Exception("Tenant is not active or suspended.");
        }
        $tenantId = $tenant->id;
        $sourceReference = $cmd->idempotencyKey;
        $sourceFingerprint = null;
        if ($sourceReference !== null) {
            $sourceReference = trim($sourceReference);
            if (!preg_match('/^[A-Za-z0-9:_-]{1,128}$/', $sourceReference)) {
                throw new \InvalidArgumentException('The issuance idempotency key is invalid.');
            }
            $sourceFingerprint = self::commandFingerprint($cmd);
            $existing = $this->findBySourceReference($tenantId, $sourceReference);
            if ($existing !== null) {
                if (!hash_equals((string)$existing['source_fingerprint'], $sourceFingerprint)) {
                    throw new \InvalidArgumentException('The issuance idempotency key was already used for a different command.');
                }
                return new Certificate($existing);
            }
        }

        // 3. Load immutable published template version
        $template = $this->templateService->findById($cmd->templateId);
        if (!$template || !$template->isPublished()) {
            throw new Exception("The requested template is not published or does not exist.");
        }
        $publishedVersion = $this->templateService->findVersionById($template->publishedVersionId);
        if (!$publishedVersion) {
            throw new Exception("Published template version data is missing.");
        }

        // 4. Validate recipient name
        if (empty($cmd->recipientName)) {
            throw new Exception("Recipient name is required.");
        }
        $issueDate = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$cmd->issueDate);
        if ($issueDate === false || $issueDate->format('Y-m-d') !== $cmd->issueDate) {
            throw new Exception('Issue date must be a valid calendar date in YYYY-MM-DD format.');
        }

        // 5. Generate secure verification token and number transactionally
        $verificationToken = $this->tokenGenerator->generate();
        $verificationTokenHash = $this->tokenGenerator->hash($verificationToken);
        $storedArtifactPath = null;

        $this->db->beginTransaction();

        try {
            // Allocate sequence transactionally
            $year = (int)$issueDate->format('Y');
            $cTable = $this->db->tableName('cert_certificates');
            $certificateNumber = $this->numberGenerator->next($tenantId, $year);

            // Construct payload snapshot
            $payload = array_merge($cmd->variables, [
                'recipient_name' => $cmd->recipientName,
                'issue_date' => $cmd->issueDate,
            ]);
            if ($cmd->recipientEmail !== null) {
                $payload['recipient_email'] = $cmd->recipientEmail;
            }

            // Construct verification URL
            $verificationUrl = $this->baseUrl . '/verify/' . $verificationToken;
            $payload['certificate_number'] = $certificateNumber;
            $payload['verification_url'] = $verificationUrl;
            $this->variableValidator->validateValues($publishedVersion->variableSchema, $payload);

            // 6. Deterministic Local Render
            $renderRequest = new RenderRequest(
                $publishedVersion,
                $payload,
                $certificateNumber,
                $verificationUrl,
                $tenant->displayName
            );

            $renderResult = $this->renderExceptionHandler->render(
                fn() => $this->renderer->render($renderRequest)
            );

            // 7. Store artifact in tenant-isolated local storage
            $month = date('m');
            $relPath = "certificates/{$tenant->slug}/{$year}/{$month}/{$certificateNumber}.pdf";
            $storedHash = $this->artifactStorage->storeVerified($relPath, $renderResult->pdfBytes);
            $storedArtifactPath = $relPath;

            // 8. Commit certificate record
            $this->db->execute(
                "INSERT INTO {$cTable} 
                (tenant_id, certificate_number, verification_token, verification_token_hash, template_id, template_version_id,
                 status, recipient_name, recipient_email, payload_json, file_path, file_sha256, issued_at, expires_at,
                 replaces_certificate_id, source_reference, source_fingerprint,
                 created_by_type, created_by_id, source_type, created_at, updated_at)
                VALUES 
                (:tid, :num, :token, :thash, :tpl_id, :ver_id, 'issued', :rname, :remail, :payload, :fpath, :fhash,
                 CURRENT_TIMESTAMP, :expires, :replaces_id, :source_reference, :source_fingerprint,
                 'user', :actor, :src, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [
                    'tid' => $tenantId,
                    'num' => $certificateNumber,
                    'token' => '',
                    'thash' => $verificationTokenHash,
                    'tpl_id' => $template->id,
                    'ver_id' => $publishedVersion->id,
                    'rname' => $cmd->recipientName,
                    'remail' => $cmd->recipientEmail,
                    'payload' => json_encode($payload),
                    'fpath' => $relPath,
                    'fhash' => $storedHash,
                    'expires' => $cmd->expiresAt,
                    'replaces_id' => $replacesCertificateId,
                    'source_reference' => $sourceReference,
                    'source_fingerprint' => $sourceFingerprint,
                    'actor' => $actorUserId,
                    'src' => $cmd->sourceType,
                ]
            );

            $certId = $this->db->lastInsertId();

            // Record initial lifecycle event
            $eTable = $this->db->tableName('cert_certificate_events');
            $this->db->execute(
                "INSERT INTO {$eTable} (certificate_id, tenant_id, from_status, to_status, reason, actor_id, created_at)
                 VALUES (:cid, :tid, 'pending', 'issued', 'Initial issuance', :actor, CURRENT_TIMESTAMP)",
                ['cid' => $certId, 'tid' => $tenantId, 'actor' => $actorUserId]
            );

            if ($replacesCertificateId !== null
                && !$this->lifecycleManager->markReplaced(
                    $replacesCertificateId,
                    $certId,
                    (string)$replacementReason,
                    $actorUserId
                )) {
                throw new \InvalidArgumentException('The original certificate changed state before it could be replaced.');
            }

            // Record append-only audit log
            $this->audit->log(
                $tenantId,
                'user',
                $actorUserId,
                'certificate.issued',
                'certificate',
                (string)$certId,
                [
                    'certificate_number' => $certificateNumber,
                    'template_id' => $template->id,
                    'version_id' => $publishedVersion->id,
                    'source_type' => $cmd->sourceType,
                ]
            );

            $this->db->commit();
            $storedArtifactPath = null;
            try {
                (new WebhookService($this->db))->queueEvent(
                    $tenantId,
                    'certificate.issued',
                    [
                        'certificate_id' => $certId,
                        'certificate_number' => $certificateNumber,
                        'template_id' => $template->id,
                        'status' => 'issued',
                    ]
                );
            } catch (\Throwable $webhookError) {
                error_log('SOI issuance webhook event could not be queued: ' . $webhookError->getMessage());
            }

            $certificate = $this->findById($certId);
            if ($certificate === null) {
                throw new Exception('Issued certificate could not be reloaded.');
            }
            $certificate->verificationToken = $verificationToken;
            return $certificate;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            if ($storedArtifactPath !== null) {
                try {
                    if (!$this->artifactStorage->delete($storedArtifactPath)) {
                        error_log('SOI orphaned certificate artifact cleanup could not remove ' . $storedArtifactPath);
                    }
                } catch (\Throwable $cleanupError) {
                    error_log('SOI orphaned certificate artifact cleanup failed: ' . $cleanupError->getMessage());
                }
            }
            if ($sourceReference !== null) {
                $existing = $this->findBySourceReference($tenantId, $sourceReference);
                if ($existing !== null
                    && hash_equals((string)$existing['source_fingerprint'], (string)$sourceFingerprint)) {
                    return new Certificate($existing);
                }
                if ($existing !== null) {
                    throw new \InvalidArgumentException('The issuance idempotency key was already used for a different command.');
                }
            }
            throw $e;
        }
    }

    public function findById(int $id): ?Certificate
    {
        $tenantId = $this->tenantContext->getTenantId();
        $table = $this->db->tableName('cert_certificates');
        $row = $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE id = :id AND tenant_id = :tid",
            ['id' => $id, 'tid' => $tenantId]
        );
        return $row ? new Certificate($row) : null;
    }

    public function findByNumber(string $number): ?Certificate
    {
        $tenantId = $this->tenantContext->getTenantId();
        $table = $this->db->tableName('cert_certificates');
        $row = $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE certificate_number = :num AND tenant_id = :tid",
            ['num' => $number, 'tid' => $tenantId]
        );
        return $row ? new Certificate($row) : null;
    }

    public function listTenantCertificates(int $limit = 50, int $offset = 0): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $table = $this->db->tableName('cert_certificates');
        $rows = $this->db->fetchAll(
            "SELECT * FROM {$table} WHERE tenant_id = :tid ORDER BY id DESC LIMIT :lim OFFSET :off",
            ['tid' => $tenantId, 'lim' => $limit, 'off' => $offset]
        );
        return array_map(fn($r) => new Certificate($r), $rows);
    }

    public function searchTenantCertificates(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $table = $this->db->tableName('cert_certificates');
        $conditions = ['tenant_id = :tenant_id'];
        $params = ['tenant_id' => $tenantId];
        $number = trim((string)($filters['certificate_number'] ?? ''));
        if ($number !== '') {
            $conditions[] = 'certificate_number LIKE :certificate_number';
            $params['certificate_number'] = '%' . str_replace(['%', '_'], '', substr($number, 0, 64)) . '%';
        }
        $recipient = trim((string)($filters['recipient'] ?? ''));
        if ($recipient !== '') {
            $conditions[] = 'recipient_name LIKE :recipient';
            $params['recipient'] = '%' . str_replace(['%', '_'], '', substr($recipient, 0, 128)) . '%';
        }
        $status = (string)($filters['status'] ?? '');
        if ($status !== '') {
            if (!in_array($status, ['issued', 'revoked', 'replaced', 'expired', 'cancelled'], true)) {
                throw new \InvalidArgumentException('Certificate status filter is invalid.');
            }
            $conditions[] = 'status = :status';
            $params['status'] = $status;
        }
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            if (empty($filters[$key])) {
                continue;
            }
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$filters[$key]);
            if ($date === false || $date->format('Y-m-d') !== $filters[$key]) {
                throw new \InvalidArgumentException('Date filters must be valid YYYY-MM-DD calendar dates.');
            }
            $conditions[] = 'DATE(issued_at) ' . $operator . ' :' . $key;
            $params[$key] = $filters[$key];
        }
        if (isset($params['from'], $params['to']) && $params['from'] > $params['to']) {
            throw new \InvalidArgumentException('The start date must not be after the end date.');
        }
        $limit = max(1, min(250, $limit));
        $offset = max(0, $offset);
        $rows = $this->db->fetchAll(
            "SELECT * FROM {$table} WHERE " . implode(' AND ', $conditions) . "
             ORDER BY issued_at DESC, id DESC LIMIT {$limit} OFFSET {$offset}",
            $params
        );
        return array_map(static fn(array $row): Certificate => new Certificate($row), $rows);
    }

    public function listCertificateEvents(int $certificateId): array
    {
        $table = $this->db->tableName('cert_certificate_events');
        return $this->db->fetchAll(
            "SELECT id, from_status, to_status, reason, actor_id, created_at
             FROM {$table} WHERE certificate_id = :certificate_id AND tenant_id = :tenant_id
             ORDER BY id DESC",
            ['certificate_id' => $certificateId, 'tenant_id' => $this->tenantContext->getTenantId()]
        );
    }

    public function revoke(int $certificateId, string $reason, ?int $actorUserId = null): bool
    {
        return $this->lifecycleManager->revoke($certificateId, $reason, $actorUserId);
    }

    public function cancel(int $certificateId, string $reason, ?int $actorUserId = null): bool
    {
        return $this->lifecycleManager->cancel($certificateId, $reason, $actorUserId);
    }

    public function expireDue(int $limit = 100, ?int $actorUserId = null): int
    {
        return $this->lifecycleManager->expireDue($limit, $actorUserId);
    }

    private function findBySourceReference(int $tenantId, string $sourceReference): ?array
    {
        $table = $this->db->tableName('cert_certificates');
        return $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE tenant_id = :tenant_id AND source_reference = :source_reference",
            ['tenant_id' => $tenantId, 'source_reference' => $sourceReference]
        );
    }

    private static function commandFingerprint(IssuanceCommand $cmd): string
    {
        $variables = $cmd->variables;
        $sort = static function (array &$value) use (&$sort): void {
            foreach ($value as &$item) {
                if (is_array($item)) {
                    $sort($item);
                }
            }
            unset($item);
            if (!array_is_list($value)) {
                ksort($value);
            }
        };
        $sort($variables);
        return hash('sha256', json_encode([
            'template_id' => $cmd->templateId,
            'recipient_name' => $cmd->recipientName,
            'variables' => $variables,
            'recipient_email' => $cmd->recipientEmail,
            'issue_date' => $cmd->issueDate,
            'expires_at' => $cmd->expiresAt,
            'source_type' => $cmd->sourceType,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
