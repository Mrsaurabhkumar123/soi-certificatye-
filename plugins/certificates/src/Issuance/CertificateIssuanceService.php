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
use SOI\Certificates\Storage\StorageAdapterInterface;
use SOI\Certificates\Templates\TemplateService;
use SOI\Certificates\Tenancy\TenantContext;

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
    protected AuditService $audit;
    protected string $baseUrl;

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
        $this->audit = $audit;
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function issue(IssuanceCommand $cmd, ?int $actorUserId = null): Certificate
    {
        // 1. Authorization check
        $this->authorizer->require(Permissions::CERTIFICATES_ISSUE);

        // 2. Validate tenant status
        $tenant = $this->tenantContext->getTenant();
        if (!$tenant || !$tenant->isActive()) {
            throw new Exception("Tenant is not active or suspended.");
        }
        $tenantId = $tenant->id;

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

        // 5. Generate secure verification token and number transactionally
        $verificationToken = bin2hex(random_bytes(16));
        $verificationTokenHash = hash('sha256', $verificationToken);

        $this->db->beginTransaction();

        try {
            // Allocate sequence transactionally
            $year = date('Y');
            $cTable = $this->db->tableName('cert_certificates');
            $count = (int)$this->db->fetchValue(
                "SELECT COUNT(*) FROM {$cTable} WHERE tenant_id = :tid",
                ['tid' => $tenantId]
            );
            $nextSeq = $count + 1;
            $certificateNumber = sprintf("SOI-%s-%05d", $year, $nextSeq);

            // Construct payload snapshot
            $payload = array_merge($cmd->variables, [
                'recipient_name' => $cmd->recipientName,
                'issue_date' => $cmd->issueDate,
            ]);

            // Construct verification URL
            $verificationUrl = ($this->baseUrl ?: 'http://localhost:8000') . '/verify/' . $verificationToken;

            // 6. Deterministic Local Render
            $renderRequest = new RenderRequest(
                $publishedVersion,
                $payload,
                $certificateNumber,
                $verificationUrl,
                $tenant->displayName
            );

            $renderResult = $this->renderer->render($renderRequest);

            // 7. Store artifact in tenant-isolated local storage
            $month = date('m');
            $relPath = "certificates/{$tenant->slug}/{$year}/{$month}/{$certificateNumber}.pdf";
            $saved = $this->storage->put($relPath, $renderResult->pdfBytes);
            if (!$saved) {
                throw new Exception("Failed to persist certificate artifact to local storage.");
            }

            // 8. Commit certificate record
            $this->db->execute(
                "INSERT INTO {$cTable} 
                (tenant_id, certificate_number, verification_token, verification_token_hash, template_id, template_version_id,
                 status, recipient_name, recipient_email, payload_json, file_path, file_sha256, issued_at, expires_at,
                 created_by_type, created_by_id, source_type, created_at, updated_at)
                VALUES 
                (:tid, :num, :token, :thash, :tpl_id, :ver_id, 'issued', :rname, :remail, :payload, :fpath, :fhash,
                 datetime('now'), :expires, 'user', :actor, :src, datetime('now'), datetime('now'))",
                [
                    'tid' => $tenantId,
                    'num' => $certificateNumber,
                    'token' => $verificationToken,
                    'thash' => $verificationTokenHash,
                    'tpl_id' => $template->id,
                    'ver_id' => $publishedVersion->id,
                    'rname' => $cmd->recipientName,
                    'remail' => $cmd->recipientEmail,
                    'payload' => json_encode($payload),
                    'fpath' => $relPath,
                    'fhash' => $renderResult->sha256,
                    'expires' => $cmd->expiresAt,
                    'actor' => $actorUserId,
                    'src' => $cmd->sourceType,
                ]
            );

            $certId = $this->db->lastInsertId();

            // Record initial lifecycle event
            $eTable = $this->db->tableName('cert_certificate_events');
            $this->db->execute(
                "INSERT INTO {$eTable} (certificate_id, tenant_id, from_status, to_status, reason, actor_id, created_at)
                 VALUES (:cid, :tid, 'pending', 'issued', 'Initial issuance', :actor, datetime('now'))",
                ['cid' => $certId, 'tid' => $tenantId, 'actor' => $actorUserId]
            );

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

            return $this->findById($certId);
        } catch (\Throwable $e) {
            $this->db->rollBack();
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

    public function revoke(int $certificateId, string $reason, ?int $actorUserId = null): bool
    {
        $this->authorizer->require(Permissions::CERTIFICATES_REVOKE);
        $cert = $this->findById($certificateId);
        if (!$cert || $cert->isRevoked()) {
            return false;
        }

        $table = $this->db->tableName('cert_certificates');
        $this->db->execute(
            "UPDATE {$table} SET status = 'revoked', updated_at = datetime('now') WHERE id = :id AND tenant_id = :tid",
            ['id' => $cert->id, 'tid' => $cert->tenantId]
        );

        $eTable = $this->db->tableName('cert_certificate_events');
        $this->db->execute(
            "INSERT INTO {$eTable} (certificate_id, tenant_id, from_status, to_status, reason, actor_id, created_at)
             VALUES (:cid, :tid, :from, 'revoked', :reason, :actor, datetime('now'))",
            [
                'cid' => $cert->id,
                'tid' => $cert->tenantId,
                'from' => $cert->status,
                'reason' => $reason,
                'actor' => $actorUserId,
            ]
        );

        $this->audit->log($cert->tenantId, 'user', $actorUserId, 'certificate.revoked', 'certificate', (string)$cert->id, [
            'reason' => $reason,
        ]);

        return true;
    }
}
