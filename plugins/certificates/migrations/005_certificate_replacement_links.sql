ALTER TABLE cert_certificates ADD COLUMN IF NOT EXISTS replaces_certificate_id INT NULL;
ALTER TABLE cert_certificates ADD COLUMN IF NOT EXISTS replaced_by_certificate_id INT NULL;
CREATE INDEX IF NOT EXISTS idx_cert_replaces ON cert_certificates (tenant_id, replaces_certificate_id);
CREATE INDEX IF NOT EXISTS idx_cert_replaced_by ON cert_certificates (tenant_id, replaced_by_certificate_id);
