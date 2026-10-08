<?php
declare(strict_types=1);

namespace SOI\Certificates\Audit;

use SOI\Certificates\Core\Database;

/**
 * Extended Audit Logger with explicit domain lifecycle helper methods.
 * Ensures consistent event keys, actor attribution, and sanitized metadata.
 */
class AuditLogger extends AuditService
{
    /**
     * Log certificate issuance lifecycle event.
     */
    public function logIssuance(
        int $tenantId,
        int $certificateId,
        string $certificateNumber,
        string $sourceType,
        ?int $actorId = null,
        string $actorType = 'user',
        array $extra = []
    ): void {
        $this->log(
            $tenantId,
            $actorType,
            $actorId,
            'certificate.issued',
            'certificate',
            (string)$certificateId,
            array_merge([
                'certificate_number' => $certificateNumber,
                'source_type' => $sourceType,
            ], $extra)
        );
    }

    /**
     * Log certificate revocation with mandatory reason.
     */
    public function logRevocation(
        int $tenantId,
        int $certificateId,
        string $reason,
        ?int $actorId = null,
        string $actorType = 'user',
        array $extra = []
    ): void {
        $this->log(
            $tenantId,
            $actorType,
            $actorId,
            'certificate.revoked',
            'certificate',
            (string)$certificateId,
            array_merge(['reason' => $reason], $extra)
        );
    }

    /**
     * Log certificate replacement with bidirectional reference linkage and reason.
     */
    public function logReplacement(
        int $tenantId,
        int $oldCertificateId,
        int $newCertificateId,
        string $reason,
        ?int $actorId = null,
        string $actorType = 'user',
        array $extra = []
    ): void {
        $this->log(
            $tenantId,
            $actorType,
            $actorId,
            'certificate.replaced',
            'certificate',
            (string)$oldCertificateId,
            array_merge([
                'reason' => $reason,
                'replaced_by_id' => $newCertificateId,
            ], $extra)
        );
    }

    /**
     * Log certificate expiration state change.
     */
    public function logExpiration(
        int $tenantId,
        int $certificateId,
        ?int $actorId = null,
        string $actorType = 'system',
        array $extra = []
    ): void {
        $this->log(
            $tenantId,
            $actorType,
            $actorId,
            'certificate.expired',
            'certificate',
            (string)$certificateId,
            $extra
        );
    }

    /**
     * Log pre-issuance pending certificate cancellation.
     */
    public function logCancellation(
        int $tenantId,
        int $certificateId,
        string $reason,
        ?int $actorId = null,
        string $actorType = 'user',
        array $extra = []
    ): void {
        $this->log(
            $tenantId,
            $actorType,
            $actorId,
            'certificate.cancelled',
            'certificate',
            (string)$certificateId,
            array_merge(['reason' => $reason], $extra)
        );
    }
}
