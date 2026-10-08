CREATE TABLE IF NOT EXISTS cert_sequences (
    tenant_id INT NOT NULL,
    sequence_year INT NOT NULL,
    last_value INT NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, sequence_year)
);
