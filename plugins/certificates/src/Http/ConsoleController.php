<?php
declare(strict_types=1);

namespace SOI\Certificates\Http;

use SOI\Certificates\Core\Plugin;
use SOI\Certificates\Core\Session;
use SOI\Certificates\Issuance\IssuanceCommand;
use SOI\Certificates\Reporting\CsvExporter;

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
        $filters = [
            'certificate_number' => substr(trim((string)($_GET['certificate_number'] ?? '')), 0, 64),
            'recipient' => substr(trim((string)($_GET['recipient'] ?? '')), 0, 128),
            'status' => (string)($_GET['status'] ?? ''),
            'from' => (string)($_GET['from'] ?? ''),
            'to' => (string)($_GET['to'] ?? ''),
        ];
        try {
            $certificates = $this->plugin->issuanceService->searchTenantCertificates($filters, 100);
        } catch (\InvalidArgumentException $e) {
            $certificates = [];
            Session::flash('error', $e->getMessage());
        }
        $canExport = $this->plugin->authorizer->can(\SOI\Certificates\Authorization\Permissions::REPORTS_EXPORT);
        $tenant = $this->plugin->tenantContext->getTenant();
        $certificateTable = $this->plugin->db->tableName('cert_certificates');
        $auditTable = $this->plugin->db->tableName('cert_audit_log');
        $tenantId = $tenant->id;
        $since = date('Y-m-d H:i:s', strtotime('-30 days'));
        $dashboardMetrics = [
            'issued_total' => (int)$this->plugin->db->fetchValue(
                "SELECT COUNT(*) FROM {$certificateTable} WHERE tenant_id = :tenant_id",
                ['tenant_id' => $tenantId]
            ),
            'issued_30d' => (int)$this->plugin->db->fetchValue(
                "SELECT COUNT(*) FROM {$certificateTable} WHERE tenant_id = :tenant_id AND issued_at >= :since",
                ['tenant_id' => $tenantId, 'since' => $since]
            ),
            'active' => (int)$this->plugin->db->fetchValue(
                "SELECT COUNT(*) FROM {$certificateTable} WHERE tenant_id = :tenant_id AND status = 'issued'",
                ['tenant_id' => $tenantId]
            ),
            'failures_30d' => (int)$this->plugin->db->fetchValue(
                "SELECT COUNT(*) FROM {$auditTable} WHERE tenant_id = :tenant_id
                 AND event_key LIKE '%failed%' AND created_at >= :since",
                ['tenant_id' => $tenantId, 'since' => $since]
            ),
        ];
        $recentFailures = $this->plugin->db->fetchAll(
            "SELECT event_key, target_type, target_id, created_at FROM {$auditTable}
             WHERE tenant_id = :tenant_id AND event_key LIKE '%failed%'
             ORDER BY id DESC LIMIT 5",
            ['tenant_id' => $tenantId]
        );

        require $this->plugin->baseDir . '/views/console.php';
    }

    public function detailCertificate(array $params): void
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
        $events = $this->plugin->issuanceService->listCertificateEvents($certificate->id);
        $pageTitle = 'Certificate ' . $certificate->certificateNumber;
        ob_start();
        require $this->plugin->baseDir . '/views/certificate_detail.php';
        $content = ob_get_clean();
        require $this->plugin->baseDir . '/views/layout.php';
    }

    public function exportRegistry(): void
    {
        $filters = [
            'certificate_number' => substr(trim((string)($_GET['certificate_number'] ?? '')), 0, 64),
            'recipient' => substr(trim((string)($_GET['recipient'] ?? '')), 0, 128),
            'status' => (string)($_GET['status'] ?? ''),
            'from' => (string)($_GET['from'] ?? ''),
            'to' => (string)($_GET['to'] ?? ''),
        ];
        try {
            $csv = (new CsvExporter($this->plugin->db, $this->plugin->tenantContext))->exportCertificates($filters);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="certificate-registry-' . gmdate('Ymd-His') . '.csv"');
            header('X-Content-Type-Options: nosniff');
            echo $csv;
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        } catch (\Throwable $e) {
            error_log('SOI certificate registry export failed: ' . $e->getMessage());
            http_response_code(500);
            echo 'The certificate registry export could not be generated.';
        }
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

            $cert = $this->plugin->issuanceService->issue($cmd, $this->plugin->tenantContext->getCurrentUserId());
            Session::flash('success', "Certificate {$cert->certificateNumber} successfully issued for {$cert->recipientName}!");
        } catch (\Throwable $e) {
            error_log('SOI manual certificate issuance failed: ' . $e->getMessage());
            Session::flash('error', 'The certificate could not be issued with the supplied information.');
        }

        header('Location: ' . $this->plugin->router->url('/console'));
        exit;
    }

    public function revokeCertificate(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $reason = trim($_POST['reason'] ?? 'Revoked by administrative action');

        try {
            $revoked = $this->plugin->issuanceService->revoke(
                $id,
                $reason,
                $this->plugin->tenantContext->getCurrentUserId()
            );
            if ($revoked) {
                Session::flash('success', "Certificate #{$id} has been revoked.");
            } else {
                Session::flash('error', "Could not revoke certificate #{$id}; it may no longer be issued.");
            }
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI certificate revocation failed: ' . $e->getMessage());
            Session::flash('error', 'The certificate could not be revoked.');
        }

        header('Location: ' . $this->plugin->router->url('/console'));
        exit;
    }

    public function replaceCertificate(array $params): void
    {
        $id = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        $certificate = $id === false || $id === null
            ? null
            : $this->plugin->issuanceService->findById((int)$id);

        try {
            if ($certificate === null) {
                throw new \InvalidArgumentException('The certificate was not found in the active tenant.');
            }
            $recipientName = trim((string)($_POST['recipient_name'] ?? ''));
            $recipientEmail = trim((string)($_POST['recipient_email'] ?? '')) ?: null;
            $reason = trim((string)($_POST['reason'] ?? ''));
            $variables = $certificate->payload;
            foreach (['recipient_name', 'recipient_email', 'issue_date', 'certificate_number', 'verification_url'] as $systemVariable) {
                unset($variables[$systemVariable]);
            }
            $replacement = $this->plugin->issuanceService->replace(
                $certificate->id,
                new IssuanceCommand(
                    $certificate->templateId,
                    $recipientName,
                    $variables,
                    $recipientEmail,
                    date('Y-m-d'),
                    $certificate->expiresAt,
                    'replacement'
                ),
                $reason,
                $this->plugin->tenantContext->getCurrentUserId()
            );
            Session::flash('success', "Certificate replaced with {$replacement->certificateNumber}.");
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI certificate replacement failed: ' . $e->getMessage());
            Session::flash('error', 'The certificate could not be replaced.');
        }

        header('Location: ' . $this->plugin->router->url('/console'));
        exit;
    }
}
