-- Eigenes Erscheinungsbild je Konto (Logo und Farben für die Teilnahmeseite)
CREATE TABLE IF NOT EXISTS account_branding (
    account_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    primary_color CHAR(7) CHARACTER SET ascii DEFAULT NULL,
    accent_color CHAR(7) CHARACTER SET ascii DEFAULT NULL,
    logo_file VARCHAR(64) CHARACTER SET ascii DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES account_user (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
