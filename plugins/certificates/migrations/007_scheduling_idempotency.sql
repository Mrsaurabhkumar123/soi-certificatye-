ALTER TABLE cert_certificates ADD COLUMN source_reference VARCHAR(128) NULL;
ALTER TABLE cert_certificates ADD COLUMN source_fingerprint VARCHAR(64) NULL;
CREATE UNIQUE INDEX uq_cert_source_reference ON cert_certificates (tenant_id, source_reference);
ALTER TABLE cert_schedules ADD COLUMN payload_json TEXT NULL;
ALTER TABLE cert_schedules ADD COLUMN timezone VARCHAR(64) NOT NULL DEFAULT 'UTC';
ALTER TABLE cert_schedules ADD COLUMN last_run_at DATETIME NULL;
ALTER TABLE cert_schedules ADD COLUMN last_result VARCHAR(32) NULL;
ALTER TABLE cert_jobs ADD COLUMN dedupe_key VARCHAR(128) NULL;
CREATE UNIQUE INDEX uq_cert_job_dedupe ON cert_jobs (tenant_id, dedupe_key);
