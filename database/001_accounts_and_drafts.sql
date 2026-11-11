CREATE TABLE IF NOT EXISTS account_user (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_account_user_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_login (
    token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    browser_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    draft_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    email VARCHAR(254) NOT NULL,
    expires_at DATETIME NOT NULL,
    KEY ix_account_login_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS account_mail_limit (
    scope_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    window_start BIGINT UNSIGNED NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (scope_key, window_start),
    KEY ix_account_mail_limit_window (window_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poll (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    owner_id BIGINT UNSIGNED NOT NULL,
    save_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'draft',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_poll_owner_save (owner_id, save_key),
    CONSTRAINT fk_poll_owner FOREIGN KEY (owner_id) REFERENCES account_user (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS poll_version (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    poll_id BIGINT UNSIGNED NOT NULL,
    version_number INT UNSIGNED NOT NULL,
    title VARCHAR(255) NOT NULL,
    purpose TEXT NOT NULL,
    audience TEXT NOT NULL,
    catalog_key VARCHAR(80) NOT NULL,
    wizard_answers JSON NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_poll_version (poll_id, version_number),
    CONSTRAINT fk_poll_version_poll FOREIGN KEY (poll_id) REFERENCES poll (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    poll_version_id BIGINT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL,
    prompt TEXT NOT NULL,
    question_type VARCHAR(16) NOT NULL,
    metric VARCHAR(16) DEFAULT NULL,
    scale_low VARCHAR(100) DEFAULT NULL,
    scale_high VARCHAR(100) DEFAULT NULL,
    stars BOOLEAN NOT NULL DEFAULT FALSE,
    UNIQUE KEY uq_question_position (poll_version_id, sort_order),
    CONSTRAINT fk_question_version FOREIGN KEY (poll_version_id) REFERENCES poll_version (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS question_option (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    question_id BIGINT UNSIGNED NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL,
    label VARCHAR(255) NOT NULL,
    numeric_value SMALLINT DEFAULT NULL,
    UNIQUE KEY uq_question_option_position (question_id, sort_order),
    CONSTRAINT fk_question_option_question FOREIGN KEY (question_id) REFERENCES question (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
