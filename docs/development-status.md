# Development Status

Product version: 1.0.0
Schema version: 2026100709
Release status: Development; not production-ready

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
- API documentation now covers scopes, idempotency, issuance errors, webhook signature headers, example payloads, and operational guidance.
- Manage, console, and platform-admin views include operational count/health dashboards and recent failure reporting where available.
- Certificate registry search, detail timeline, and filtered CSV export are implemented and exercised by HTTP smoke tests.

## Partial or not yet verified end-to-end

- CMS identity-provider integration: the plugin has a trusted identity-provider contract and a fail-closed account-existence provider seam, but integration with the actual host CMS authentication and user directory has not been validated in this repository.
- Tenant administration: membership assignment accepts host CMS user IDs after provider validation; invitation/email workflows and host user search/name display remain dependent on the host CMS integration.
- Forms and schedules: core public form policies, arbitrary template-variable field design, approval, and scheduled issuance are implemented; host cron wiring and operator retry/reconciliation workflows remain incomplete.
- Designer and assets: the required basic canvas controls and asset reference deletion guard are present; browser testing covered draft interaction and multipart upload/delete, but exhaustive editor edge cases and assistive-technology checks remain.
- Bulk processing and webhooks: CSV/XLSX-first-sheet/TSV ingestion is implemented. XLSX formulas rely on cached workbook values, and delivery to a real external webhook receiver has not been validated.
- API/docs and operations: operational dashboards, developer docs, and the 4-viewport responsive matrix were checked in the local browser; full cross-browser, accessibility, and production load testing remain.
- Release packaging: a production cumulative archive has not been generated or validated. The current PHP runtime lacks `ZipArchive`.

## Verification checkpoint

- `tests/run_tests.php`: 82 passed, 0 failed.
- `tests/http_smoke_test.php`: 15 passed, 0 failed.
- PHP syntax check across 80 project PHP files (excluding vendor, storage, and `.git`): all passed; the form-builder and designer JavaScript pass `node --check`; staged and unstaged diffs pass `git diff --check`.
- Local PHP 8.2.12 development server is running in standalone demo mode. `/`, `/manage`, `/console`, `/docs`, and the template designer returned successfully.
- An isolated MariaDB 10.4 instance on localhost applied all 9 migrations and passed tenant/template creation, verification-policy upsert, audit logging, API-client authentication, idempotency replay, direct issuance, form-approval issuance, and scheduler lease acquisition/retry; the temporary server and data directory were removed afterward.
- The browser confirmed asset upload and deletion, template-driven form-field add/remove and JSON mapping generation, designer drag snapping and undo/redo, and no document-level horizontal overflow on Manage at 1440x900, 1920x1080, 768x1024, and 390x844.
- PHP CLI has PDO MySQL support, but no persistent MySQL service is configured. MariaDB 10.4 was validated in an isolated temporary instance; compatibility with the exact production-supported MySQL version remains unverified.
- Actual SOI CMS integration, live outbound webhook delivery, and final install/update packaging remain unverified.

## Known release blockers

1. Validate the trusted identity-provider and user-existence provider contract against the real SOI CMS host.
2. Run host-level integration tests against the actual SOI CMS authentication, user directory, and scheduled-job mechanism.
3. Validate database migrations and repository queries against the supported production MySQL version, then run external webhook delivery against a controlled public test receiver.
4. Generate and inspect the cumulative release archive in a packaging environment with ZIP support; verify install/update behavior and release versioning before calling it production-ready.

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
