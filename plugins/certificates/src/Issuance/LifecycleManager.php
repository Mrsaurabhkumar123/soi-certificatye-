<?php
declare(strict_types=1);

namespace SOI\Certificates\Issuance;

use InvalidArgumentException;
use SOI\Certificates\Audit\AuditService;
use SOI\Certificates\Authorization\Authorizer;
use SOI\Certificates\Authorization\Permissions;
use SOI\Certificates\Core\Database;
use SOI\Certificates\Tenancy\TenantContext;
use SOI\Certificates\Webhooks\WebhookService;

final class LifecycleManager
{
    public function __construct(
        private readonly Database $db,
        private readonly TenantContext $tenantContext,
        private readonly Authorizer $authorizer,
        private readonly AuditService $audit
    ) {
    }

    public function revoke(int $certificateId, string $reason, ?int $actorUserId = null): bool
    {
        return $this->transitionIssued(
            $certificateId,
            'revoked',
            $reason,
            $actorUserId,
            Permissions::CERTIFICATES_REVOKE,
            'certificate.revoked'
        );
    }

    public function cancel(int $certificateId, string $reason, ?int $actorUserId = null): bool
    {
        $this->authorizer->require(Permissions::CERTIFICATES_CANCEL);
        $reason = $this->requireReason($reason);
        return $this->withTransaction(function () use ($certificateId, $reason, $actorUserId): bool {
            $tenantId = $this->tenantContext->getTenantId();
            $certificates = $this->db->tableName('cert_certificates');
            $certificate = $this->lockCertificate($certificates, $certificateId, $tenantId);
            if ($certificate === null || $certificate['status'] !== 'pending') {
                return false;
            }
            return $this->persistTransition(
                $certificate,
                'cancelled',
                $reason,
                $actorUserId,
                'certificate.cancelled'
            );
        });
    }

    public function expireDue(int $limit = 100, ?int $actorUserId = null): int
    {
        $this->authorizer->require(Permissions::SCHEDULES_MANAGE);
        $tenantId = $this->tenantContext->getTenantId();
        $limit = max(1, min(500, $limit));
        $certificates = $this->db->tableName('cert_certificates');
        $due = $this->db->fetchAll(
            "SELECT id FROM {$certificates}
             WHERE tenant_id = :tenant_id AND status = 'issued'
               AND expires_at IS NOT NULL AND expires_at <= CURRENT_TIMESTAMP
             ORDER BY expires_at ASC, id ASC LIMIT {$limit}",
            ['tenant_id' => $tenantId]
        );
        $expired = 0;
        foreach ($due as $row) {
            if ($this->transitionIssued(
                (int)$row['id'],
                'expired',
                'Certificate expiration date reached.',
                $actorUserId,
                Permissions::SCHEDULES_MANAGE,
                'certificate.expired'
            )) {
                $expired++;
            }
        }
        return $expired;
    }

    public function markReplaced(
        int $oldCertificateId,
        int $newCertificateId,
        string $reason,
        ?int $actorUserId = null
    ): bool {
        $this->authorizer->require(Permissions::CERTIFICATES_REPLACE);
        $reason = $this->requireReason($reason);
        if ($oldCertificateId < 1 || $newCertificateId < 1 || $oldCertificateId === $newCertificateId) {
            throw new InvalidArgumentException('Replacement certificate references are invalid.');
        }

        return $this->withTransaction(function () use ($oldCertificateId, $newCertificateId, $reason, $actorUserId): bool {
            $tenantId = $this->tenantContext->getTenantId();
            $certificates = $this->db->tableName('cert_certificates');
            $newCertificate = $this->db->fetchOne(
                "SELECT id FROM {$certificates}
                 WHERE id = :new_id AND tenant_id = :tenant_id AND status = 'issued'
                   AND replaces_certificate_id = :old_id",
                ['new_id' => $newCertificateId, 'tenant_id' => $tenantId, 'old_id' => $oldCertificateId]
            );
            if ($newCertificate === null) {
                throw new InvalidArgumentException('The replacement certificate must be issued in the active tenant first.');
            }
            $oldCertificate = $this->lockCertificate($certificates, $oldCertificateId, $tenantId);
            if ($oldCertificate === null || $oldCertificate['status'] !== 'issued') {
                return false;
            }
            $updated = $this->db->execute(
                "UPDATE {$certificates}
                 SET status = 'replaced', replaced_by_certificate_id = :new_id, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :old_id AND tenant_id = :tenant_id AND status = 'issued'",
                ['new_id' => $newCertificateId, 'old_id' => $oldCertificateId, 'tenant_id' => $tenantId]
            );
            if ($updated !== 1) {
                return false;
            }
            $this->recordEvent($oldCertificate, 'replaced', $reason, $actorUserId);
            $this->audit->log(
                $tenantId,
                'user',
                $actorUserId,
                'certificate.replaced',
                'certificate',
                (string)$oldCertificateId,
                ['replacement_certificate_id' => $newCertificateId, 'reason' => $reason]
            );
            $this->queueWebhookSafely(
                $tenantId,
                'certificate.replaced',
                ['certificate_id' => $oldCertificateId, 'replacement_certificate_id' => $newCertificateId]
            );
            return true;
        });
    }

    private function transitionIssued(
        int $certificateId,
        string $toStatus,
        string $reason,
        ?int $actorUserId,
        string $permission,
        string $eventKey
    ): bool {
        $this->authorizer->require($permission);
        $reason = $this->requireReason($reason);
        return $this->withTransaction(function () use ($certificateId, $toStatus, $reason, $actorUserId, $eventKey): bool {
            $tenantId = $this->tenantContext->getTenantId();
            $certificates = $this->db->tableName('cert_certificates');
            $certificate = $this->lockCertificate($certificates, $certificateId, $tenantId);
            if ($certificate === null || $certificate['status'] !== 'issued') {
                return false;
            }
            return $this->persistTransition($certificate, $toStatus, $reason, $actorUserId, $eventKey);
        });
    }

    private function persistTransition(
        array $certificate,
        string $toStatus,
        string $reason,
        ?int $actorUserId,
        string $eventKey
    ): bool {
        $certificates = $this->db->tableName('cert_certificates');
        $updated = $this->db->execute(
            "UPDATE {$certificates} SET status = :status, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND tenant_id = :tenant_id AND status = :from_status",
            [
                'status' => $toStatus,
                'id' => (int)$certificate['id'],
                'tenant_id' => (int)$certificate['tenant_id'],
                'from_status' => (string)$certificate['status'],
            ]
        );
        if ($updated !== 1) {
            return false;
        }

        $this->recordEvent($certificate, $toStatus, $reason, $actorUserId);
        $this->audit->log(
            (int)$certificate['tenant_id'],
            'user',
            $actorUserId,
            $eventKey,
            'certificate',
            (string)$certificate['id'],
            ['from_status' => $certificate['status'], 'to_status' => $toStatus, 'reason' => $reason]
        );
        $this->queueWebhookSafely(
            (int)$certificate['tenant_id'],
            'certificate.' . $toStatus,
            ['certificate_id' => (int)$certificate['id'], 'status' => $toStatus]
        );
        return true;
    }

    private function queueWebhookSafely(int $tenantId, string $eventKey, array $payload): void
    {
        if (!in_array($eventKey, ['certificate.revoked', 'certificate.replaced', 'certificate.expired'], true)) {
            return;
        }
        try {
            (new WebhookService($this->db))->queueEvent($tenantId, $eventKey, $payload);
        } catch (\Throwable $e) {
            error_log('SOI webhook event queue failed: ' . $e->getMessage());
        }
    }

    private function recordEvent(array $certificate, string $toStatus, string $reason, ?int $actorUserId): void
    {
        $events = $this->db->tableName('cert_certificate_events');
        $this->db->execute(
            "INSERT INTO {$events} (certificate_id, tenant_id, from_status, to_status, reason, actor_id, created_at)
             VALUES (:certificate_id, :tenant_id, :from_status, :to_status, :reason, :actor_id, CURRENT_TIMESTAMP)",
            [
                'certificate_id' => (int)$certificate['id'],
                'tenant_id' => (int)$certificate['tenant_id'],
                'from_status' => (string)$certificate['status'],
                'to_status' => $toStatus,
                'reason' => $reason,
                'actor_id' => $actorUserId,
            ]
        );
    }

    private function lockCertificate(string $table, int $id, int $tenantId): ?array
    {
        if ($id < 1) {
            return null;
        }
        $lockSuffix = $this->db->getDriver() === 'sqlite' ? '' : ' FOR UPDATE';
        return $this->db->fetchOne(
            "SELECT * FROM {$table} WHERE id = :id AND tenant_id = :tenant_id{$lockSuffix}",
            ['id' => $id, 'tenant_id' => $tenantId]
        );
    }

    private function withTransaction(callable $callback): mixed
    {
        $pdo = $this->db->getPdo();
        $ownsTransaction = !$pdo->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $result = $callback();
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $result;
        } catch (\Throwable $e) {
            if ($ownsTransaction) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function requireReason(string $reason): string
    {
        $reason = trim($reason);
        if ($reason === '' || strlen($reason) > 1000) {
            throw new InvalidArgumentException('A lifecycle reason between 1 and 1000 characters is required.');
        }
        return $reason;
    }
}
