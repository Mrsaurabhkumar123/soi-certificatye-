<?php
declare(strict_types=1);

namespace SOI\Certificates\Core;

use SOI\Certificates\Audit\AuditService;
use SOI\Certificates\Authorization\Authorizer;
use SOI\Certificates\Http\ConsoleController;
use SOI\Certificates\Http\DocsController;
use SOI\Certificates\Http\ManageController;
use SOI\Certificates\Http\SuperAdminController;
use SOI\Certificates\Http\VerifyController;
use SOI\Certificates\Issuance\CertificateIssuanceService;
use SOI\Certificates\Rendering\LocalCertificateRenderer;
use SOI\Certificates\Storage\LocalStorageAdapter;
use SOI\Certificates\Templates\TemplateService;
use SOI\Certificates\Tenancy\TenantContext;
use SOI\Certificates\Tenancy\TenantRepository;
use SOI\Certificates\Verification\VerificationService;

/**
 * Main application container & bootstrap coordinator for SOI Certificate Platform.
 */
class Plugin
{
    public const VERSION = '1.0.0';
    public const SCHEMA_VERSION = '2026100701';

    protected static ?Plugin $instance = null;

    public Database $db;
    public MigrationRunner $migrationRunner;
    public LocalStorageAdapter $storage;
    public TenantRepository $tenantRepo;
    public TenantContext $tenantContext;
    public Authorizer $authorizer;
    public AuditService $audit;
    public TemplateService $templateService;
    public LocalCertificateRenderer $renderer;
    public CertificateIssuanceService $issuanceService;
    public VerificationService $verificationService;
    public HealthService $healthService;
    public Router $router;
    public string $baseDir;

    public function __construct(string $baseDir, string $baseWebPath = '')
    {
        $this->baseDir = rtrim($baseDir, '/\\');

        // 1. Storage setup
        $storageDir = $this->baseDir . '/storage';
        $this->storage = new LocalStorageAdapter($storageDir);

        // 2. Database & Migrations
        $this->db = Database::createDefault($storageDir . '/data');
        $this->migrationRunner = new MigrationRunner($this->db, $this->baseDir . '/migrations');

        // Auto-run baseline migrations if empty
        if (count($this->migrationRunner->getPendingMigrations()) > 0) {
            $this->migrationRunner->runPending();
        }

        // 3. Tenancy & Auth
        $this->tenantRepo = new TenantRepository($this->db);
        $this->initDefaultTenant();

        $defaultTenant = $this->tenantRepo->findBySlug('school-of-interns');
        $this->tenantContext = new TenantContext($defaultTenant, 1, 'tenant_owner');
        $this->authorizer = new Authorizer($this->tenantContext, true); // Admin bootstrap enabled

        // 4. Core Services
        $this->audit = new AuditService($this->db);
        $this->templateService = new TemplateService($this->db, $this->tenantContext);
        $this->initDefaultTemplate();

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

        $this->verificationService = new VerificationService($this->db);
        $this->healthService = new HealthService($this->db, $storageDir, self::VERSION);

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

    protected function initDefaultTenant(): void
    {
        if (!$this->tenantRepo->findBySlug('school-of-interns')) {
            $this->tenantRepo->create(
                'school-of-interns',
                'School Of Interns (SOI)',
                ['primary_color' => '#1e3a8a', 'logo' => 'soi_logo.png']
            );
        }
    }

    protected function initDefaultTemplate(): void
    {
        $templates = $this->templateService->getTenantTemplates();
        if (empty($templates)) {
            $tpl = $this->templateService->createTemplate('leadership-excellence', 'Leadership Excellence Award', 'Leadership');
            $this->templateService->publish($tpl->id, 1);
        }
    }

    protected function registerRoutes(): void
    {
        $r = $this->router;

        // Base redirect
        $r->get('/', function() {
            header('Location: ' . $this->router->url('/console'));
            exit;
        });

        // Surface: /super-admin
        $superAdmin = new SuperAdminController($this);
        $r->get('/super-admin', [$superAdmin, 'index']);
        $r->post('/super-admin/tenants/create', [$superAdmin, 'createTenant']);
        $r->post('/super-admin/tenants/suspend', [$superAdmin, 'suspendTenant']);
        $r->post('/super-admin/migrations/run', [$superAdmin, 'runMigrations']);

        // Surface: /manage
        $manage = new ManageController($this);
        $r->get('/manage', [$manage, 'index']);
        $r->post('/manage/templates/create', [$manage, 'createTemplate']);
        $r->post('/manage/templates/publish', [$manage, 'publishTemplate']);

        // Surface: /console
        $console = new ConsoleController($this);
        $r->get('/console', [$console, 'index']);
        $r->post('/console/issue', [$console, 'issueCertificate']);
        $r->get('/console/certificates/{id}/download', [$console, 'downloadCertificate']);
        $r->post('/console/certificates/{id}/revoke', [$console, 'revokeCertificate']);

        // Surface: /verify/{token}
        $verify = new VerifyController($this);
        $r->get('/verify/{token}', [$verify, 'verify']);

        // Surface: /docs
        $docs = new DocsController($this);
        $r->get('/docs', [$docs, 'index']);

        // Surface: /api/v1
        $r->get('/api/v1/health', function() {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['data' => $this->healthService->runChecks()]);
        });

        $r->get('/api/v1/templates', function() {
            header('Content-Type: application/json; charset=utf-8');
            $list = $this->templateService->getPublishedTemplates();
            echo json_encode(['data' => array_map(fn($t) => ['id' => $t->id, 'name' => $t->name, 'slug' => $t->slug], $list)]);
        });

        $r->post('/api/v1/certificates', function() {
            header('Content-Type: application/json; charset=utf-8');
            $raw = file_get_contents('php://input');
            $data = json_decode($raw, true) ?: [];

            try {
                $cmd = new \SOI\Certificates\Issuance\IssuanceCommand(
                    (int)($data['template_id'] ?? 1),
                    (string)($data['recipient_name'] ?? ''),
                    (array)($data['variables'] ?? []),
                    $data['recipient_email'] ?? null,
                    $data['issue_date'] ?? null,
                    $data['expires_at'] ?? null,
                    'api'
                );
                $cert = $this->issuanceService->issue($cmd, 1);
                http_response_code(201);
                echo json_encode([
                    'data' => [
                        'certificate_number' => $cert->certificateNumber,
                        'verification_token' => $cert->verificationToken,
                        'status' => $cert->status,
                        'file_sha256' => $cert->fileSha256,
                    ],
                    'request_id' => 'req_' . bin2hex(random_bytes(8))
                ]);
            } catch (\Throwable $e) {
                http_response_code(422);
                echo json_encode([
                    'error' => ['code' => 'ISSUANCE_FAILED', 'message' => $e->getMessage()],
                    'request_id' => 'req_' . bin2hex(random_bytes(8))
                ]);
            }
        });
    }

    public function handleRequest(): void
    {
        $this->router->dispatch();
    }
}
