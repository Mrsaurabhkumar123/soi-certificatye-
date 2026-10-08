<?php
declare(strict_types=1);

namespace SOI\Certificates\Verification;

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
    public bool $requiresPin = false;
    public bool $requiresAuthentication = false;
}
