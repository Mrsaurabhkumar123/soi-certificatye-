<?php
declare(strict_types=1);

namespace SOI\Certificates\Rendering;

use SOI\Certificates\Templates\TemplateVersion;

class RenderRequest
{
    public TemplateVersion $templateVersion;
    public array $variables;
    public string $certificateNumber;
    public string $verificationUrl;
    public string $tenantName;
    public string $correlationId;

    public function __construct(
        TemplateVersion $templateVersion,
        array $variables,
        string $certificateNumber,
        string $verificationUrl,
        string $tenantName = '',
        string $correlationId = ''
    ) {
        $this->templateVersion = $templateVersion;
        $this->variables = $variables;
        $this->certificateNumber = $certificateNumber;
        $this->verificationUrl = $verificationUrl;
        $this->tenantName = $tenantName;
        $this->correlationId = $correlationId ?: ('rnd_' . bin2hex(random_bytes(6)));
    }
}

class RenderResult
{
    public string $pdfBytes;
    public string $sha256;
    public int $sizeBytes;
    public int $pageCount;
    public float $renderDurationMs;
    public array $warnings = [];

    public function __construct(string $pdfBytes, float $durationMs = 0.0, array $warnings = [])
    {
        $this->pdfBytes = $pdfBytes;
        $this->sha256 = hash('sha256', $pdfBytes);
        $this->sizeBytes = strlen($pdfBytes);
        $this->pageCount = 1;
        $this->renderDurationMs = $durationMs;
        $this->warnings = $warnings;
    }
}
