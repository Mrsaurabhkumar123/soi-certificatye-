<?php
declare(strict_types=1);

namespace SOI\Certificates\Http\Controllers;

use SOI\Certificates\Core\Plugin;
use Throwable;

final class CertificateDownloadController
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function download(array $params): void
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        $certificate = $id === false || $id === null
            ? null
            : $this->plugin->issuanceService->findById((int)$id);

        if ($certificate === null) {
            http_response_code(404);
            echo 'Certificate not found.';
            return;
        }

        try {
            $pdfBytes = $this->plugin->artifactStorage->readVerified(
                $certificate->filePath,
                $certificate->fileSha256
            );
        } catch (Throwable $e) {
            error_log('SOI certificate artifact integrity/read failure for certificate ' . $certificate->id . ': ' . $e->getMessage());
            http_response_code(500);
            echo 'Certificate file is temporarily unavailable.';
            return;
        }

        if (!str_starts_with($pdfBytes, '%PDF-')) {
            error_log('SOI certificate artifact is not a PDF for certificate ' . $certificate->id);
            http_response_code(500);
            echo 'Certificate file is temporarily unavailable.';
            return;
        }

        $this->plugin->audit->log(
            $this->plugin->tenantContext->getTenantId(),
            'user',
            $this->plugin->tenantContext->getCurrentUserId(),
            'certificate.artifact.downloaded',
            'certificate',
            (string)$certificate->id,
            ['sha256' => $certificate->fileSha256]
        );

        $safeNumber = preg_replace('/[^A-Za-z0-9._-]/', '_', $certificate->certificateNumber);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $safeNumber . '.pdf"');
        header('X-Content-Type-Options: nosniff');
        header('Content-Length: ' . strlen($pdfBytes));
        echo $pdfBytes;
    }
}
