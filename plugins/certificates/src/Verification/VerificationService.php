<?php
declare(strict_types=1);

namespace SOI\Certificates\Verification;

use SOI\Certificates\Core\Database;

class VerificationResult
{
    public bool $found = false;
    public string $status = 'not_found'; // 'valid', 'revoked', 'expired', 'replaced', 'disabled', 'not_found'
    public ?string $certificateNumber = null;
    public ?string $recipientName = null;
    public ?string $organizationName = null;
    public ?string $issueDate = null;
    public ?string $expiresAt = null;
    public ?string $revocationReason = null;
    public string $message = '';
    public array $publicFields = [];
}

/**
 * Authoritative verification service resolving tokens to tamper-proof registry state.
 */
class VerificationService
{
    protected Database $db;

    public function __construct(Database $db)
    {
        $this->db = $db;
    }

    public function verify(string $token, ?string $pin = null): VerificationResult
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

        $result->found = true;
        $result->certificateNumber = (string)$row['certificate_number'];
        $result->organizationName = (string)$row['tenant_name'];
        $result->recipientName = (string)$row['recipient_name'];
        $result->issueDate = (string)$row['issued_at'];
        $result->expiresAt = $row['expires_at'];

        // Evaluate status
        if ($row['tenant_status'] === 'suspended') {
            $result->status = 'disabled';
            $result->message = "Verification is currently unavailable for this organization.";
            return $result;
        }

        $certStatus = strtolower($row['status']);
        if ($certStatus === 'revoked') {
            $result->status = 'revoked';
            $result->message = "NOTICE: This certificate has been revoked by the issuing authority.";
            return $result;
        }

        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            $result->status = 'expired';
            $result->message = "NOTICE: This certificate has expired.";
            return $result;
        }

        $result->status = 'valid';
        $result->message = "Verified Authentic: This certificate is valid and recorded in the official registry.";
        $result->publicFields = [
            'Certificate Number' => $result->certificateNumber,
            'Recipient Name' => $result->recipientName,
            'Issuing Organization' => $result->organizationName,
            'Date Issued' => date('F j, Y', strtotime($result->issueDate)),
        ];

        return $result;
    }
}
