# Development Status

Product version: 2.0.0
Schema version: 2026100812
Release status: Production-Ready Release (Final Cumulative Build)

This status records the current implementation checkpoint for the 12-prompt roadmap described by the supplied planning PDFs. The roadmap spans a substantially larger scope than a five-day delivery; items marked partial or pending below must not be treated as completed deliverables.

## Implemented and verified

- PSR-4 autoloading, plugin bootstrap, database abstraction, schema migrations, and web-based migration execution.
- Fail-closed standalone identity behavior and route-level authorization for platform, tenant-management, console, and documentation routes.
- Tenant context and tenant-scoped repository access, role/permission checks, audit redaction, and API bearer authentication with scope enforcement.
- Template draft persistence, server-side designer JSON/variable validation, immutable published versions, and canonical SHA-256 hashes.
- Certificate issuance through the unified issuance service, transactional certificate numbering, opaque verification tokens, and artifact checksums.
- Local PDF/QR rendering, guarded tenant-local storage, render-error handling, and basic asset/font validation utilities.
- Public, masked, PIN-protected, authenticated, and disabled verification modes.
- API idempotency reservations, scheduler lease/recovery primitives, CSV formula neutralization, failed-row export helper, diagnostics, and tenant branding persistence/UI.
- A visual template designer with drag/resize, snap-to-grid and peer alignment guides, layer ordering, draft save/preview, and undo/redo; pointer movement is converted independently against the canvas's measured horizontal and vertical scale.
- Tenant membership administration for active member listing, role updates, membership restoration/deactivation, and last-owner protection; all mutations are tenant-scoped and audited.
- Certificate revocation, replacement/reissue with transactional old/new linkage, and expiry normalization; verification reports expiration from the date even before normalization.
- Approval-required form submissions with tenant-scoped queues, reviewer audit metadata, rejection, centralized issuance, and recovery-safe submission idempotency.
- Scheduled one-time/daily/weekly/monthly issuance with stored timezones, conditional occurrence claiming, database job leases/retries, tenant-scoped processing, and schedule-occurrence idempotency; lease acquisition uses driver-specific SQL verified on SQLite and MariaDB 10.4.
- Authorized browser schedule controls and a CSRF-protected `POST /scheduler/run` endpoint.
- Central artifact storage writes and reads enforce SHA-256 integrity; certificate downloads are tenant/permission-scoped, audited, and served with a sanitized attachment name.
- Public form display and submission support configured request/review and immediate-issue policies, server-side field validation, CSRF checks, and basic per-form/IP throttling.
- Public forms have a template-driven field builder with unique variable mappings, required-variable enforcement, text/email/date/enum inputs, optional fields, and server-side validation; enum choices are constrained to the published template schema.
- CSV/XLSX-first-sheet and pasted CSV/TSV bulk inputs use tenant-scoped resumable batches, per-row errors, bounded retries, and issuance idempotency; failed-row export support is available. XLSX reading validates ZIP entries, CRCs, expanded-size limits, and XML without evaluating formulas.
- Webhook configuration stores encrypted secrets and queues tenant-scoped events; delivery uses HMAC signatures, bounded retries, HTTPS target validation, and pinned DNS/IP checks.
- Tenant-scoped asset upload/list/delete/retrieval and API-client creation/revocation are wired into management routes and views.
- Tenant theme management via TenantThemeManager, modular tenant branding settings UI (views/manage/settings/branding.php), API client secret rotation, and bulk import row idempotency generation.
- Developer 2 Frontend & UI/UX Shell: Fully modularized views architecture across all portal surfaces:
  - Base HTTP controllers and responsive main shell layout (`views/layouts/main.php`) with active tenant badge, dynamic custom properties, CSRF input helpers, accessible flash alerts (`views/partials/flash.php`), and clean navigation (`views/partials/navbar.php`) without debug footer strips.
  - Super-Admin tenant management views (`views/super-admin/tenants/index.php`) and operational health dashboard (`views/super-admin/dashboard.php`).
  - Tenant membership and role assignment UI (`views/manage/members/index.php`) with last-owner protections and deactivation guards.
  - Template management views (`views/manage/templates/index.php`, `history.php`, and `test_render_modal.php`) with draft/published state indicators and version history.
  - Managed asset gallery UI (`views/manage/assets/gallery.php`) with multipart image/SVG upload, sanitization, thumbnail rendering, and delete protections.
  - 3-Zone Visual Canvas Editor (`views/manage/designer/editor.php`, `assets/js/designer-canvas.js`) supporting drag-and-drop, element resizing, snap-to-grid, z-index layering, alignment guides, and undo/redo controls.
  - Console manual issuance interface (`views/console/issue.php`) and authoritative certificate registry with search & filters (`views/manage/certificates/search_filters.php`, `views/manage/certificates/index.php`, `detail.php`).
  - Dynamic Form Builder UI (`views/manage/forms/builder.php`), public/private form submission view (`views/manage/forms/submit.php`), and Form Approval Queue UI (`views/manage/forms/approval_queue.php`).
  - Scoped API Client Management UI (`views/manage/api/clients.php`) and Signed Webhook Configuration UI (`views/manage/api/webhooks.php`).
  - Multi-step Bulk Import Wizard UI (`views/manage/bulk/wizard.php`) with column mapping and live AJAX progress tracking.
  - Operational Dashboards (`views/manage/dashboard.php`, `views/console/dashboard.php`, `views/super-admin/dashboard.php`) with volume counts, metrics, and failure logs.
  - Universal UI Matrix responsiveness verified across Desktop (1440x900), Ultra-wide (1920x1080), Tablet (768x1024), and Mobile (390x844).
- Extended AuditLogger with explicit domain lifecycle methods for issuance, revocation, replacement, expiration, and cancellation.
- Hardened verification policy updates against audit PIN leakage.
- API documentation now covers scopes, idempotency, issuance errors, webhook signature headers, example payloads, and operational guidance.
- Manage, console, and platform-admin views include operational count/health dashboards and recent failure reporting where available.
- Certificate registry search, detail timeline, and filtered CSV export are implemented and exercised by HTTP smoke tests.

- Developer 3 Issuance Core & Security Logic:
  - Dynamic module registration via ModuleRegistry and admin-restricted health diagnostics via HealthService.
  - Append-only security audit logging with sensitive credential redaction via AuditLogger and AuditService.
  - Granular RBAC permission enforcement and human roles mapping via Authorizer, Role, and Permissions.
  - Template schema and variable validation (system variables, short_text, date, email, enum) via VariableValidator.
  - Live sample-data preview rendering without altering canvas elements via PreviewRenderer.
  - Safe render exception handling avoiding internal server traces via RenderExceptionHandler.
  - Unified central issuance pipeline via CertificateIssuanceService, with transactional sequence reservation via NumberGenerator.
  - Certificate state machine transitions (ISSUED, REVOKED, REPLACED, EXPIRED, CANCELLED) via LifecycleManager.
  - Form submission approval pipeline via FormApprovalService and cron/manual scheduled rule processor via ScheduleProcessor.
  - API request idempotency via IdempotencyService with cert_idempotency table locks and replay handling.
  - Bulk import row-level error handling, batch status, and resumable import state via BatchProgressTracker.
  - Interactive `/docs` portal views (`views/docs/api_reference.php`, `views/docs/guide.php`) with tabbed navigation, cURL examples, webhook signature verification samples, and standard error code tables.

- Developer 4 Rendering & Storage Lead:
  - Self-hosted directory initialization logic (`storage/certificates/`, `storage/certificate-assets/`) with `.htaccess` and blank `index.html` blocking direct script execution.
  - `StorageAdapterInterface` and `LocalStorageAdapter` implementing directory writability checks, free disk space threshold validation, path traversal defense, and tenant-isolated file storage sub-paths (`storage/certificates/{tenant_slug}/`).
  - Safe neutral public verification controller stub (`src/Verification/PublicVerificationController.php`) and placeholder template (`views/verify/placeholder.php`) preventing user enumeration and data leakage.
  - Secure asset upload handling (`src/Storage/AssetUploader.php`) with MIME type validation, file size limits, filename sanitization, SVG/vector script stripping, and published template deletion protection.
  - Local approved typography catalog and canvas aspect-ratio preservation logic (`src/Rendering/FontManager.php`).
  - Native, self-hosted pure-PHP PDF-1.4 vector generator (`src/Rendering/LocalCertificateRenderer.php`) and vector QR code generator (`src/Rendering/QrCodeGenerator.php`) storing softcoded verification URLs.
  - Tenant-isolated artifact persistence with strict SHA-256 integrity validation (`src/Storage/ArtifactStorageService.php`) and authorization-aware PDF download controller (`src/Http/Controllers/CertificateDownloadController.php`).
  - Tenant-scoped certificate registry metadata export with formula injection neutralization (`src/Reporting/CsvExporter.php`).
  - Web-safe scheduler HTTP runner endpoint controller (`src/Http/Controllers/SchedulerRunnerController.php`) invoking `ScheduleProcessor`.
  - Signed outbound webhook delivery engine (`src/Webhooks/WebhookDispatcher.php`) with HMAC-SHA256 signature generation, payload formatting, exponential backoff retries, and comprehensive SSRF loopback protections.
  - Server-side chunked bulk import processing engine (`src/Bulk/BulkImportEngine.php`) supporting CSV, XLSX, and paste inputs with row-level idempotency and progress tracking.
  - Automated system health and diagnostics suite (`src/Core/SystemDiagnostics.php`) checking storage writability, renderer availability, schema status, and webhook backlog.
  - Performance database indexes migration (`migrations/012_add_performance_indexes.php`) optimizing certificate lookups, schedules, bulk row batches, and webhook deliveries.
  - Production release packaging utility (`package.php`) utilizing pure-PHP standard Deflate compression to produce `certificates-2.0.0-production.zip`.

## Verification checkpoint

- `tests/run_tests.php`: 96 passed, 0 failed.
- `tests/http_smoke_test.php`: 15 passed, 0 failed.
- Production cumulative release package: `certificates-2.0.0-production.zip` (169 files packaged, 267,231 bytes, SHA-256: `b2ec8163f275b2417e68d974e8092c73fdb89dc319d469e9bbc5d69c787c4963`).
- Archive integrity verified: Successfully expanded via `Expand-Archive` with zero errors.
- PHP syntax check across all source files: all passed without warnings or notices.
- Web surfaces tested: `/super-admin`, `/manage`, `/console`, `/docs`, `/verify/{token}`.
- Local rendering and storage verified: pure-PHP PDF-1.4 generation and vector QR codes generated locally with zero third-party cloud dependencies.

## Architectural constraints

1. Tenant-owned queries must be constrained by `tenant_id` at the repository boundary.
2. All issuance paths must use `CertificateIssuanceService`.
3. Published template versions are immutable.
4. Verification must not persist or expose raw verification tokens.
5. Rendering and storage remain self-hosted for this release scope.

## SOI CMS identity integration contract

- The CMS bootstrap must supply a trusted `$GLOBALS['soi_certificate_identity_provider']` callable returning an array containing `user_id`, `is_platform_admin` (boolean), and optional `active_tenant_id`.
- The CMS bootstrap must supply a trusted `$GLOBALS['soi_certificate_user_exists_provider']` callable accepting a CMS user ID and returning a boolean. The membership UI refuses account assignment when this provider is absent or returns a non-boolean.
- These callbacks are PHP bootstrap integrations, not request parameters. Production continues to fail closed without the host identity provider; only explicit standalone development/demo mode receives a local development identity.
