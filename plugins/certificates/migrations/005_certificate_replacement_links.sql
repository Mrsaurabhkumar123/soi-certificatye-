ALTER TABLE cert_certificates ADD COLUMN replaces_certificate_id INT NULL;
ALTER TABLE cert_certificates ADD COLUMN replaced_by_certificate_id INT NULL;
CREATE INDEX idx_cert_replaces ON cert_certificates (tenant_id, replaces_certificate_id);
CREATE INDEX idx_cert_replaced_by ON cert_certificates (tenant_id, replaced_by_certificate_id);
