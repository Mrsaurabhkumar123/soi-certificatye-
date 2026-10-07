<?php
declare(strict_types=1);

namespace SOI\Certificates\Issuance;

class IssuanceCommand
{
    public int $templateId;
    public string $recipientName;
    public ?string $recipientEmail = null;
    public array $variables = [];
    public ?string $issueDate = null;
    public ?string $expiresAt = null;
    public string $sourceType = 'manual';
    public ?string $idempotencyKey = null;

    public function __construct(
        int $templateId,
        string $recipientName,
        array $variables = [],
        ?string $recipientEmail = null,
        ?string $issueDate = null,
        ?string $expiresAt = null,
        string $sourceType = 'manual',
        ?string $idempotencyKey = null
    ) {
        $this->templateId = $templateId;
        $this->recipientName = trim($recipientName);
        $this->variables = $variables;
        $this->recipientEmail = $recipientEmail;
        $this->issueDate = $issueDate ?: date('Y-m-d');
        $this->expiresAt = $expiresAt;
        $this->sourceType = $sourceType;
        $this->idempotencyKey = $idempotencyKey;
    }
}
