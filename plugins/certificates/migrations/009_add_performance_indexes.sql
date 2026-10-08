CREATE INDEX idx_cert_tenant_issued_at ON cert_certificates (tenant_id, issued_at);
CREATE INDEX idx_cert_tenant_status_date ON cert_certificates (tenant_id, status, issued_at);
CREATE INDEX idx_cert_tenant_number ON cert_certificates (tenant_id, certificate_number);
CREATE INDEX idx_cert_tenant_recipient ON cert_certificates (tenant_id, recipient_name);
CREATE INDEX idx_form_submission_queue ON cert_form_submissions (tenant_id, status, created_at);
CREATE INDEX idx_webhook_due_delivery ON cert_webhook_deliveries (tenant_id, status, next_retry_at, id);
CREATE INDEX idx_audit_tenant_recent ON cert_audit_log (tenant_id, created_at, id);
CREATE INDEX idx_template_tenant_status ON cert_templates (tenant_id, status, name);
