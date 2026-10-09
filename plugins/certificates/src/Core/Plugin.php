<?php
declare(strict_types=1);

namespace SOI\Certificates\Core;

use SOI\Certificates\Audit\AuditService;
use SOI\Certificates\Api\ApiClientAuth;
use SOI\Certificates\Api\ApiClientService;
use SOI\Certificates\Api\IdempotencyService;
use SOI\Certificates\Api\ScopeMiddleware;
use SOI\Certificates\Authorization\Authorizer;
use SOI\Certificates\Http\ConsoleController;
use SOI\Certificates\Http\Controllers\CertificateDownloadController;
use SOI\Certificates\Http\DocsController;
use SOI\Certificates\Http\FormController;
use SOI\Certificates\Http\ManageController;
use SOI\Certificates\Http\SuperAdminController;
use SOI\Certificates\Http\VerifyController;
use SOI\Certificates\Http\SchedulerRunnerController;
use SOI\Certificates\Issuance\CertificateIssuanceService;
use SOI\Certificates\Forms\DynamicFormService;
use SOI\Certificates\Forms\FormApprovalService;
use SOI\Certificates\Bulk\BulkImportEngine;
use SOI\Certificates\Rendering\LocalCertificateRenderer;
use SOI\Certificates\Storage\LocalStorageAdapter;
use SOI\Certificates\Storage\ArtifactStorageService;
use SOI\Certificates\Storage\AssetUploader;
use SOI\Certificates\Templates\TemplateService;
use SOI\Certificates\Tenancy\TenantContext;
use SOI\Certificates\Tenancy\TenantRepository;
use SOI\Certificates\Tenancy\TenantThemeManager;
use SOI\Certificates\Scheduling\JobScheduler;
use SOI\Certificates\Scheduling\ScheduleProcessor;
use SOI\Certificates\Verification\VerificationService;
use SOI\Certificates\Verification\VerificationPolicy;
use SOI\Certificates\Webhooks\WebhookService;

/**
 * Main application container & bootstrap coordinator for SOI Certificate Platform.
 */
class Plugin
{
    public const VERSION = '1.0.0';
    public const SCHEMA_VERSION = '2026100709';

    protected static ?Plugin $instance = null;

    public Database $db;
    public MigrationRunner $migrationRunner;
    public LocalStorageAdapter $storage;
    public ArtifactStorageService $artifactStorage;
    public AssetUploader $assetUploader;
    public TenantRepository $tenantRepo;
    public TenantThemeManager $themeManager;
    public TenantContext $tenantContext;
    public Authorizer $authorizer;
    public AuditService $audit;
    public WebhookService $webhookService;
    public TemplateService $templateService;
    public LocalCertificateRenderer $renderer;
    public CertificateIssuanceService $issuanceService;
    public DynamicFormService $dynamicFormService;
    public FormApprovalService $formApprovalService;
    public BulkImportEngine $bulkImportEngine;
    public JobScheduler $jobScheduler;
    public ScheduleProcessor $scheduleProcessor;
    public VerificationService $verificationService;
    public VerificationPolicy $verificationPolicy;
    public ApiClientService $apiClientService;
    public IdempotencyService $idempotencyService;
    public HealthService $healthService;
    public SystemDiagnostics $systemDiagnostics;
    public Router $router;
    public string $baseDir;
    protected ?array $apiClientContext = null;

    public function __construct(string $baseDir, string $baseWebPath = '')
    {
        $this->baseDir = rtrim($baseDir, '/\\');

        // 1. Storage setup
        $storageDir = $this->baseDir . '/storage';
        $this->storage = new LocalStorageAdapter($storageDir);
        $this->artifactStorage = new ArtifactStorageService($this->storage);

        // 2. Database & Migrations
        $this->db = Database::createDefault($storageDir . '/data');
        $this->migrationRunner = new MigrationRunner($this->db, $this->baseDir . '/migrations');

        // Auto-run baseline migrations if empty
        if (count($this->migrationRunner->getPendingMigrations()) > 0) {
            $this->migrationRunner->runPending();
        }

        // 3. Resolve identity from the CMS integration.
        $this->themeManager = new TenantThemeManager();
        $this->tenantRepo = new TenantRepository($this->db, $this->themeManager);
        $identity = CmsIdentity::current();
        $standaloneDemo = getenv('SOI_CERT_ENV') === 'development'
            && getenv('SOI_CERT_STANDALONE_DEMO') === '1';

        // Ensure baseline default organization tenant exists
        $defaultTenant = $this->tenantRepo->findFirstActive() ?? $this->initDefaultTenant();

        $this->tenantContext = new TenantContext(null, $identity->userId, 'viewer');
        $this->authorizer = new Authorizer($this->tenantContext, $identity->isPlatformAdmin || $standaloneDemo);

        $activeTenantId = $identity->activeTenantId;

        // Resolve active tenant for authenticated user
        if ($identity->userId !== null) {
            if ($activeTenantId === null) {
                // Check if user has active memberships
                $userMemberships = $this->tenantRepo->listTenantsForUser($identity->userId);
                if (!empty($userMemberships)) {
                    $activeTenantId = (int)$userMemberships[0]['id'];
                } elseif ($identity->isPlatformAdmin || $standaloneDemo) {
                    // Admin user without explicit membership: enroll as tenant_owner on default tenant
                    $this->tenantRepo->addMembership($defaultTenant->id, $identity->userId, 'tenant_owner');
                    $activeTenantId = $defaultTenant->id;
                }
            }

            if ($activeTenantId !== null) {
                $membership = $this->tenantRepo->resolveMembership($activeTenantId, $identity->userId);
                if ($membership !== null) {
                    $this->tenantContext->setTenant(new \SOI\Certificates\Tenancy\Tenant($membership), $membership['role_key'], $identity->userId);
                } elseif ($identity->isPlatformAdmin || $standaloneDemo) {
                    $tenant = $this->tenantRepo->findById($activeTenantId);
                    if ($tenant !== null && $tenant->isActive()) {
                        $this->tenantContext->setTenant($tenant, 'platform_super_admin', $identity->userId);
                    }
                }
            }
        } elseif ($standaloneDemo) {
            $this->tenantRepo->addMembership($defaultTenant->id, 1, 'tenant_owner');
            $this->tenantContext->setTenant($defaultTenant, 'platform_super_admin', 1);
        }

        // 4. Core Services
        $this->audit = new AuditService($this->db);
        $this->assetUploader = new AssetUploader($this->db, $this->storage);
        $this->webhookService = new WebhookService($this->db);
        $this->templateService = new TemplateService($this->db, $this->tenantContext);
        if ($this->tenantContext->hasTenant()) {
            $this->initDefaultTemplate();
        }

        $this->renderer = new LocalCertificateRenderer();
        $this->issuanceService = new CertificateIssuanceService(
            $this->db,
            $this->tenantContext,
            $this->authorizer,
            $this->templateService,
            $this->renderer,
            $this->storage,
            $this->audit
        );
        $this->dynamicFormService = new DynamicFormService($this->db);
        $this->bulkImportEngine = new BulkImportEngine(
            $this->db,
            $this->tenantContext,
            $this->authorizer,
            $this->issuanceService
        );
        $this->formApprovalService = new FormApprovalService(
            $this->db,
            $this->tenantContext,
            $this->authorizer,
            $this->issuanceService,
            $this->audit
        );
        $this->jobScheduler = new JobScheduler($this->db);
        $this->scheduleProcessor = new ScheduleProcessor(
            $this->db,
            $this->tenantContext,
            $this->authorizer,
            $this->jobScheduler,
            $this->issuanceService,
            $this->audit
        );

        $this->verificationPolicy = new VerificationPolicy($this->db);
        $this->verificationService = new VerificationService($this->db, $this->verificationPolicy);
        $this->healthService = new HealthService($this->db, $storageDir, self::VERSION);
        $this->systemDiagnostics = new SystemDiagnostics(
            $this->db,
            $this->migrationRunner,
            $this->healthService,
            $storageDir
        );
        $this->apiClientService = new ApiClientService($this->db);
        $this->idempotencyService = new IdempotencyService($this->db);

        // 5. Router & Dispatch
        $this->router = new Router($baseWebPath);
        $this->registerRoutes();
    }

    public static function init(string $baseDir, string $baseWebPath = ''): self
    {
        if (self::$instance === null) {
            self::$instance = new self($baseDir, $baseWebPath);
        }
        return self::$instance;
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    public function getApiClientContext(): ?array
    {
        return $this->apiClientContext;
    }

    public function initDefaultTenant(): \SOI\Certificates\Tenancy\Tenant
    {
        $existing = $this->tenantRepo->findBySlug('school-of-interns');
        if ($existing !== null) {
            return $existing;
        }
        $first = $this->tenantRepo->findFirstActive();
        if ($first !== null) {
            return $first;
        }

        $siteName = 'School Of Interns (SOI)';
        if (class_exists('\SOI\Core\Database') && method_exists('\SOI\Core\Database', 'getOption')) {
            try {
                $opt = \SOI\Core\Database::getOption('site_name');
                if (!empty($opt) && is_string($opt)) {
                    $siteName = $opt;
                }
            } catch (\Throwable) {}
        }

        return $this->tenantRepo->create(
            'school-of-interns',
            $siteName,
            ['primary_color' => '#1e3a8a', 'logo' => 'soi_logo.png']
        );
    }

    public function initDefaultTemplate(): void
    {
        if (!$this->tenantContext->hasTenant()) {
            return;
        }
        $templates = $this->templateService->getTenantTemplates();
        if (empty($templates)) {
            $tpl = $this->templateService->createTemplate('leadership-excellence', 'Leadership Excellence Award', 'Leadership');
            $this->templateService->publish($tpl->id, 1);
        }
    }

    protected function registerRoutes(): void
    {
        $r = $this->router;
        $requirePermission = fn(string $permission): callable => function () use ($permission): bool {
            if (!$this->authorizer->can($permission)) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                echo '403 Forbidden';
                return false;
            }
            return true;
        };

        // Base redirect
        $r->get('/', function() {
            http_response_code(302);
            header('Location: ' . $this->router->url('/console'));
        });

        // Section 6.1 /admin clarification: canonical tenant administration surface is /manage
        $r->get('/admin', function() {
            http_response_code(302);
            header('Location: ' . $this->router->url('/manage'));
        });

        // Surface: /super-admin
        $superAdmin = new SuperAdminController($this);
        $r->get('/super-admin', [$superAdmin, 'index'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::PLATFORM_READ)]);
        $r->post('/super-admin/tenants/create', [$superAdmin, 'createTenant'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::PLATFORM_MANAGE)]);
        $r->post('/super-admin/tenants/suspend', [$superAdmin, 'suspendTenant'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::PLATFORM_MANAGE)]);
        $r->post('/super-admin/migrations/run', [$superAdmin, 'runMigrations'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::PLATFORM_MANAGE)]);

        // Surface: /manage
        $manage = new ManageController($this);
        $r->get('/manage', [$manage, 'index'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::TEMPLATES_READ)]);
        $r->get('/manage/templates', [$manage, 'index'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::TEMPLATES_READ)]);
        $r->get('/manage/templates/{id}/designer', [$manage, 'designer'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::TEMPLATES_READ)]);
        $r->post('/manage/templates/create', [$manage, 'createTemplate'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::TEMPLATES_CREATE)]);
        $r->post('/manage/templates/clone', [$manage, 'cloneTemplate'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::TEMPLATES_CREATE)]);
        $r->post('/manage/templates/archive', [$manage, 'archiveTemplate'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::TEMPLATES_ARCHIVE)]);
        $r->post('/manage/templates/save-draft', [$manage, 'saveTemplateDraft'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::TEMPLATES_UPDATE)]);
        $r->post('/manage/templates/publish', [$manage, 'publishTemplate'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::TEMPLATES_PUBLISH)]);
        $r->post('/manage/settings/branding', [$manage, 'saveBranding'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::SETTINGS_MANAGE)]);
        $r->post('/manage/settings/verification', [$manage, 'saveVerificationPolicy'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::VERIFICATION_MANAGE)]);
        $r->post('/manage/members/add', [$manage, 'addMember'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::ROLES_MANAGE)]);
        $r->post('/manage/members/role', [$manage, 'updateMemberRole'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::ROLES_MANAGE)]);
        $r->post('/manage/members/deactivate', [$manage, 'deactivateMember'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::ROLES_MANAGE)]);
        $r->post('/manage/forms/submissions/{id}/approve', [$manage, 'approveFormSubmission'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_ISSUE)]);
        $r->post('/manage/forms/submissions/{id}/reject', [$manage, 'rejectFormSubmission'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::FORMS_MANAGE)]);
        $r->post('/manage/forms/create', [$manage, 'createForm'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::FORMS_MANAGE)]);
        $r->post('/manage/assets/upload', [$manage, 'uploadAsset'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::TEMPLATES_UPDATE)]);
        $r->post('/manage/assets/{id}/delete', [$manage, 'deleteAsset'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::TEMPLATES_UPDATE)]);
        $r->get('/manage/assets/{id}', [$manage, 'showAsset'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::TEMPLATES_READ)]);
        $r->post('/manage/webhooks/create', [$manage, 'createWebhook'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::WEBHOOKS_MANAGE)]);
        $r->post('/manage/webhooks/run', [$manage, 'runWebhooks'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::WEBHOOKS_MANAGE)]);
        $r->post('/manage/api/clients/create', [$manage, 'createApiClient'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::API_CLIENTS_MANAGE)]);
        $r->post('/manage/api/clients/revoke', [$manage, 'revokeApiClient'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::API_CLIENTS_MANAGE)]);
        $r->post('/manage/schedules/create', [$manage, 'createSchedule'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::SCHEDULES_MANAGE)]);
        $r->post('/manage/schedules/status', [$manage, 'updateScheduleStatus'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::SCHEDULES_MANAGE)]);
        $r->post('/manage/schedules/run', [$manage, 'runSchedules'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::SCHEDULES_MANAGE)]);
        $r->post('/manage/bulk/create', [$manage, 'createBulkBatch'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_ISSUE)]);
        $r->post('/manage/bulk/process', [$manage, 'processBulkBatch'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_ISSUE)]);
        $publicForms = new FormController($this);
        $r->get('/forms/{form_key}', [$publicForms, 'show']);
        $r->post('/forms/{form_key}', [$publicForms, 'submit']);
        $schedulerRunner = new SchedulerRunnerController($this);
        $r->post('/scheduler/run', [$schedulerRunner, 'run'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::SCHEDULES_MANAGE)]);

        // Surface: /console
        $console = new ConsoleController($this);
        $certificateDownloads = new CertificateDownloadController($this);
        $r->get('/console', [$console, 'index'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_READ)]);
        $r->post('/console/issue', [$console, 'issueCertificate'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_ISSUE)]);
        $r->get('/console/certificates/{id}', [$console, 'detailCertificate'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_READ)]);
        $r->get('/console/certificates/{id}/download', [$certificateDownloads, 'download'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_DOWNLOAD)]);
        $r->get('/manage/reports/certificates.csv', [$console, 'exportRegistry'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::REPORTS_EXPORT)]);
        $r->post('/console/certificates/{id}/revoke', [$console, 'revokeCertificate'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_REVOKE)]);
        $r->post('/console/certificates/{id}/replace', [$console, 'replaceCertificate'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_REPLACE)]);

        // Surface: /verify/{token}
        $verify = new VerifyController($this);
        $r->get('/verify/{token}', [$verify, 'verify']);
        $r->post('/verify/{token}', [$verify, 'verifyWithPin']);

        // Surface: /docs
        $docs = new DocsController($this);
        $r->get('/docs', [$docs, 'index'], [$requirePermission(\SOI\Certificates\Authorization\Permissions::TEMPLATES_READ)]);

        // Surface: /api/v1
        $apiAuth = new ApiClientAuth($this->apiClientService);
        $scopeMiddleware = new ScopeMiddleware();
        $apiMiddleware = function (string $scope) use ($apiAuth, $scopeMiddleware): callable {
            return function () use ($scope, $apiAuth, $scopeMiddleware): callable|false {
                $client = $apiAuth->authenticateRequest();
                if ($client === null) {
                    http_response_code(401);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => ['code' => 'UNAUTHENTICATED', 'message' => 'Valid bearer credentials are required.']]);
                    return false;
                }
                if (!$scopeMiddleware->allows($client, $scope)) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => ['code' => 'INSUFFICIENT_SCOPE', 'message' => 'The API client lacks the required scope.']]);
                    return false;
                }

                $tenant = $this->tenantRepo->findById((int)$client['tenant_id']);
                if ($tenant === null || !$tenant->isActive()) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['error' => ['code' => 'TENANT_UNAVAILABLE', 'message' => 'The API client tenant is unavailable.']]);
                    return false;
                }

                $previousContext = $this->tenantContext->snapshot();
                $previousClient = $this->apiClientContext;
                $previousScopes = null;
                $this->tenantContext->setTenant($tenant, 'api_client', null);
                $this->authorizer->setApiScopes($client['scopes']);
                $this->apiClientContext = $client;
                return function () use ($previousContext, $previousScopes, $previousClient): void {
                    $this->tenantContext->restore($previousContext);
                    $this->authorizer->setApiScopes($previousScopes);
                    $this->apiClientContext = $previousClient;
                };
            };
        };

        $moduleApi = new \SOI\Certificates\Api\ModuleApiController($this);

        $r->get('/api/v1/health', function() {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['data' => $this->healthService->runChecks()]);
        }, [$apiMiddleware(\SOI\Certificates\Authorization\Permissions::PLATFORM_READ)]);

        // Module 1: Templates
        $r->get('/api/v1/templates', [$moduleApi, 'listTemplates'], [$apiMiddleware(\SOI\Certificates\Authorization\Permissions::TEMPLATES_READ)]);
        $r->post('/api/v1/templates', [$moduleApi, 'createTemplate'], [$apiMiddleware(\SOI\Certificates\Authorization\Permissions::TEMPLATES_CREATE)]);
        $r->get('/api/v1/templates/{id}', [$moduleApi, 'getTemplate'], [$apiMiddleware(\SOI\Certificates\Authorization\Permissions::TEMPLATES_READ)]);
        $r->put('/api/v1/templates/{id}', [$moduleApi, 'updateTemplate'], [$apiMiddleware(\SOI\Certificates\Authorization\Permissions::TEMPLATES_UPDATE)]);

        // Module 2: Certificates
        $r->get('/api/v1/certificates/{id}', [$moduleApi, 'getCertificate'], [$apiMiddleware(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_READ)]);
        $r->post('/api/v1/certificates', function() {
            header('Content-Type: application/json; charset=utf-8');
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true);
            $requestId = 'req_' . bin2hex(random_bytes(8));
            if (!is_array($data)) {
                http_response_code(400);
                echo json_encode(['error' => ['code' => 'INVALID_JSON', 'message' => 'The request body must be a JSON object.'], 'request_id' => $requestId]);
                return;
            }

            $idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '';
            $tenantId = $this->tenantContext->getTenantId();
            $clientId = (int)($this->apiClientContext['id'] ?? 0);
            $fingerprint = $this->idempotencyService->fingerprint($data, $clientId);
            if ($idempotencyKey === '') {
                http_response_code(400);
                echo json_encode(['error' => ['code' => 'IDEMPOTENCY_REQUIRED', 'message' => 'An Idempotency-Key header is required.'], 'request_id' => $requestId]);
                return;
            }
            try {
                $replay = $this->idempotencyService->replayOrReserve($tenantId, $idempotencyKey, $fingerprint);
                if ($replay !== null) {
                    http_response_code($replay['status']);
                    echo $replay['body'];
                    return;
                }
            } catch (\InvalidArgumentException $e) {
                http_response_code(400);
                echo json_encode(['error' => ['code' => 'IDEMPOTENCY_INVALID', 'message' => $e->getMessage()], 'request_id' => $requestId]);
                return;
            } catch (\RuntimeException $e) {
                http_response_code(409);
                echo json_encode(['error' => ['code' => 'REQUEST_IN_PROGRESS', 'message' => $e->getMessage()], 'request_id' => $requestId]);
                return;
            }

            try {
                $cmd = new \SOI\Certificates\Issuance\IssuanceCommand(
                    (int)($data['template_id'] ?? 0),
                    (string)($data['recipient_name'] ?? ''),
                    (array)($data['variables'] ?? []),
                    $data['recipient_email'] ?? null,
                    $data['issue_date'] ?? null,
                    $data['expires_at'] ?? null,
                    'api'
                );
                $cert = $this->issuanceService->issue($cmd, $this->tenantContext->getCurrentUserId());
                http_response_code(201);
                $response = [
                    'data' => [
                        'certificate_number' => $cert->certificateNumber,
                        'status' => $cert->status,
                        'file_sha256' => $cert->fileSha256,
                    ],
                    'request_id' => $requestId
                ];
                $this->idempotencyService->complete($tenantId, $idempotencyKey, $fingerprint, 201, $response);
                echo json_encode($response);
            } catch (\Throwable $e) {
                error_log('SOI certificate API issuance failed: ' . $e->getMessage());
                http_response_code(422);
                $response = [
                    'error' => ['code' => 'ISSUANCE_FAILED', 'message' => 'The certificate could not be issued with the supplied request.'],
                    'request_id' => $requestId
                ];
                $this->idempotencyService->complete($tenantId, $idempotencyKey, $fingerprint, 422, $response);
                echo json_encode($response);
            }
        }, [$apiMiddleware(\SOI\Certificates\Authorization\Permissions::CERTIFICATES_ISSUE)]);

        // Module 3: Users
        $r->get('/api/v1/users', [$moduleApi, 'listUsers'], [$apiMiddleware(\SOI\Certificates\Authorization\Permissions::USERS_READ)]);
        $r->post('/api/v1/users', [$moduleApi, 'createUser'], [$apiMiddleware(\SOI\Certificates\Authorization\Permissions::USERS_MANAGE)]);
        $r->get('/api/v1/users/{id}', [$moduleApi, 'getUser'], [$apiMiddleware(\SOI\Certificates\Authorization\Permissions::USERS_READ)]);
        $r->put('/api/v1/users/{id}', [$moduleApi, 'updateUser'], [$apiMiddleware(\SOI\Certificates\Authorization\Permissions::USERS_MANAGE)]);

        // Module 4: Roles
        $r->get('/api/v1/roles', [$moduleApi, 'listRoles'], [$apiMiddleware(\SOI\Certificates\Authorization\Permissions::ROLES_READ)]);
        $r->post('/api/v1/roles', [$moduleApi, 'createRole'], [$apiMiddleware(\SOI\Certificates\Authorization\Permissions::ROLES_MANAGE)]);
        $r->put('/api/v1/roles/{id}', [$moduleApi, 'updateRole'], [$apiMiddleware(\SOI\Certificates\Authorization\Permissions::ROLES_MANAGE)]);

        // Module 5: Verification
        $r->get('/api/v1/verification/{token}', [$moduleApi, 'verify']);
    }

    public function handleRequest(?string $method = null, ?string $uri = null): void
    {
        $this->router->dispatch($method, $uri);
    }
}
