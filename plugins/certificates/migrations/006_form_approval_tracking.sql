ALTER TABLE cert_form_submissions ADD COLUMN reviewed_by INT NULL;
ALTER TABLE cert_form_submissions ADD COLUMN reviewed_at DATETIME NULL;
ALTER TABLE cert_form_submissions ADD COLUMN decision_reason TEXT NULL;
