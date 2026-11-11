CREATE TABLE IF NOT EXISTS account_billing (
    account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    company VARCHAR(200) DEFAULT NULL,
    address_line1 VARCHAR(200) NOT NULL,
    address_line2 VARCHAR(200) DEFAULT NULL,
    postal_code VARCHAR(24) NOT NULL,
    city VARCHAR(120) NOT NULL,
    country VARCHAR(100) NOT NULL,
    phone VARCHAR(60) DEFAULT NULL,
    vat_id VARCHAR(40) DEFAULT NULL,
    CONSTRAINT fk_account_billing_user FOREIGN KEY (account_id) REFERENCES account_user (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
