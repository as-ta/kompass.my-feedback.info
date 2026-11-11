CREATE TABLE IF NOT EXISTS invoice_issuer (
    id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    legal_name VARCHAR(200) NOT NULL DEFAULT '',
    street_address VARCHAR(200) NOT NULL DEFAULT '',
    postal_code VARCHAR(24) NOT NULL DEFAULT '',
    city VARCHAR(120) NOT NULL DEFAULT '',
    contact_name VARCHAR(120) NOT NULL DEFAULT '',
    contact_email VARCHAR(254) NOT NULL DEFAULT '',
    contact_phone VARCHAR(60) NOT NULL DEFAULT '',
    vat_id VARCHAR(20) NOT NULL DEFAULT '',
    vat_rate_basis_points SMALLINT UNSIGNED DEFAULT NULL,
    next_test_number INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO invoice_issuer (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id;

CREATE TABLE IF NOT EXISTS sandbox_invoice (
    poll_period_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    invoice_number VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    issued_at DATETIME NOT NULL,
    xml MEDIUMBLOB NOT NULL,
    FOREIGN KEY (poll_period_id) REFERENCES poll_period (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
