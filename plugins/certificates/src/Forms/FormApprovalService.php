<?php
declare(strict_types=1);

namespace SOI\Certificates\Forms;

use InvalidArgumentException;
use RuntimeException;
use SOI\Certificates\Audit\AuditService;
use SOI\Certificates\Authorization\Authorizer;
use SOI\Certificates\Authorization\Permissions;
use SOI\Certificates\Core\Database;
use SOI\Certificates\Issuance\Certificate;
use SOI\Certificates\Issuance\CertificateIssuanceService;
use SOI\Certificates\Issuance\IssuanceCommand;
use SOI\Certificates\Tenancy\TenantContext;

final class FormApprovalService
{
    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenantContext,
        private readonly Authorizer $authorizer,
        private readonly CertificateIssuanceService $issuanceService,
        private readonly AuditService $audit
    ) {
    }

    public function listPending(): array
    {
        $tenantId = $this->tenantContext->getTenantId();
        $submissions = $this->db->tableName('cert_form_submissions');
        $forms = $this->db->tableName('cert_forms');
        return $this->db->fetchAll(
            "SELECT s.id, s.form_id, s.payload_json, s.status, s.created_at, f.title AS form_title
             FROM {$submissions} s
             JOIN {$forms} f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
             WHERE s.tenant_id = :tenant_id AND s.status = 'pending'
               AND f.issue_mode IN ('approval', 'request_only')
             ORDER BY s.created_at ASC, s.id ASC",
            ['tenant_id' => $tenantId]
        );
    }

    public function approve(int $submissionId, ?int $actorUserId = null): Certificate
    {
        $this->authorizer->require(Permissions::CERTIFICATES_ISSUE);
        if ($submissionId < 1) {
            throw new InvalidArgumentException('A valid form submission is required.');
        }
        $tenantId = $this->tenantContext->getTenantId();
        $submissions = $this->db->tableName('cert_form_submissions');
        $forms = $this->db->tableName('cert_forms');
        $submission = $this->db->fetchOne(
            "SELECT s.*, f.template_id, f.field_mapping_json, f.requires_approval, f.issue_mode, f.title AS form_title
             FROM {$submissions} s
             JOIN {$forms} f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
             WHERE s.id = :submission_id AND s.tenant_id = :tenant_id",
            ['submission_id' => $submissionId, 'tenant_id' => $tenantId]
        );
        if ($submission === null || !in_array($submission['issue_mode'], ['approval', 'request_only'], true)) {
            throw new InvalidArgumentException('The reviewable submission was not found in this tenant.');
        }
        if ($submission['status'] === 'approved' && $submission['certificate_id'] !== null) {
            $existing = $this->issuanceService->findById((int)$submission['certificate_id']);
            if ($existing !== null) {
                return $existing;
            }
            throw new RuntimeException('The approved certificate record is unavailable for this submission.');
        }

        $staleProcessing = $this->db->getDriver() === 'sqlite'
            ? "reviewed_at <= datetime('now', '-10 minutes')"
            : 'reviewed_at <= DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE)';
        $claimed = $this->db->execute(
            "UPDATE {$submissions}
             SET status = 'processing', reviewed_by = :reviewed_by, reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :submission_id AND tenant_id = :tenant_id
               AND (status IN ('pending', 'failed')
                 OR (status = 'processing' AND {$staleProcessing}))",
            [
                'reviewed_by' => $actorUserId,
                'submission_id' => $submissionId,
                'tenant_id' => $tenantId,
            ]
        );
        if ($claimed !== 1) {
            throw new RuntimeException('This form submission is already being processed or has a final decision.');
        }

        try {
            $payload = json_decode((string)$submission['payload_json'], true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                throw new InvalidArgumentException('The stored form submission is invalid.');
            }
            [$recipientName, $recipientEmail, $variables] = $this->normalizeSubmission(
                $payload,
                (string)$submission['field_mapping_json']
            );
            $certificate = $this->issuanceService->issue(
                new IssuanceCommand(
                    (int)$submission['template_id'],
                    $recipientName,
                    $variables,
                    $recipientEmail,
                    date('Y-m-d'),
                    null,
                    'form',
                    'form_submission:' . $submissionId
                ),
                $actorUserId
            );
            $updated = $this->db->execute(
                "UPDATE {$submissions}
                 SET status = 'approved', certificate_id = :certificate_id, reviewed_by = :reviewed_by,
                     reviewed_at = CURRENT_TIMESTAMP, decision_reason = 'Approved and issued'
                 WHERE id = :submission_id AND tenant_id = :tenant_id AND status = 'processing'",
                [
                    'certificate_id' => $certificate->id,
                    'reviewed_by' => $actorUserId,
                    'submission_id' => $submissionId,
                    'tenant_id' => $tenantId,
                ]
            );
            if ($updated !== 1) {
                throw new RuntimeException('The issued certificate could not be linked to its form submission.');
            }
            $this->audit->log(
                $tenantId,
                'user',
                $actorUserId,
                'form.submission.approved',
                'form_submission',
                (string)$submissionId,
                ['certificate_id' => $certificate->id, 'form_id' => (int)$submission['form_id']]
            );
            return $certificate;
        } catch (\Throwable $e) {
            $this->db->execute(
                "UPDATE {$submissions}
                 SET status = 'failed', reviewed_by = :reviewed_by, reviewed_at = CURRENT_TIMESTAMP,
                     decision_reason = 'Approval failed; inspect application log'
                 WHERE id = :submission_id AND tenant_id = :tenant_id AND status = 'processing'",
                ['reviewed_by' => $actorUserId, 'submission_id' => $submissionId, 'tenant_id' => $tenantId]
            );
            $this->audit->log(
                $tenantId,
                'user',
                $actorUserId,
                'form.submission.approval_failed',
                'form_submission',
                (string)$submissionId,
                ['form_id' => (int)$submission['form_id']]
            );
            error_log('SOI form submission approval failed: ' . $e->getMessage());
            throw $e;
        }
    }

    public function reject(int $submissionId, string $reason, ?int $actorUserId = null): bool
    {
        $this->authorizer->require(Permissions::FORMS_MANAGE);
        $reason = trim($reason);
        if ($submissionId < 1 || $reason === '' || strlen($reason) > 1000) {
            throw new InvalidArgumentException('A submission and rejection reason between 1 and 1000 characters are required.');
        }
        $tenantId = $this->tenantContext->getTenantId();
        $submissions = $this->db->tableName('cert_form_submissions');
        $updated = $this->db->execute(
            "UPDATE {$submissions}
             SET status = 'rejected', reviewed_by = :reviewed_by, reviewed_at = CURRENT_TIMESTAMP,
                 decision_reason = :reason
             WHERE id = :submission_id AND tenant_id = :tenant_id AND status = 'pending'",
            [
                'reviewed_by' => $actorUserId,
                'reason' => $reason,
                'submission_id' => $submissionId,
                'tenant_id' => $tenantId,
            ]
        );
        if ($updated !== 1) {
            return false;
        }
        $this->audit->log(
            $tenantId,
            'user',
            $actorUserId,
            'form.submission.rejected',
            'form_submission',
            (string)$submissionId,
            ['reason' => $reason]
        );
        return true;
    }

    public function issuePublicSubmission(int $submissionId): Certificate
    {
        $tenantId = $this->tenantContext->getTenantId();
        if ($submissionId < 1 || $tenantId < 1) {
            throw new InvalidArgumentException('A valid public form submission is required.');
        }
        $submissions = $this->db->tableName('cert_form_submissions');
        $forms = $this->db->tableName('cert_forms');
        $submission = $this->db->fetchOne(
            "SELECT s.*, f.template_id, f.field_mapping_json, f.issue_mode, f.visibility
             FROM {$submissions} s
             JOIN {$forms} f ON f.id = s.form_id AND f.tenant_id = s.tenant_id
             WHERE s.id = :submission_id AND s.tenant_id = :tenant_id",
            ['submission_id' => $submissionId, 'tenant_id' => $tenantId]
        );
        if ($submission === null || $submission['visibility'] !== 'public' || $submission['issue_mode'] !== 'immediate') {
            throw new InvalidArgumentException('Immediate issuance is not enabled for this public form.');
        }
        if ($submission['status'] === 'approved' && $submission['certificate_id'] !== null) {
            $existing = $this->issuanceService->findById((int)$submission['certificate_id']);
            if ($existing !== null) {
                return $existing;
            }
            throw new RuntimeException('The previously issued certificate is unavailable.');
        }
        $claimed = $this->db->execute(
            "UPDATE {$submissions}
             SET status = 'processing', reviewed_at = CURRENT_TIMESTAMP
             WHERE id = :submission_id AND tenant_id = :tenant_id AND status = 'pending'",
            ['submission_id' => $submissionId, 'tenant_id' => $tenantId]
        );
        if ($claimed !== 1) {
            throw new RuntimeException('This public submission has already been processed.');
        }
        try {
            $payload = json_decode((string)$submission['payload_json'], true, 32, JSON_THROW_ON_ERROR);
            [$recipientName, $recipientEmail, $variables] = $this->normalizeSubmission(
                $payload,
                (string)$submission['field_mapping_json']
            );
            $certificate = $this->issuanceService->issueFromPublicForm(
                new IssuanceCommand(
                    (int)$submission['template_id'],
                    $recipientName,
                    $variables,
                    $recipientEmail,
                    date('Y-m-d'),
                    null,
                    'form',
                    'form_submission:' . $submissionId
                ),
                $tenantId
            );
            $this->db->execute(
                "UPDATE {$submissions}
                 SET status = 'approved', certificate_id = :certificate_id, reviewed_at = CURRENT_TIMESTAMP,
                     decision_reason = 'Issued automatically by public form policy'
                 WHERE id = :submission_id AND tenant_id = :tenant_id AND status = 'processing'",
                ['certificate_id' => $certificate->id, 'submission_id' => $submissionId, 'tenant_id' => $tenantId]
            );
            $this->audit->log(
                $tenantId,
                'system',
                null,
                'form.submission.issued',
                'form_submission',
                (string)$submissionId,
                ['certificate_id' => $certificate->id]
            );
            return $certificate;
        } catch (\Throwable $e) {
            $this->db->execute(
                "UPDATE {$submissions} SET status = 'failed', reviewed_at = CURRENT_TIMESTAMP,
                 decision_reason = 'Automatic issuance failed; inspect application log'
                 WHERE id = :submission_id AND tenant_id = :tenant_id AND status = 'processing'",
                ['submission_id' => $submissionId, 'tenant_id' => $tenantId]
            );
            error_log('SOI immediate public form issuance failed: ' . $e->getMessage());
            throw $e;
        }
    }

    private function normalizeSubmission(array $payload, string $mappingJson): array
    {
        $mapping = json_decode($mappingJson, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($mapping)) {
            throw new InvalidArgumentException('The form field mapping is invalid.');
        }

        $recipientName = '';
        $recipientEmail = null;
        $variables = [];
        if ($mapping === []) {
            $recipientName = trim((string)($payload['recipient_name'] ?? ''));
            $recipientEmail = isset($payload['recipient_email']) ? trim((string)$payload['recipient_email']) : null;
            $variables = $payload;
        } else {
            foreach ($mapping as $sourceField => $targetVariable) {
                if (!is_string($sourceField) || !is_string($targetVariable)
                    || !array_key_exists($sourceField, $payload) || !is_scalar($payload[$sourceField])) {
                    throw new InvalidArgumentException('A mapped form field is missing or invalid.');
                }
                $value = (string)$payload[$sourceField];
                if ($targetVariable === 'recipient_name') {
                    $recipientName = trim($value);
                } elseif ($targetVariable === 'recipient_email') {
                    $recipientEmail = trim($value);
                } else {
                    $variables[$targetVariable] = $payload[$sourceField];
                }
            }
        }

        unset(
            $variables['recipient_name'],
            $variables['recipient_email'],
            $variables['certificate_number'],
            $variables['verification_url'],
            $variables['issue_date'],
            $variables['tenant_name']
        );
        if ($recipientName === '') {
            throw new InvalidArgumentException('The form submission must include a recipient name.');
        }
        if ($recipientEmail !== null && $recipientEmail !== ''
            && filter_var($recipientEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('The submitted recipient email is invalid.');
        }
        return [$recipientName, $recipientEmail === '' ? null : $recipientEmail, $variables];
    }
}
