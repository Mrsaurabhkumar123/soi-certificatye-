<?php
declare(strict_types=1);

namespace SOI\Certificates\Verification;

use SOI\Certificates\Core\Database;

/**
 * Authoritative verification service resolving tokens to tamper-proof registry state.
 */
class VerificationService
{
    protected Database $db;
    protected VerificationPolicy $policy;

    public function __construct(Database $db, ?VerificationPolicy $policy = null)
    {
        $this->db = $db;
        $this->policy = $policy ?? new VerificationPolicy($db);
    }

    public function verify(string $token, ?string $pin = null, bool $authenticated = false): VerificationResult
    {
        $result = new VerificationResult();
        $token = trim($token);
        if (empty($token)) {
            $result->message = "Invalid or empty verification token.";
            return $result;
        }

        $tokenHash = hash('sha256', $token);
        $cTable = $this->db->tableName('cert_certificates');
        $tTable = $this->db->tableName('cert_tenants');

        $row = $this->db->fetchOne(
            "SELECT c.*, t.display_name as tenant_name, t.status as tenant_status
             FROM {$cTable} c
             JOIN {$tTable} t ON c.tenant_id = t.id
             WHERE c.verification_token_hash = :hash",
            ['hash' => $tokenHash]
        );

        if (!$row) {
            $result->message = "No authoritative record exists matching this verification code.";
            return $result;
        }

        // Evaluate status
        if ($row['tenant_status'] === 'suspended') {
            $result->status = 'disabled';
            $result->message = "Verification is currently unavailable for this organization.";
            return $result;
        }

        $tenantId = (int)$row['tenant_id'];
        $policy = $this->policy->getForTenant($tenantId);
        if ($policy['mode'] === 'disabled') {
            $result->status = 'disabled';
            $result->message = 'Verification is currently unavailable.';
            return $result;
        }
        if ($policy['mode'] === 'pin' && ($pin === null || $pin === '')) {
            $result->requiresPin = true;
            $result->message = 'Enter the verification PIN to continue.';
            return $result;
        }
        if ($policy['mode'] === 'authenticated' && !$authenticated) {
            $result->requiresAuthentication = true;
            $result->message = 'Sign in to view this verification record.';
            return $result;
        }
        if (!$this->policy->allows($tenantId, $pin, $authenticated)) {
            $result->message = 'This verification request could not be completed.';
            return $result;
        }

        $result->found = true;
        $result->certificateNumber = (string)$row['certificate_number'];
        $result->organizationName = (string)$row['tenant_name'];
        $result->recipientName = (string)$row['recipient_name'];
        $result->issueDate = (string)$row['issued_at'];
        $result->expiresAt = $row['expires_at'];

        $certStatus = strtolower($row['status']);
        if ($certStatus === 'revoked') {
            $result->status = 'revoked';
            $result->revocationReason = $row['revocation_reason'] ?? null;
            $result->message = "NOTICE: This certificate has been revoked by the issuing authority.";
            return $result;
        }
        if ($certStatus === 'replaced') {
            $result->status = 'replaced';
            $result->message = 'NOTICE: This certificate has been replaced.';
            return $result;
        }
        if (!in_array($certStatus, ['issued', 'valid'], true)) {
            $result->status = 'disabled';
            $result->message = 'This certificate is not currently valid.';
            return $result;
        }

        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            $result->status = 'expired';
            $result->message = "NOTICE: This certificate has expired.";
            return $result;
        }

        $result->status = 'valid';
        $result->message = "Verified Authentic: This certificate is valid and recorded in the official registry.";
        if ($policy['mode'] === 'masked') {
            $result->recipientName = self::maskName($result->recipientName);
        }
        $result->publicFields = [
            'Certificate Number' => $result->certificateNumber,
            'Recipient Name' => $result->recipientName,
            'Issuing Organization' => $result->organizationName,
            'Date Issued' => date('F j, Y', strtotime($result->issueDate)),
        ];

        return $result;
    }

    private static function maskName(string $name): string
    {
        $parts = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) {
            return '***';
        }
        return implode(' ', array_map(static function (string $part): string {
            if (function_exists('mb_substr') && function_exists('mb_strlen')) {
                return mb_substr($part, 0, 1) . str_repeat('*', max(2, mb_strlen($part) - 1));
            }
            return substr($part, 0, 1) . str_repeat('*', max(2, strlen($part) - 1));
        }, $parts));
    }
}
