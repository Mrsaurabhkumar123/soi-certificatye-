ALTER TABLE cert_certificates ADD COLUMN IF NOT EXISTS source_reference VARCHAR(128) NULL;
ALTER TABLE cert_certificates ADD COLUMN IF NOT EXISTS source_fingerprint VARCHAR(64) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_cert_source_reference ON cert_certificates (tenant_id, source_reference);
ALTER TABLE cert_schedules ADD COLUMN IF NOT EXISTS payload_json TEXT NULL;
ALTER TABLE cert_schedules ADD COLUMN IF NOT EXISTS timezone VARCHAR(64) NOT NULL DEFAULT 'UTC';
ALTER TABLE cert_schedules ADD COLUMN IF NOT EXISTS last_run_at DATETIME NULL;
ALTER TABLE cert_schedules ADD COLUMN IF NOT EXISTS last_result VARCHAR(32) NULL;
ALTER TABLE cert_jobs ADD COLUMN IF NOT EXISTS dedupe_key VARCHAR(128) NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_cert_job_dedupe ON cert_jobs (tenant_id, dedupe_key);
