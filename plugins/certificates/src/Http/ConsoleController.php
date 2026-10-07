<?php
declare(strict_types=1);

namespace SOI\Certificates\Http;

use SOI\Certificates\Core\Plugin;
use SOI\Certificates\Core\Session;
use SOI\Certificates\Issuance\IssuanceCommand;

class ConsoleController
{
    protected Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function index(): void
    {
        $templates = $this->plugin->templateService->getPublishedTemplates();
        $certificates = $this->plugin->issuanceService->listTenantCertificates(50);
        $tenant = $this->plugin->tenantContext->getTenant();

        require $this->plugin->baseDir . '/views/console.php';
    }

    public function issueCertificate(): void
    {
        $templateId = (int)($_POST['template_id'] ?? 0);
        $recipientName = trim($_POST['recipient_name'] ?? '');
        $recipientEmail = trim($_POST['recipient_email'] ?? '') ?: null;
        $courseName = trim($_POST['course_name'] ?? '');
        $issueDate = trim($_POST['issue_date'] ?? '') ?: date('Y-m-d');

        try {
            $cmd = new IssuanceCommand(
                $templateId,
                $recipientName,
                ['course_name' => $courseName],
                $recipientEmail,
                $issueDate,
                null,
                'manual'
            );

            $cert = $this->plugin->issuanceService->issue($cmd, 1);
            Session::flash('success', "Certificate {$cert->certificateNumber} successfully issued for {$cert->recipientName}!");
        } catch (\Throwable $e) {
            Session::flash('error', "Issuance failed: " . $e->getMessage());
        }

        header('Location: ' . $this->plugin->router->url('/console'));
        exit;
    }

    public function downloadCertificate(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $cert = $this->plugin->issuanceService->findById($id);

        if (!$cert) {
            http_response_code(404);
            echo "Certificate not found.";
            exit;
        }

        $pdfBytes = $this->plugin->storage->get($cert->filePath);
        if (!$pdfBytes) {
            http_response_code(404);
            echo "Certificate PDF artifact file missing.";
            exit;
        }

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($cert->filePath) . '"');
        header('Content-Length: ' . strlen($pdfBytes));
        echo $pdfBytes;
        exit;
    }

    public function revokeCertificate(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Revoked by administrative action');

        $revoked = $this->plugin->issuanceService->revoke($id, $reason, 1);
        if ($revoked) {
            Session::flash('success', "Certificate #{$id} has been revoked.");
        } else {
            Session::flash('error', "Could not revoke certificate #{$id}.");
        }

        header('Location: ' . $this->plugin->router->url('/console'));
        exit;
    }
}
