<?php
declare(strict_types=1);

namespace SOI\Certificates\Rendering;

/**
 * Standard interface for certificate rendering.
 * Shields the domain issuance pipeline from vendor-specific rendering code.
 */
interface CertificateRendererInterface
{
    public function render(RenderRequest $request): RenderResult;
}
