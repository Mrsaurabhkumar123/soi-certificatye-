<?php
declare(strict_types=1);

namespace SOI\Certificates\Http;

use SOI\Certificates\Core\Plugin;
use SOI\Certificates\Core\Session;
use SOI\Certificates\Bulk\SpreadsheetReader;

class ManageController
{
    protected Plugin $plugin;

    public function __construct(Plugin $plugin)
    {
        $this->plugin = $plugin;
    }

    public function index(): void
    {
        $tenant = $this->plugin->tenantContext->getTenant();
        $templates = $this->plugin->templateService->getTenantTemplates();
        $categories = $this->plugin->templateService->getCategories();
        $recentTemplates = $this->plugin->templateService->getRecentTemplates(6);
        $recentAudit = $this->plugin->audit->getRecent($tenant->id, 20);
        $verificationPolicy = $this->plugin->verificationPolicy->publicConfiguration($tenant->id);
        $members = $this->plugin->tenantRepo->listMemberships($tenant->id);
        $canManageMembers = $this->plugin->authorizer->can(\SOI\Certificates\Authorization\Permissions::ROLES_MANAGE);
        $canReviewForms = $this->plugin->authorizer->can(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_ISSUE);
        $canManageForms = $this->plugin->authorizer->can(\SOI\Certificates\Authorization\Permissions::FORMS_MANAGE);
        $pendingFormSubmissions = $canReviewForms ? $this->plugin->formApprovalService->listPending() : [];
        $canManageSchedules = $this->plugin->authorizer->can(\SOI\Certificates\Authorization\Permissions::SCHEDULES_MANAGE);
        $schedules = $canManageSchedules ? $this->plugin->scheduleProcessor->listSchedules() : [];
        $canManageWebhooks = $this->plugin->authorizer->can(\SOI\Certificates\Authorization\Permissions::WEBHOOKS_MANAGE);
        $webhooks = $canManageWebhooks ? $this->plugin->webhookService->listWebhooks($tenant->id) : [];
        $canIssueCertificates = $this->plugin->authorizer->can(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_ISSUE);
        $publishedTemplates = ($canManageSchedules || $canManageForms || $canIssueCertificates)
            ? $this->plugin->templateService->getPublishedTemplates()
            : [];
        $formBuilderVariables = [];
        if ($canManageForms) {
            foreach ($publishedTemplates as $publishedTemplate) {
                try {
                    $variables = [
                        ['key' => 'recipient_name', 'label' => 'Recipient name', 'type' => 'short_text', 'required' => true],
                        ['key' => 'recipient_email', 'label' => 'Recipient email', 'type' => 'email', 'required' => false],
                    ];
                    foreach ($this->plugin->templateService->getPublishedVariableSchema($publishedTemplate->id) as $variable) {
                        if (in_array($variable['key'], ['certificate_number', 'verification_url', 'issue_date', 'recipient_name', 'recipient_email', 'tenant_name'], true)) {
                            continue;
                        }
                        $variables[] = $variable;
                    }
                    $formBuilderVariables[$publishedTemplate->id] = $variables;
                } catch (\InvalidArgumentException $e) {
                    error_log('SOI form builder skipped an invalid published template schema: ' . $e->getMessage());
                    $formBuilderVariables[$publishedTemplate->id] = [];
                }
            }
        }
        $forms = $canManageForms ? $this->plugin->dynamicFormService->listTenantForms($tenant->id) : [];
        $bulkBatches = $canIssueCertificates ? $this->plugin->bulkImportEngine->listBatches() : [];
        $canManageAssets = $this->plugin->authorizer->can(\SOI\Certificates\Authorization\Permissions::TEMPLATES_UPDATE);
        $assets = $canManageAssets ? $this->plugin->assetUploader->listForTenant($tenant->id) : [];
        $canManageApiClients = $this->plugin->authorizer->can(\SOI\Certificates\Authorization\Permissions::API_CLIENTS_MANAGE);
        $apiClients = $canManageApiClients ? $this->plugin->apiClientService->listForTenant($tenant->id) : [];
        $apiClientCredentialsJson = Session::flash('api_client_credentials');
        $apiClientCredentials = is_string($apiClientCredentialsJson) ? json_decode($apiClientCredentialsJson, true) : null;
        $certificateTable = $this->plugin->db->tableName('cert_certificates');
        $formSubmissionTable = $this->plugin->db->tableName('cert_form_submissions');
        $dashboardSince = date('Y-m-d H:i:s', strtotime('-30 days'));
        $dashboardMetrics = [
            'issued_total' => (int)$this->plugin->db->fetchValue(
                "SELECT COUNT(*) FROM {$certificateTable} WHERE tenant_id = :tenant_id",
                ['tenant_id' => $tenant->id]
            ),
            'issued_30d' => (int)$this->plugin->db->fetchValue(
                "SELECT COUNT(*) FROM {$certificateTable} WHERE tenant_id = :tenant_id AND issued_at >= :since",
                ['tenant_id' => $tenant->id, 'since' => $dashboardSince]
            ),
            'pending_forms' => (int)$this->plugin->db->fetchValue(
                "SELECT COUNT(*) FROM {$formSubmissionTable} WHERE tenant_id = :tenant_id AND status = 'pending'",
                ['tenant_id' => $tenant->id]
            ),
            'failed_schedules' => (int)$this->plugin->db->fetchValue(
                "SELECT COUNT(*) FROM " . $this->plugin->db->tableName('cert_schedules')
                . " WHERE tenant_id = :tenant_id AND last_result = 'failed'",
                ['tenant_id' => $tenant->id]
            ),
        ];

        require $this->plugin->baseDir . '/views/manage.php';
    }

    public function createForm(): void
    {
        $tenant = $this->plugin->tenantContext->getTenant();
        try {
            $templateId = filter_var($_POST['template_id'] ?? null, FILTER_VALIDATE_INT);
            if ($tenant === null || $templateId === false || $templateId === null || $templateId < 1) {
                throw new \InvalidArgumentException('Select a valid published template.');
            }
            $mappingJson = $_POST['field_mapping'] ?? null;
            $schemaJson = $_POST['field_schema'] ?? null;
            if (!is_string($mappingJson) || !is_string($schemaJson)) {
                throw new \InvalidArgumentException('Configure the form fields before creating the form.');
            }
            if (strlen($mappingJson) > 16_384 || strlen($schemaJson) > 32_768) {
                throw new \InvalidArgumentException('The form field configuration is too large.');
            }
            try {
                $mapping = json_decode($mappingJson, true, 32, JSON_THROW_ON_ERROR);
                $fields = json_decode($schemaJson, true, 32, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \InvalidArgumentException('Configure the form fields before creating the form.', 0, $e);
            }
            if (!is_array($mapping) || !is_array($fields)) {
                throw new \InvalidArgumentException('Configure at least one valid form field.');
            }
            $mode = $_POST['issue_mode'] ?? 'approval';
            if (!is_string($mode)) {
                throw new \InvalidArgumentException('Select a valid submission policy.');
            }
            $formId = $this->plugin->dynamicFormService->createForm(
                $tenant->id,
                (string)($_POST['form_key'] ?? ''),
                (string)($_POST['title'] ?? ''),
                (int)$templateId,
                $mapping,
                $mode === 'approval',
                $fields,
                $mode
            );
            $this->plugin->audit->log(
                $tenant->id,
                'user',
                $this->plugin->tenantContext->getCurrentUserId(),
                'form.created',
                'form',
                (string)$formId,
                ['issue_mode' => $mode, 'template_id' => (int)$templateId]
            );
            Session::flash('success', 'Public form created.');
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI form creation failed: ' . $e->getMessage());
            Session::flash('error', 'The form could not be created.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function createBulkBatch(): void
    {
        try {
            $templateId = filter_var($_POST['template_id'] ?? null, FILTER_VALIDATE_INT);
            $file = $_FILES['csv_file'] ?? null;
            $pastedData = trim((string)($_POST['pasted_data'] ?? ''));
            $hasUpload = is_array($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
            if ($templateId === false || $templateId === null || ($hasUpload && $pastedData !== '')) {
                throw new \InvalidArgumentException('Choose exactly one spreadsheet file or paste CSV/TSV data.');
            }
            if ($hasUpload) {
                if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
                    || !is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
                    throw new \InvalidArgumentException('The spreadsheet upload could not be validated.');
                }
                if ((int)($file['size'] ?? 0) > 2_097_152) {
                    throw new \InvalidArgumentException('Spreadsheet upload must be no larger than 2 MB.');
                }
                $extension = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
                if (!in_array($extension, ['csv', 'xlsx'], true)) {
                    throw new \InvalidArgumentException('Upload a CSV or XLSX spreadsheet.');
                }
                if ($extension === 'xlsx') {
                    $csv = SpreadsheetReader::xlsxToCsv((string)$file['tmp_name']);
                } else {
                    $csv = file_get_contents((string)$file['tmp_name']);
                    if ($csv === false) {
                        throw new \RuntimeException('CSV upload could not be read.');
                    }
                }
            } else {
                if ($pastedData === '') {
                    throw new \InvalidArgumentException('Choose a spreadsheet file or paste CSV/TSV data.');
                }
                $csv = SpreadsheetReader::pastedTextToCsv($pastedData);
            }
            $mapping = [
                (string)($_POST['name_column'] ?? 'Recipient Name') => 'recipient_name',
                (string)($_POST['email_column'] ?? 'Email') => 'recipient_email',
                (string)($_POST['course_column'] ?? 'Course') => 'course_name',
            ];
            foreach ($mapping as $column => $target) {
                if (trim($column) === '') {
                    unset($mapping[$column]);
                }
            }
            $batchId = $this->plugin->bulkImportEngine->createBatch(
                (int)$templateId,
                $csv,
                $mapping,
                $this->plugin->tenantContext->getCurrentUserId()
            );
            Session::flash('success', "Import batch #{$batchId} was created.");
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI bulk batch creation failed: ' . $e->getMessage());
            Session::flash('error', 'The import batch could not be created.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function processBulkBatch(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $batchId = filter_var($_POST['batch_id'] ?? null, FILTER_VALIDATE_INT);
        try {
            if ($batchId === false || $batchId === null || $batchId < 1) {
                throw new \InvalidArgumentException('A valid batch ID is required.');
            }
            echo json_encode(['data' => $this->plugin->bulkImportEngine->processBatch(
                (int)$batchId,
                25,
                $this->plugin->tenantContext->getCurrentUserId()
            )], JSON_THROW_ON_ERROR);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['error' => ['message' => $e->getMessage()]]);
        } catch (\Throwable $e) {
            error_log('SOI bulk batch processing failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => ['message' => 'The import batch could not be processed.']]);
        }
    }

    public function createWebhook(): void
    {
        $tenant = $this->plugin->tenantContext->getTenant();
        try {
            if ($tenant === null) {
                throw new \InvalidArgumentException('Select an active tenant before configuring webhooks.');
            }
            $id = $this->plugin->webhookService->createWebhook(
                $tenant->id,
                (string)($_POST['name'] ?? ''),
                (string)($_POST['target_url'] ?? ''),
                (string)($_POST['secret'] ?? ''),
                is_array($_POST['events'] ?? null) ? $_POST['events'] : []
            );
            $this->plugin->audit->log(
                $tenant->id,
                'user',
                $this->plugin->tenantContext->getCurrentUserId(),
                'webhook.created',
                'webhook',
                (string)$id,
                ['events' => $_POST['events'] ?? []]
            );
            Session::flash('success', 'Webhook endpoint saved. The signing secret is encrypted at rest.');
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI webhook configuration failed: ' . $e->getMessage());
            Session::flash('error', 'Webhook could not be saved. Check encryption-key and OpenSSL configuration.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function uploadAsset(): void
    {
        $tenant = $this->plugin->tenantContext->getTenant();
        try {
            $file = $_FILES['asset_file'] ?? null;
            if ($tenant === null || !is_array($file)) {
                throw new \InvalidArgumentException('Select an asset file and active tenant.');
            }
            $asset = $this->plugin->assetUploader->upload(
                $tenant->id,
                $tenant->slug,
                (string)($_POST['name'] ?? ''),
                (string)($_POST['asset_type'] ?? ''),
                $file
            );
            $this->plugin->audit->log(
                $tenant->id,
                'user',
                $this->plugin->tenantContext->getCurrentUserId(),
                'asset.uploaded',
                'asset',
                (string)$asset['id'],
                ['asset_type' => $asset['asset_type'], 'sha256' => $asset['sha256']]
            );
            Session::flash('success', 'Asset uploaded and validated.');
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI asset upload failed: ' . $e->getMessage());
            Session::flash('error', 'The asset could not be uploaded.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function createApiClient(): void
    {
        $tenant = $this->plugin->tenantContext->getTenant();
        try {
            if ($tenant === null) {
                throw new \InvalidArgumentException('Select an active tenant before creating API clients.');
            }
            $scopes = is_array($_POST['scopes'] ?? null) ? $_POST['scopes'] : [];
            $credentials = $this->plugin->apiClientService->createClient(
                $tenant->id,
                (string)($_POST['name'] ?? ''),
                $scopes
            );
            Session::flash('api_client_credentials', json_encode($credentials, JSON_THROW_ON_ERROR));
            $this->plugin->audit->log(
                $tenant->id,
                'user',
                $this->plugin->tenantContext->getCurrentUserId(),
                'api_client.created',
                'api_client',
                $credentials['client_id'],
                ['scopes' => $credentials['scopes']]
            );
            Session::flash('success', 'API client created. Copy its secret now; it cannot be viewed again.');
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI API client creation failed: ' . $e->getMessage());
            Session::flash('error', 'API client could not be created.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function revokeApiClient(): void
    {
        $tenantId = $this->plugin->tenantContext->getTenantId();
        $clientId = filter_var($_POST['client_id'] ?? null, FILTER_VALIDATE_INT);
        try {
            if ($clientId === false || $clientId === null
                || !$this->plugin->apiClientService->revoke($tenantId, (int)$clientId)) {
                throw new \InvalidArgumentException('The active tenant API client was not found.');
            }
            $this->plugin->audit->log(
                $tenantId,
                'user',
                $this->plugin->tenantContext->getCurrentUserId(),
                'api_client.revoked',
                'api_client',
                (string)$clientId
            );
            Session::flash('success', 'API client revoked.');
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI API client revocation failed: ' . $e->getMessage());
            Session::flash('error', 'API client could not be revoked.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function deleteAsset(array $params): void
    {
        $tenantId = $this->plugin->tenantContext->getTenantId();
        $assetId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        try {
            if ($assetId === false || $assetId === null || $assetId < 1
                || !$this->plugin->assetUploader->delete($tenantId, (int)$assetId)) {
                throw new \InvalidArgumentException('The asset was not found in the active tenant.');
            }
            $this->plugin->audit->log(
                $tenantId,
                'user',
                $this->plugin->tenantContext->getCurrentUserId(),
                'asset.deleted',
                'asset',
                (string)$assetId
            );
            Session::flash('success', 'Asset deleted.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI asset deletion failed: ' . $e->getMessage());
            Session::flash('error', 'The asset could not be deleted.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function showAsset(array $params): void
    {
        $assetId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        $tenantId = $this->plugin->tenantContext->getTenantId();
        $asset = $assetId === false || $assetId === null
            ? null
            : $this->plugin->assetUploader->findForTenant($tenantId, (int)$assetId);
        $bytes = $asset === null ? null : $this->plugin->storage->get((string)$asset['file_path']);
        if ($asset === null || $bytes === null) {
            http_response_code(404);
            echo 'Asset not found.';
            return;
        }
        header('Content-Type: ' . $asset['mime_type']);
        header('Content-Length: ' . strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        header('Content-Disposition: inline; filename="' . basename((string)$asset['file_path']) . '"');
        echo $bytes;
    }

    public function runWebhooks(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $tenantId = $this->plugin->tenantContext->getTenantId();
            echo json_encode(['data' => $this->plugin->webhookService->dispatchDue($tenantId, 25)], JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            error_log('SOI webhook delivery run failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => ['message' => 'Webhook deliveries could not be processed.']]);
        }
    }

    public function createSchedule(): void
    {
        $templateId = filter_var($_POST['template_id'] ?? null, FILTER_VALIDATE_INT);
        try {
            if ($templateId === false || $templateId === null || $templateId < 1) {
                throw new \InvalidArgumentException('Select a valid published template.');
            }
            $runAt = str_replace('T', ' ', trim((string)($_POST['next_run_at'] ?? '')));
            if (strlen($runAt) === 16) {
                $runAt .= ':00';
            }
            $scheduleId = $this->plugin->scheduleProcessor->createSchedule(
                (string)($_POST['name'] ?? ''),
                (int)$templateId,
                [
                    'recipient_name' => (string)($_POST['recipient_name'] ?? ''),
                    'recipient_email' => (string)($_POST['recipient_email'] ?? ''),
                    'variables' => ['course_name' => (string)($_POST['course_name'] ?? '')],
                ],
                $runAt,
                (string)($_POST['recurrence'] ?? 'once'),
                (string)($_POST['timezone'] ?? 'UTC')
            );
            Session::flash('success', "Schedule #{$scheduleId} created.");
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI schedule creation failed: ' . $e->getMessage());
            Session::flash('error', 'The schedule could not be created.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function updateScheduleStatus(): void
    {
        $scheduleId = filter_var($_POST['schedule_id'] ?? null, FILTER_VALIDATE_INT);
        $status = (string)($_POST['status'] ?? '');
        try {
            if ($scheduleId === false || $scheduleId === null || $scheduleId < 1
                || !$this->plugin->scheduleProcessor->setStatus((int)$scheduleId, $status)) {
                throw new \InvalidArgumentException('The schedule could not be updated in the active tenant.');
            }
            Session::flash('success', 'Schedule status updated.');
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI schedule status update failed: ' . $e->getMessage());
            Session::flash('error', 'The schedule status could not be updated.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function runSchedules(): void
    {
        try {
            $queued = $this->plugin->scheduleProcessor->enqueueDueOccurrences();
            $result = $this->plugin->scheduleProcessor->runDueJobs(
                20,
                60,
                $this->plugin->tenantContext->getCurrentUserId()
            );
            Session::flash(
                'success',
                "Scheduler run finished: {$queued} occurrence(s) queued, {$result['issued']} issued, {$result['failed']} failed."
            );
        } catch (\Throwable $e) {
            error_log('SOI authorized schedule run failed: ' . $e->getMessage());
            Session::flash('error', 'Due schedules could not be processed.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function approveFormSubmission(array $params): void
    {
        $submissionId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        try {
            if ($submissionId === false || $submissionId === null || $submissionId < 1) {
                throw new \InvalidArgumentException('A valid form submission is required.');
            }
            $certificate = $this->plugin->formApprovalService->approve(
                (int)$submissionId,
                $this->plugin->tenantContext->getCurrentUserId()
            );
            Session::flash('success', "Submission approved; certificate {$certificate->certificateNumber} was issued.");
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI form submission approval failed: ' . $e->getMessage());
            Session::flash('error', 'The submission could not be approved.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function rejectFormSubmission(array $params): void
    {
        $submissionId = filter_var($params['id'] ?? null, FILTER_VALIDATE_INT);
        try {
            if ($submissionId === false || $submissionId === null || $submissionId < 1) {
                throw new \InvalidArgumentException('A valid form submission is required.');
            }
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (!$this->plugin->formApprovalService->reject(
                (int)$submissionId,
                $reason,
                $this->plugin->tenantContext->getCurrentUserId()
            )) {
                throw new \InvalidArgumentException('The pending tenant submission was not found.');
            }
            Session::flash('success', 'Form submission rejected.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI form submission rejection failed: ' . $e->getMessage());
            Session::flash('error', 'The submission could not be rejected.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function addMember(): void
    {
        $tenant = $this->plugin->tenantContext->getTenant();
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $role = (string)($_POST['role_key'] ?? '');
        try {
            if ($tenant === null || $userId === false || $userId === null || $userId < 1) {
                throw new \InvalidArgumentException('Enter a valid CMS user ID.');
            }
            if (!(new \SOI\Certificates\Core\CmsUserDirectory())->userExists((int)$userId)) {
                throw new \InvalidArgumentException('The CMS user account was not found.');
            }
            $this->plugin->tenantRepo->addMembership($tenant->id, (int)$userId, $role);
            $this->plugin->audit->log(
                $tenant->id,
                'user',
                $this->plugin->tenantContext->getCurrentUserId(),
                'tenant.membership.added',
                'membership',
                (string)$userId,
                ['role' => $role]
            );
            Session::flash('success', 'Tenant membership saved.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI tenant membership creation failed: ' . $e->getMessage());
            Session::flash('error', 'Tenant membership could not be saved.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function updateMemberRole(): void
    {
        $tenant = $this->plugin->tenantContext->getTenant();
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        $role = (string)($_POST['role_key'] ?? '');
        try {
            if ($tenant === null || $userId === false || $userId === null || $userId < 1) {
                throw new \InvalidArgumentException('A valid CMS user ID is required.');
            }
            if (!$this->plugin->tenantRepo->updateMembershipRole($tenant->id, (int)$userId, $role)) {
                throw new \InvalidArgumentException('The active tenant membership was not found.');
            }
            $this->plugin->audit->log(
                $tenant->id,
                'user',
                $this->plugin->tenantContext->getCurrentUserId(),
                'tenant.membership.role_updated',
                'membership',
                (string)$userId,
                ['role' => $role]
            );
            Session::flash('success', 'Tenant member role updated.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI tenant membership role update failed: ' . $e->getMessage());
            Session::flash('error', 'Tenant member role could not be updated.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function deactivateMember(): void
    {
        $tenant = $this->plugin->tenantContext->getTenant();
        $userId = filter_var($_POST['user_id'] ?? null, FILTER_VALIDATE_INT);
        try {
            if ($tenant === null || $userId === false || $userId === null || $userId < 1) {
                throw new \InvalidArgumentException('A valid CMS user ID is required.');
            }
            if (!$this->plugin->tenantRepo->deactivateMembership($tenant->id, (int)$userId)) {
                throw new \InvalidArgumentException('The active tenant membership was not found.');
            }
            $this->plugin->audit->log(
                $tenant->id,
                'user',
                $this->plugin->tenantContext->getCurrentUserId(),
                'tenant.membership.deactivated',
                'membership',
                (string)$userId,
                []
            );
            Session::flash('success', 'Tenant membership deactivated.');
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI tenant membership deactivation failed: ' . $e->getMessage());
            Session::flash('error', 'Tenant membership could not be deactivated.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function saveBranding(): void
    {
        $tenant = $this->plugin->tenantContext->getTenant();
        if ($tenant === null) {
            http_response_code(403);
            echo '403 Forbidden';
            return;
        }
        $branding = [
            'primary_color' => trim((string)($_POST['primary_color'] ?? '')),
            'accent_color' => trim((string)($_POST['accent_color'] ?? '')),
            'logo' => trim((string)($_POST['logo'] ?? '')),
        ];
        $branding = array_filter($branding, static fn(string $value): bool => $value !== '');
        try {
            if (!$this->plugin->tenantRepo->updateBranding($tenant->id, $branding)) {
                throw new \RuntimeException('Tenant branding was not updated.');
            }
            $this->plugin->audit->log(
                $tenant->id,
                'user',
                $this->plugin->tenantContext->getCurrentUserId(),
                'tenant.branding.updated',
                'tenant',
                (string)$tenant->id,
                ['primary_color' => $branding['primary_color'] ?? null, 'accent_color' => $branding['accent_color'] ?? null]
            );
            Session::flash('success', 'Tenant branding settings saved.');
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI tenant branding update failed: ' . $e->getMessage());
            Session::flash('error', 'Tenant branding could not be saved.');
        }
        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function saveVerificationPolicy(): void
    {
        $tenant = $this->plugin->tenantContext->getTenant();
        if ($tenant === null) {
            http_response_code(403);
            echo '403 Forbidden';
            return;
        }
        try {
            $mode = (string)($_POST['verification_mode'] ?? '');
            $pin = isset($_POST['verification_pin']) ? (string)$_POST['verification_pin'] : null;
            $this->plugin->verificationPolicy->configure($tenant->id, $mode, $pin);
            $this->plugin->audit->log(
                $tenant->id,
                'user',
                $this->plugin->tenantContext->getCurrentUserId(),
                'verification.policy.updated',
                'tenant',
                (string)$tenant->id,
                ['mode' => $mode, 'pin_configured' => ($pin !== null && $pin !== '')]
            );
            Session::flash('success', 'Verification privacy settings saved.');
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI verification policy update failed: ' . $e->getMessage());
            Session::flash('error', 'Verification privacy settings could not be saved.');
        }

        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function createTemplate(): void
    {
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $category = trim($_POST['category'] ?? '');

        if (!empty($name) && !empty($slug)) {
            $this->plugin->templateService->createTemplate($slug, $name, $category);
            Session::flash('success', "Template '{$name}' created in draft state.");
        } else {
            Session::flash('error', "Template name and slug are required.");
        }

        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function cloneTemplate(): void
    {
        $id = (int)($_POST['template_id'] ?? 0);
        $newName = trim((string)($_POST['name'] ?? ''));
        $newSlug = trim((string)($_POST['slug'] ?? ''));
        $category = trim((string)($_POST['category'] ?? '')) ?: null;

        try {
            if ($id <= 0) {
                throw new \InvalidArgumentException('Select a valid template to clone.');
            }
            $source = $this->plugin->templateService->findById($id);
            if ($source === null) {
                throw new \InvalidArgumentException('Source template not found.');
            }
            if ($newName === '') {
                $newName = $source->name . ' (Clone)';
            }
            if ($newSlug === '') {
                $newSlug = $source->slug . '-copy-' . substr(bin2hex(random_bytes(3)), 0, 5);
            }
            $cloned = $this->plugin->templateService->cloneTemplate($id, $newSlug, $newName, $category);
            Session::flash('success', "Template successfully cloned as draft '{$cloned->name}'.");
        } catch (\Throwable $e) {
            error_log('SOI template clone failed: ' . $e->getMessage());
            Session::flash('error', 'Template could not be cloned: ' . $e->getMessage());
        }

        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function archiveTemplate(): void
    {
        $id = (int)($_POST['template_id'] ?? 0);
        try {
            if ($id <= 0 || !$this->plugin->templateService->archiveTemplate($id)) {
                throw new \InvalidArgumentException('Template could not be archived.');
            }
            Session::flash('success', 'Template has been archived.');
        } catch (\Throwable $e) {
            Session::flash('error', $e->getMessage());
        }

        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }

    public function designer(array $params): void
    {
        $id = (int)($params['id'] ?? 0);
        $template = $this->plugin->templateService->findById($id);
        if ($template === null || $template->draftVersionId === null) {
            http_response_code(404);
            echo 'Template not found.';
            return;
        }
        $version = $this->plugin->templateService->findVersionById($template->draftVersionId);
        if ($version === null) {
            http_response_code(404);
            echo 'Template version not found.';
            return;
        }
        $layout = $version->layout;
        $variableSchema = $version->variableSchema;
        $templateId = $template->id;
        $isPublishedDraft = $version->publishedAt !== null;
        require $this->plugin->baseDir . '/views/designer.php';
    }

    public function saveTemplateDraft(): void
    {
        header('Content-Type: application/json; charset=utf-8');
        $data = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($data)) {
            http_response_code(400);
            echo json_encode(['error' => ['code' => 'INVALID_JSON', 'message' => 'A JSON object is required.']]);
            return;
        }
        try {
            $version = $this->plugin->templateService->saveDraft(
                (int)($data['template_id'] ?? 0),
                is_array($data['layout'] ?? null) ? $data['layout'] : [],
                is_array($data['variable_schema'] ?? null) ? $data['variable_schema'] : []
            );
            echo json_encode(['data' => ['version_id' => $version->id, 'version_number' => $version->versionNumber]]);
        } catch (\InvalidArgumentException $e) {
            http_response_code(422);
            echo json_encode(['error' => ['code' => 'INVALID_TEMPLATE', 'message' => $e->getMessage()]]);
        } catch (\Throwable $e) {
            error_log('SOI template draft save failed: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['error' => ['code' => 'SAVE_FAILED', 'message' => 'The template draft could not be saved.']]);
        }
    }

    public function publishTemplate(): void
    {
        $id = (int)($_POST['template_id'] ?? 0);
        try {
            $version = $this->plugin->templateService->publish($id, $this->plugin->tenantContext->getCurrentUserId());
            Session::flash('success', "Template published as immutable version v{$version->versionNumber}.");
        } catch (\InvalidArgumentException $e) {
            Session::flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            error_log('SOI template publish failed: ' . $e->getMessage());
            Session::flash('error', 'Template could not be published.');
        }

        header('Location: ' . $this->plugin->router->url('/manage'));
        exit;
    }
}
