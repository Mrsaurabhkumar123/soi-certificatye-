<?php
declare(strict_types=1);

namespace SOI\Certificates\Issuance;

/**
 * Certificate entity representing an authoritative certificate record.
 */
class Certificate
{
    public int $id;
    public int $tenantId;
    public string $certificateNumber;
    public string $verificationToken;
    public string $verificationTokenHash;
    public int $templateId;
    public int $templateVersionId;
    public string $status; // 'issued', 'revoked', 'expired', 'replaced', 'cancelled'
    public string $recipientName;
    public ?string $recipientEmail;
    public array $payload = [];
    public string $filePath;
    public string $fileSha256;
    public string $issuedAt;
    public ?string $expiresAt;
    public string $createdByType;
    public ?int $createdById;
    public string $sourceType;

    public function __construct(array $data)
    {
        $this->id = (int)($data['id'] ?? 0);
        $this->tenantId = (int)($data['tenant_id'] ?? 0);
        $this->certificateNumber = (string)($data['certificate_number'] ?? '');
        $this->verificationToken = (string)($data['verification_token'] ?? '');
        $this->verificationTokenHash = (string)($data['verification_token_hash'] ?? '');
        $this->templateId = (int)($data['template_id'] ?? 0);
        $this->templateVersionId = (int)($data['template_version_id'] ?? 0);
        $this->status = (string)($data['status'] ?? 'issued');
        $this->recipientName = (string)($data['recipient_name'] ?? '');
        $this->recipientEmail = $data['recipient_email'] ?? null;
        $this->payload = is_string($data['payload_json'] ?? null)
            ? (json_decode($data['payload_json'], true) ?: [])
            : ($data['payload'] ?? []);
        $this->filePath = (string)($data['file_path'] ?? '');
        $this->fileSha256 = (string)($data['file_sha256'] ?? '');
        $this->issuedAt = (string)($data['issued_at'] ?? date('Y-m-d H:i:s'));
        $this->expiresAt = $data['expires_at'] ?? null;
        $this->createdByType = (string)($data['created_by_type'] ?? 'user');
        $this->createdById = isset($data['created_by_id']) ? (int)$data['created_by_id'] : null;
        $this->sourceType = (string)($data['source_type'] ?? 'manual');
    }

    public function isIssued(): bool
    {
        return $this->status === 'issued';
    }

    public function isRevoked(): bool
    {
        return $this->status === 'revoked';
    }
}
