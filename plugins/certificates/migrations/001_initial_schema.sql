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
