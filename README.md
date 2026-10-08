# SOI Certificate Management Platform

[![PHP Version](https://img.shields.io/badge/PHP-8.1%2B-777bb4.svg)](https://www.php.net/)
[![Release Version](https://img.shields.io/badge/Version-2.0.0-blue.svg)](docs/development-status.md)
[![Schema Version](https://img.shields.io/badge/Schema-2026100812-orange.svg)](plugins/certificates/migrations/)
[![Tests](https://img.shields.io/badge/Tests-111%2F111%20Passed-brightgreen.svg)](tests/)
[![Architecture](https://img.shields.io/badge/Architecture-PSR--4%20Modular-success.svg)](plugins/certificates/src/)
[![Multi-Tenancy](https://img.shields.io/badge/Tenancy-Isolated%20Guarded-blueviolet.svg)](plugins/certificates/src/Tenancy/)
[![Self-Hosted](https://img.shields.io/badge/Dependencies-Zero%20External%20Cloud-lightgrey.svg)](plugins/certificates/src/Rendering/)

Enterprise-grade, modular, multi-tenant digital certificate issuance, verification, lifecycle management, and reporting platform designed for **School Of Interns (SOI)**. Built to operate seamlessly either embedded within the SOI CMS or standalone via PHP/MySQL/SQLite.

---

## 🌟 Key Highlights & Architectural Capabilities

### 🏢 1. True Multi-Tenancy & Data Isolation (Developer 1)
- **Repository-Level Guarding**: Every database query is strictly filtered by `tenant_id` at the repository boundary, eliminating cross-tenant leakage.
- **Fail-Closed Identity**: Unauthenticated or unauthorized tenant switching attempts immediately fail closed with security logging.
- **Dynamic Migrations**: Safe, web-executable migration runner (`001_create_foundation_tables.php` through `012_add_performance_indexes.php`) with double-prefix protection and schema rollback safety.
- **Custom Tenant Themes & Branding**: Per-tenant styling, brand colors, custom logos, dynamic custom properties, and softcoded base URL resolvers.

### 🎨 2. Visual Designer & Universal Responsive Shell (Developer 2)
- **3-Zone Visual Template Designer**: Drag-and-drop element positioning, interactive resizing, snap-to-grid, peer alignment guides, dynamic variable injection, and undo/redo stacks (`views/manage/designer/editor.php`, `assets/js/designer-canvas.js`).
- **Universal Responsive Design**: Glassmorphism aesthetic tested and verified across Desktop (1440x900), Ultra-wide (1920x1080), Tablet (768x1024), and Mobile (390x844).
- **Comprehensive Portal Surfaces**:
  - **Super Admin** (`/super-admin`): Multi-tenant management, suspension controls, platform health metrics.
  - **Tenant Management** (`/manage`): Certificate registry, bulk import wizard, form builder, approval queues, member roles, asset gallery, API keys, webhooks, and branding settings.
  - **Issuance Console** (`/console`): High-speed single manual certificate issuance.
  - **Documentation Portal** (`/docs`): Interactive API reference, cURL samples, and webhook guides.

### 🔒 3. Issuance Pipeline & Security Engine (Developer 3)
- **Central Issuance Pipeline**: Unified transactional sequence allocation via `CertificateIssuanceService` and `NumberGenerator`.
- **Granular RBAC**: Strict role enforcement (`Owner`, `Admin`, `Template Designer`, `Issuer`, `Viewer`) with last-owner deactivation protection.
- **Immutable Audit Logging**: Append-only audit trail (`AuditLogger`) with automated filtering and redaction of credentials, API secrets, PINs, and bearer tokens.
- **Certificate Lifecycle State Machine**: Full lifecycle tracking: `ISSUED` ➔ `REVOKED` ➔ `REPLACED` ➔ `EXPIRED` ➔ `CANCELLED` with immutable historical linkage.
- **Approval Queues & Scheduled Issuance**: Request/review public submission pipeline and background cron job lease handling with occurrence idempotency.
- **Signed Outbound Webhooks**: HMAC-SHA256 signature headers, exponential retry backoff, and strict SSRF loopback protections.

### 🖨️ 4. Pure-PHP Rendering & Secure Storage (Developer 4)
- **Zero-Cloud Local Rendering**: Self-hosted, pure-PHP vector PDF-1.4 generation (`LocalCertificateRenderer`) and vector QR code generator (`QrCodeGenerator`). Does not require cloud APIs, ImageMagick, or Puppeteer.
- **Tenant-Isolated Storage**: Dedicated sub-directories (`storage/certificates/{tenant_slug}/`) locked down with `.htaccess` and `index.html` protections to prevent direct script execution.
- **Integrity Validation**: SHA-256 checksums computed on artifact writes and validated on reads.
- **Neutral Public Verification**: Anti-enumeration verification gateway (`/verify/{token}`) supporting Open, Masked, PIN-protected, and Authenticated verification modes.
- **Formula Injection Defense**: Neutralizes CSV spreadsheet formulas (`=`, `+`, `-`, `@`, `\t`, `\r`) during data exports.
- **Production Packager**: Built-in release packaging engine ([package.php](package.php)) producing release archives with pure-PHP standard Deflate compression.

---

## 📁 Repository Directory Structure

```text
soi-certificatye-/
├── .gitignore                          # Git ignore rules for runtime artifacts & uploads
├── README.md                           # Project documentation and developer guide
├── index.php                           # Standalone developer server gateway & router
├── package.php                         # Production ZIP packaging tool (Pure-PHP Deflate)
├── run_server.bat                      # Instant local server launcher (port 8000)
├── run_tests.bat                       # Automated test suite batch executor
├── certificates-2.0.0-production.zip   # Latest cumulative production release archive
│
├── docs/                               # Platform architectural documentation
│   └── development-status.md           # Implementation verification status checkpoint
│
├── plugins/certificates/               # Main SOI Certificate Platform Plugin
│   ├── plugin.php                      # Plugin bootstrap & autoloader initialization
│   ├── assets/                         # Frontend styling, CSS tokens, and JS scripts
│   │   ├── css/style.css               # Modern glassmorphism UI & responsive styles
│   │   └── js/designer-canvas.js       # Drag-and-drop template designer logic
│   ├── migrations/                     # 12-Step database migrations (001 to 012)
│   │   ├── 001_create_foundation_tables.php
│   │   ├── 002_create_tenancy_tables.php
│   │   ├── ...
│   │   └── 012_add_performance_indexes.php
│   ├── src/                            # PSR-4 Architecture (SOI\Certificates)
│   │   ├── Api/                        # API clients, bearer tokens, scope validation
│   │   ├── Audit/                      # Append-only audit logger & sensitive data redaction
│   │   ├── Authorization/              # Granular RBAC, roles, and permission matrix
│   │   ├── Bulk/                       # Chunked CSV/XLSX imports & row-level idempotency
│   │   ├── Core/                       # Autoloader, Database, Migrations, Diagnostics
│   │   ├── Forms/                      # Dynamic form builder & submission approvals
│   │   ├── Http/                       # Controllers (SuperAdmin, Manage, Console, Docs)
│   │   ├── Rendering/                  # Pure-PHP PDF-1.4 & Vector QR Code generators
│   │   ├── Reporting/                  # Formula-neutralized CSV exports
│   │   ├── Scheduling/                 # Recurrence processor & occurrence leasing
│   │   ├── Storage/                    # Tenant-isolated file storage adapters & integrity
│   │   ├── Tenancy/                    # TenantContext, TenantRepository, ThemeManager
│   │   ├── Verification/               # Public verification controller & placeholder
│   │   └── Webhooks/                   # Signed webhook dispatcher & SSRF protections
│   ├── storage/                        # Protected storage directory (.htaccess & index.html)
│   │   ├── certificates/               # Tenant-isolated certificate PDFs
│   │   └── certificate-assets/         # Uploaded logos, borders, and signatures
│   └── views/                          # Modular view templates
│       ├── layouts/main.php            # Primary responsive layout shell
│       ├── partials/                   # Reusable navbar, flash alerts, and components
│       ├── super-admin/                # Platform management views
│       ├── manage/                     # Tenant administration views
│       ├── console/                    # Rapid issuance console views
│       ├── docs/                       # Developer API guides & references
│       └── verify/                     # Public verification UI views
│
└── tests/                              # Complete test suites
    ├── run_tests.php                   # 96 Unit & Isolation tests (100% pass)
    └── http_smoke_test.php             # 15 HTTP smoke tests across all portal routes
```

---

## 🚀 Quickstart & Local Setup

### System Requirements
- **PHP**: version **8.1** or higher.
- **Extensions**: `pdo`, `pdo_sqlite` or `pdo_mysql`, `mbstring`, `zlib`.
- **Database**: SQLite (built-in, default for standalone) or MySQL / MariaDB 10.4+.
- **Web Server**: Apache, Nginx, or PHP built-in CLI server.

### 1. Clone the Repository
```bash
git clone https://github.com/Mrsaurabhkumar123/soi-certificatye-.git
cd soi-certificatye-
```

### 2. Start the Local Server
You can launch the pre-configured local development server on `http://localhost:8000`:

**Using Batch Script (Windows):**
```cmd
run_server.bat
```

**Or directly via PHP CLI:**
```bash
php -S localhost:8000 index.php
```

### 3. Open in Browser
Visit the following portal endpoints:
| Portal Surface | URL | Description |
| :--- | :--- | :--- |
| **Tenant Management** | `http://localhost:8000/manage` | Main administration dashboard, templates, certificates, bulk imports, forms |
| **Issuance Console** | `http://localhost:8000/console` | Fast single-certificate manual issuance form |
| **Super Admin** | `http://localhost:8000/super-admin` | Platform health diagnostics and tenant management |
| **API & Developer Docs** | `http://localhost:8000/docs` | Interactive REST API reference & Webhook documentation |
| **Public Verification** | `http://localhost:8000/verify/sample-token` | Neutral certificate verification gateway |

---

## 🧪 Testing & Quality Assurance

The codebase includes comprehensive unit, isolation, and HTTP smoke test suites:

### Run All Unit & Isolation Tests
Tests database migrations, tenant boundary enforcement, RBAC authorization, rendering engines, QR generation, audit redaction, and idempotency:
```bash
php tests/run_tests.php
```
> **Result**: `96 Passed, 0 Failed (100% Green)`

### Run HTTP Smoke Tests
Validates all HTTP routes, controllers, middleware, CSRF protections, and JSON responses:
```bash
php tests/http_smoke_test.php
```
> **Result**: `15 Passed, 0 Failed (100% Green)`

### One-Click Batch Test Runner (Windows)
```cmd
run_tests.bat
```

---

## 📦 Building Production Release

The platform includes a pure-PHP Deflate release packaging tool that packages the entire verified plugin directory into a production-ready ZIP archive:

```bash
php package.php
```
Outputs:
- Target file: `certificates-2.0.0-production.zip`
- Standard ZIP-compliant Deflate compression with zero external dependencies.

---

## 🛡️ Security Architecture

1. **Anti-Enumeration Verification**: `/verify/{token}` returns neutral responses for non-existent tokens to prevent dictionary attacks or token guessing.
2. **SSRF Loopback Defense**: `WebhookDispatcher` prohibits webhooks targeting loopback addresses (`127.0.0.1`, `localhost`), internal subnets (`10.0.0.0/8`, `192.168.0.0/16`, `172.16.0.0/12`), or link-local ranges.
3. **Execution Blockers**: All storage sub-folders (`storage/certificates/`, `storage/certificate-assets/`) contain `.htaccess` and `index.html` files blocking direct script execution (`.php`, `.phtml`, `.cgi`).
4. **SVG Vector Sanitization**: Uploaded SVG assets have all `<script>`, `onload`, `onclick`, `javascript:`, and external entity tags stripped before saving.
5. **CSRF Protection**: All state-modifying requests (`POST`, `PUT`, `DELETE`) require a valid session CSRF token.

---

## 📄 License & Attribution

Developed for **School Of Interns (SOI)**.  
Engineered under the **SOI Certificate Management Platform Architecture Specification & 12-Prompt Delivery Plan**.
<img width="1891" height="978" alt="image" src="https://github.com/user-attachments/assets/2e4c7cec-6af1-4117-b7fc-d772c6f7410c" />
<img width="1911" height="976" alt="image" src="https://github.com/user-attachments/assets/b9802bef-6de6-46bd-8303-3714ed0271d0" />
<img width="1912" height="977" alt="image" src="https://github.com/user-attachments/assets/c0b21e05-f576-48fa-afa0-f4c86371a2fd" />
<img width="1911" height="983" alt="image" src="https://github.com/user-attachments/assets/884a58be-103c-40e3-8bc8-273580860cb4" />

