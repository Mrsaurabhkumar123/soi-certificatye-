# Development Status

Product version: 1.0.0-dev
Schema version: 2026100701
Current cumulative ZIP: updates/certificates-1.0.0-prompt1.zip

## Completed in this phase
- [x] High-level architectural flow design and developer team task delegation document (`docs/architecture-flow.md`).
- [x] Zero-dependency PSR-4 autoloader (`src/Core/Autoloader.php`) mapping `SOI\Certificates\` namespace.
- [x] Core plugin bootstrap container (`src/Core/Plugin.php`) and module registry (`src/Core/ModuleRegistry.php`).
- [x] Database abstraction layer (`src/Core/Database.php`) supporting table prefixes, prepared statements, transactions, and dual MySQL/SQLite engine support for portable local zero-config execution.
- [x] Migration registry and runner (`src/Core/MigrationRunner.php`) with version-gated idempotent migrations and web runner capability.
- [x] Baseline database migrations for all 18 platform entities (`migrations/001_initial_schema.sql`).
- [x] Health diagnostic service (`src/Core/HealthService.php`) validating PHP runtime, database connectivity, schema version, storage directory writability, and local renderer capability.
- [x] Secure session and CSRF token manager (`src/Core/Session.php`).
- [x] Core regex Router (`src/Core/Router.php`) with softcoded URL generation (`/super-admin`, `/manage`, `/console`, `/docs`, `/verify/{token}`, `/api/v1/...`).
- [x] Multi-tenancy context isolation (`src/Tenancy/TenantContext.php`, `TenantRepository.php`).
- [x] Granular RBAC authorizer (`src/Authorization/Authorizer.php`, `Permissions.php`).
- [x] Append-only audit logging service (`src/Audit/AuditService.php`).
- [x] Local storage adapter (`src/Storage/LocalStorageAdapter.php`) with path-traversal guards.
- [x] Native local PDF generation engine (`src/Rendering/LocalCertificateRenderer.php`) and local QR code generator (`src/Rendering/QrCodeGenerator.php`).
- [x] Unified `CertificateIssuanceService` (`src/Issuance/CertificateIssuanceService.php`) enforcing template snapshotting, sequence locking, and token generation.
- [x] Authoritative verification engine (`src/Verification/VerificationService.php`) supporting 5 privacy modes.
- [x] Developer and operator web shells: `/super-admin`, `/manage`, `/console`, `/docs`, `/verify/{token}`.
- [x] Versioned REST API (`/api/v1/`) with Bearer token authentication and JSON response envelopes.
- [x] Automated test runner (`tests/run_tests.php`) and standalone interactive developer server (`index.php`).
- [x] Cumulative Update Center packaging script (`package.php`) and plugin manifest (`manifest.json`).

## Fixed in this phase
- Baseline setup initiated from clean repository specifications.

## Partially completed
- Visual drag-and-drop designer canvas (HTML/CSS foundation and structured JSON preview implemented; interactive UI drag controls scheduled for Dev 2 in Prompt 4).
- Chunked CSV/XLSX bulk processor (architecture specified; browser worker runner scheduled for Dev 6 in Prompt 10).

## Not started / next prompt
- Prompt 2: Deep dive into multi-tenancy invitation workflows and tenant configuration panels.
- Prompt 3 & 4: Visual Certificate Designer canvas advanced editing controls.

## Known defects and severity
- None. (Zero severity-1 or severity-2 defects).

## Routes added/changed
- `GET /` -> Redirect to `/console` or portal dashboard
- `GET /super-admin` -> Platform administrator overview & tenant control
- `POST /super-admin/tenants/create` -> Create new tenant
- `POST /super-admin/migrations/run` -> Run database migrations through web UI
- `GET /manage` -> Tenant administrator management dashboard
- `GET /console` -> Certificate issuer portal (instant issuance & download)
- `POST /console/issue` -> Execute issuance via unified `CertificateIssuanceService`
- `GET /docs` -> Interactive developer and admin documentation portal
- `GET /verify/{token}` -> Public/protected certificate verification
- `GET /api/v1/health` -> System health check JSON
- `GET /api/v1/certificates/{id}` -> REST certificate inspection
- `POST /api/v1/certificates` -> REST programmatic certificate issuance

## Database migrations
- `001_initial_schema.sql`: Creates `cert_tenants`, `cert_memberships`, `cert_roles`, `cert_role_permissions`, `cert_settings`, `cert_templates`, `cert_template_versions`, `cert_template_assets`, `cert_certificates`, `cert_certificate_events`, `cert_forms`, `cert_form_submissions`, `cert_api_clients`, `cert_idempotency`, `cert_webhooks`, `cert_webhook_deliveries`, `cert_schedules`, `cert_jobs`, and `cert_audit_log`.

## Authorization changes
- Centralized RBAC authorizer initialized with default roles: `platform_admin`, `tenant_owner`, `tenant_admin`, `template_designer`, `issuer`, `viewer`.

## Test suites and counts
- Unit & Multi-Tenant Isolation Suite: 17 Passed, 0 Failed (`tests/run_tests.php`).
- HTTP Smoke Test Suite: 8 Passed, 0 Failed (`tests/http_smoke_test.php`).

## Browser/HTTP smoke results
- All core shells (`/super-admin`, `/manage`, `/console`, `/docs`, `/verify/...`) return valid 200 HTTP responses without unhandled exceptions.

## Packaging validation
- Cumulative packaging script produces `updates/certificates-1.0.0-prompt1.zip` matching SOI CMS Update Center manifest conventions.
- Package file count: 57 files.
- Package SHA-256: `bf8d738a674fe9e4fb293f2bc399d6908f3c70bde3e6ee23d20c93b54a927c21`.


## Deployment/activation notes
- Fully self-hosted, web-installable, no CLI/SSH required.

## Architectural decisions that must be preserved
1. Row-level multi-tenancy enforced at the repository query boundary with indexed `tenant_id`.
2. All certificate issuance paths MUST funnel through `CertificateIssuanceService`.
3. Zero external cloud rendering or queue services in v1.
