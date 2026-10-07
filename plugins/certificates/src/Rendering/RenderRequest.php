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
