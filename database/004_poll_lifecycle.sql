CREATE TABLE IF NOT EXISTS poll_public (
    poll_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    token CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    FOREIGN KEY (poll_id) REFERENCES poll (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS poll_period (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    poll_id BIGINT UNSIGNED NOT NULL,
    period_number INT UNSIGNED NOT NULL,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    amount DECIMAL(10,2) NOT NULL,
    order_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL UNIQUE,
    approval_url VARCHAR(512) DEFAULT NULL,
    capture_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL UNIQUE,
    paid_at DATETIME DEFAULT NULL,
    payment_pending BOOLEAN NOT NULL DEFAULT FALSE,
    starts_at DATETIME DEFAULT NULL,
    ends_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_poll_period (poll_id, period_number),
    FOREIGN KEY (poll_id) REFERENCES poll (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO poll_period (poll_id, period_number, request_key, amount, order_id, approval_url, capture_id, paid_at)
SELECT s.poll_id, 1, s.request_key, s.amount, s.order_id, s.approval_url, s.capture_id, s.paid_at
FROM poll_paypal_sandbox s
WHERE NOT EXISTS (SELECT 1 FROM poll_period p WHERE p.poll_id = s.poll_id AND p.period_number = 1);

CREATE TABLE IF NOT EXISTS poll_response (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    poll_period_id BIGINT UNSIGNED NOT NULL,
    poll_version_id BIGINT UNSIGNED NOT NULL,
    submission_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL UNIQUE,
    answers JSON NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (poll_period_id) REFERENCES poll_period (id),
    FOREIGN KEY (poll_version_id) REFERENCES poll_version (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
