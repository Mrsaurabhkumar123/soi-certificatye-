ALTER TABLE cert_form_submissions ADD COLUMN IF NOT EXISTS reviewed_by INT NULL;
ALTER TABLE cert_form_submissions ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL;
ALTER TABLE cert_form_submissions ADD COLUMN IF NOT EXISTS decision_reason TEXT NULL;
