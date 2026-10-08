CREATE UNIQUE INDEX uq_cert_template_version_sequence
    ON cert_template_versions (template_id, version_number);
