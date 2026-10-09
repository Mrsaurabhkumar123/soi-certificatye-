-- SOI Certificate Management Platform Database Schema

-- Migration: 001_initial_schema.sql
-- -------------------------------------------------------------
-- SOI Certificate Management Platform - Initial Migration 001
-- Multi-tenant schema covering all 18 core architecture entities
-- -------------------------------------------------------------

CREATE TABLE IF NOT EXISTS cert_tenants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(64) NOT NULL UNIQUE,
    display_name VARCHAR(128) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    branding_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cert_memberships (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    user_id INT NOT NULL,
    role_key VARCHAR(64) NOT NULL DEFAULT 'viewer',
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_user (tenant_id, user_id),
    KEY idx_user_status (user_id, status)
);

CREATE TABLE IF NOT EXISTS cert_roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NULL,
    role_key VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    is_system TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tenant_role (tenant_id, role_key)
);

CREATE TABLE IF NOT EXISTS cert_role_permissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    permission_key VARCHAR(64) NOT NULL,
    UNIQUE KEY uq_role_perm (role_id, permission_key)
);

CREATE TABLE IF NOT EXISTS cert_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    setting_key VARCHAR(64) NOT NULL,
    value_json TEXT NOT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tenant_setting (tenant_id, setting_key)
);

CREATE TABLE IF NOT EXISTS cert_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    slug VARCHAR(64) NOT NULL,
    name VARCHAR(128) NOT NULL,
    category VARCHAR(64) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'draft',
    draft_version_id INT NULL,
    published_version_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tenant_status (tenant_id, status),
    UNIQUE KEY uq_tenant_template_slug (tenant_id, slug)
);

CREATE TABLE IF NOT EXISTS cert_template_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    template_id INT NOT NULL,
    tenant_id INT NOT NULL,
    version_number INT NOT NULL,
    page_format VARCHAR(32) NOT NULL DEFAULT 'A4_LANDSCAPE',
    layout_json LONGTEXT NOT NULL,
    variable_schema_json TEXT NOT NULL,
    canonical_hash VARCHAR(64) NOT NULL,
    published_at DATETIME NULL,
    published_by INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_template_ver (template_id, version_number)
);

CREATE TABLE IF NOT EXISTS cert_template_assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(128) NOT NULL,
    asset_type VARCHAR(64) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size INT NOT NULL,
    mime_type VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_tenant_asset (tenant_id, asset_type)
);

CREATE TABLE IF NOT EXISTS cert_certificates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    certificate_number VARCHAR(64) NOT NULL,
    verification_token VARCHAR(64) NOT NULL,
    verification_token_hash VARCHAR(64) NOT NULL,
    template_id INT NOT NULL,
    template_version_id INT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'issued',
    recipient_name VARCHAR(128) NOT NULL,
    recipient_email VARCHAR(128) NULL,
    payload_json LONGTEXT NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_sha256 VARCHAR(64) NOT NULL,
    issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NULL,
    created_by_type VARCHAR(32) NOT NULL DEFAULT 'user',
    created_by_id INT NULL,
    source_type VARCHAR(32) NOT NULL DEFAULT 'manual',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cert_number (tenant_id, certificate_number),
    UNIQUE KEY uq_verify_token (verification_token_hash),
    KEY idx_tenant_cert_status (tenant_id, status),
    KEY idx_tenant_issued (tenant_id, issued_at)
);

CREATE TABLE IF NOT EXISTS cert_certificate_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    certificate_id INT NOT NULL,
    tenant_id INT NOT NULL,
    from_status VARCHAR(32) NOT NULL,
    to_status VARCHAR(32) NOT NULL,
    reason TEXT NULL,
    actor_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_cert_events (certificate_id, created_at)
);

CREATE TABLE IF NOT EXISTS cert_forms (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    form_key VARCHAR(64) NOT NULL UNIQUE,
    title VARCHAR(128) NOT NULL,
    template_id INT NOT NULL,
    visibility VARCHAR(32) NOT NULL DEFAULT 'tenant_only',
    field_mapping_json TEXT NOT NULL,
    requires_approval TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cert_form_submissions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    form_id INT NOT NULL,
    tenant_id INT NOT NULL,
    submitter_ip VARCHAR(64) NULL,
    payload_json TEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    certificate_id INT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cert_api_clients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(128) NOT NULL,
    client_id VARCHAR(64) NOT NULL UNIQUE,
    secret_hash VARCHAR(128) NOT NULL,
    scopes_json TEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_used_at DATETIME NULL
);

CREATE TABLE IF NOT EXISTS cert_idempotency (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    idempotency_key VARCHAR(128) NOT NULL,
    request_fingerprint VARCHAR(64) NOT NULL,
    response_code INT NOT NULL,
    response_body LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    UNIQUE KEY uq_tenant_idem (tenant_id, idempotency_key)
);

CREATE TABLE IF NOT EXISTS cert_webhooks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(128) NOT NULL,
    target_url VARCHAR(255) NOT NULL,
    secret VARCHAR(128) NOT NULL,
    events_json TEXT NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cert_webhook_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    webhook_id INT NOT NULL,
    tenant_id INT NOT NULL,
    event_key VARCHAR(64) NOT NULL,
    payload_json TEXT NOT NULL,
    response_status INT NULL,
    response_body TEXT NULL,
    attempts INT NOT NULL DEFAULT 0,
    next_retry_at DATETIME NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cert_schedules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    name VARCHAR(128) NOT NULL,
    template_id INT NOT NULL,
    trigger_type VARCHAR(64) NOT NULL,
    recurrence VARCHAR(64) NULL,
    next_run_at DATETIME NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cert_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT NULL,
    tenant_id INT NOT NULL,
    job_type VARCHAR(64) NOT NULL,
    payload_json TEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    run_after DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    lease_token VARCHAR(64) NULL,
    lease_until DATETIME NULL,
    attempts INT NOT NULL DEFAULT 0,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_job_status (status, run_after)
);

CREATE TABLE IF NOT EXISTS cert_audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NULL,
    actor_type VARCHAR(32) NOT NULL,
    actor_id INT NULL,
    event_key VARCHAR(64) NOT NULL,
    target_type VARCHAR(64) NOT NULL,
    target_id VARCHAR(64) NOT NULL,
    request_id VARCHAR(64) NULL,
    source_ip VARCHAR(64) NULL,
    metadata_json TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_tenant (tenant_id, created_at),
    KEY idx_audit_target (target_type, target_id)
);


-- Migration: 002_template_version_lock.sql
CREATE UNIQUE INDEX IF NOT EXISTS uq_cert_template_version_sequence
    ON cert_template_versions (template_id, version_number);


-- Migration: 003_certificate_sequences.sql
CREATE TABLE IF NOT EXISTS cert_sequences (
    tenant_id INT NOT NULL,
    sequence_year INT NOT NULL,
    last_value INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, sequence_year)
);


-- Migration: 004_clear_legacy_verification_tokens.sql
UPDATE cert_certificates
SET verification_token = ''
WHERE verification_token IS NOT NULL AND verification_token <> '';


-- Migration: 005_certificate_replacement_links.sql
ALTER TABLE cert_certificates ADD COLUMN IF NOT EXISTS replaces_certificate_id INT NULL;
ALTER TABLE cert_certificates ADD COLUMN IF NOT EXISTS replaced_by_certificate_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_cert_replaces ON cert_certificates (tenant_id, replaces_certificate_id);
CREATE INDEX IF NOT EXISTS idx_cert_replaced_by ON cert_certificates (tenant_id, replaced_by_certificate_id);


-- Migration: 006_form_approval_tracking.sql
ALTER TABLE cert_form_submissions ADD COLUMN IF NOT EXISTS reviewed_by INT NULL;
ALTER TABLE cert_form_submissions ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL;
ALTER TABLE cert_form_submissions ADD COLUMN IF NOT EXISTS decision_reason TEXT NULL;


-- Migration: 007_scheduling_idempotency.sql
ALTER TABLE cert_certificates ADD COLUMN IF NOT EXISTS source_reference VARCHAR(128) NULL;
ALTER TABLE cert_certificates ADD COLUMN IF NOT EXISTS source_fingerprint VARCHAR(64) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_cert_source_reference ON cert_certificates (tenant_id, source_reference);
ALTER TABLE cert_schedules ADD COLUMN IF NOT EXISTS payload_json TEXT NULL;
ALTER TABLE cert_schedules ADD COLUMN IF NOT EXISTS timezone VARCHAR(64) NOT NULL DEFAULT 'UTC';
ALTER TABLE cert_schedules ADD COLUMN IF NOT EXISTS last_run_at DATETIME NULL;
ALTER TABLE cert_schedules ADD COLUMN IF NOT EXISTS last_result VARCHAR(32) NULL;
ALTER TABLE cert_jobs ADD COLUMN IF NOT EXISTS dedupe_key VARCHAR(128) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_cert_job_dedupe ON cert_jobs (tenant_id, dedupe_key);


-- Migration: 008_forms_and_bulk_import.sql
ALTER TABLE cert_forms ADD COLUMN IF NOT EXISTS field_schema_json TEXT NOT NULL DEFAULT '{}';
ALTER TABLE cert_forms ADD COLUMN IF NOT EXISTS issue_mode VARCHAR(32) NOT NULL DEFAULT 'approval';
ALTER TABLE cert_forms ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL;

CREATE TABLE IF NOT EXISTS cert_bulk_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT NOT NULL,
    template_id INT NOT NULL,
    created_by INT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    total_rows INT NOT NULL DEFAULT 0,
    succeeded_rows INT NOT NULL DEFAULT 0,
    failed_rows INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_bulk_tenant_status (tenant_id, status, created_at)
);

CREATE TABLE IF NOT EXISTS cert_bulk_rows (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_id INT NOT NULL,
    tenant_id INT NOT NULL,
    `row_number` INT NOT NULL,
    payload_json TEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    certificate_id INT NULL,
    error_message TEXT NULL,
    processing_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bulk_batch_row (batch_id, `row_number`),
    KEY idx_bulk_row_work (tenant_id, batch_id, status, `row_number`)
);


-- Migration: 009_add_performance_indexes.sql
CREATE INDEX IF NOT EXISTS idx_cert_tenant_issued_at ON cert_certificates (tenant_id, issued_at);
CREATE INDEX IF NOT EXISTS idx_cert_tenant_status_date ON cert_certificates (tenant_id, status, issued_at);
CREATE INDEX IF NOT EXISTS idx_cert_tenant_number ON cert_certificates (tenant_id, certificate_number);
CREATE INDEX IF NOT EXISTS idx_cert_tenant_recipient ON cert_certificates (tenant_id, recipient_name);
CREATE INDEX IF NOT EXISTS idx_form_submission_queue ON cert_form_submissions (tenant_id, status, created_at);
CREATE INDEX IF NOT EXISTS idx_webhook_due_delivery ON cert_webhook_deliveries (tenant_id, status, next_retry_at, id);
CREATE INDEX IF NOT EXISTS idx_audit_tenant_recent ON cert_audit_log (tenant_id, created_at, id);
CREATE INDEX IF NOT EXISTS idx_template_tenant_status ON cert_templates (tenant_id, status, name);

