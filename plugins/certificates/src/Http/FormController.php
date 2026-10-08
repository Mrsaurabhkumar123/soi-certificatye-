<?php
declare(strict_types=1);

namespace SOI\Certificates\Http;

use SOI\Certificates\Core\Plugin;
use SOI\Certificates\Core\Session;
use SOI\Certificates\Tenancy\Tenant;
use Throwable;

final class FormController
{
    public function __construct(private readonly Plugin $plugin)
    {
    }

    public function show(array $params): void
    {
        $form = $this->plugin->dynamicFormService->getPublicFormByKey((string)($params['form_key'] ?? ''));
        if ($form === null) {
            http_response_code(404);
            echo 'Public form not found.';
            return;
        }
        $fields = json_decode((string)$form['field_schema_json'], true);
        if (!is_array($fields)) {
            http_response_code(500);
            echo 'Public form configuration is unavailable.';
            return;
        }
        $csrfField = Session::csrfField();
        $actionUrl = $this->plugin->router->url('/forms/' . rawurlencode($form['form_key']));
        require $this->plugin->baseDir . '/views/forms/public.php';
    }

    public function submit(array $params): void
    {
        $form = $this->plugin->dynamicFormService->getPublicFormByKey((string)($params['form_key'] ?? ''));
        if ($form === null) {
            http_response_code(404);
            echo 'Public form not found.';
            return;
        }
        $remoteIp = (string)($_SERVER['REMOTE_ADDR'] ?? '');
        if ($remoteIp === '' || filter_var($remoteIp, FILTER_VALIDATE_IP) === false) {
            http_response_code(400);
            echo 'Submission could not be accepted.';
            return;
        }
        $ipFingerprint = hash('sha256', $remoteIp);
        $submissions = $this->plugin->db->tableName('cert_form_submissions');
        $cutoff = $this->plugin->db->getDriver() === 'sqlite'
            ? "datetime('now', '-15 minutes')"
            : "DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 15 MINUTE)";
        $recentCount = (int)$this->plugin->db->fetchValue(
            "SELECT COUNT(*) FROM {$submissions}
             WHERE form_id = :form_id AND submitter_ip = :ip AND created_at >= {$cutoff}",
            ['form_id' => (int)$form['id'], 'ip' => $ipFingerprint]
        );
        if ($recentCount >= 5) {
            http_response_code(429);
            echo 'Submission rate limit reached. Please try again later.';
            return;
        }

        $tenant = $this->plugin->tenantRepo->findById((int)$form['tenant_id']);
        if ($tenant === null || !$tenant->isActive()) {
            http_response_code(404);
            echo 'Public form not found.';
            return;
        }
        $previousContext = $this->plugin->tenantContext->snapshot();
        $this->plugin->tenantContext->setTenant($tenant, 'viewer', null);
        try {
            $submissionId = $this->plugin->dynamicFormService->submitForm(
                (int)$form['id'],
                (int)$form['tenant_id'],
                is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [],
                $ipFingerprint
            );
            if ($form['issue_mode'] === 'immediate') {
                $certificate = $this->plugin->formApprovalService->issuePublicSubmission($submissionId);
                $verificationUrl = $this->plugin->router->url('/verify/' . rawurlencode($certificate->verificationToken));
                http_response_code(201);
                echo '<main class="container"><section class="card"><h1>Certificate issued</h1><p>Your certificate number is '
                    . htmlspecialchars($certificate->certificateNumber, ENT_QUOTES, 'UTF-8')
                    . '.</p><p><a href="' . htmlspecialchars($verificationUrl, ENT_QUOTES, 'UTF-8')
                    . '">View verification details</a></p></section></main>';
                return;
            }
            http_response_code(202);
            echo '<main class="container"><section class="card"><h1>Request received</h1><p>Your submission is awaiting the organization\'s review.</p></section></main>';
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo '<main class="container"><section class="card"><h1>Check your submission</h1><p>'
                . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</p></section></main>';
        } catch (Throwable $e) {
            error_log('SOI public form submission failed: ' . $e->getMessage());
            http_response_code(500);
            echo '<main class="container"><section class="card"><h1>Submission unavailable</h1><p>Please try again later.</p></section></main>';
        } finally {
            $this->plugin->tenantContext->restore($previousContext);
        }
    }
}
