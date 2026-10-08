ALTER TABLE cert_forms ADD COLUMN field_schema_json TEXT NOT NULL DEFAULT '{}';
ALTER TABLE cert_forms ADD COLUMN issue_mode VARCHAR(32) NOT NULL DEFAULT 'approval';
ALTER TABLE cert_forms ADD COLUMN updated_at DATETIME NULL;

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
    row_number INT NOT NULL,
    payload_json TEXT NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    attempts INT NOT NULL DEFAULT 0,
    certificate_id INT NULL,
    error_message TEXT NULL,
    processing_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_bulk_batch_row (batch_id, row_number),
    KEY idx_bulk_row_work (tenant_id, batch_id, status, row_number)
);
