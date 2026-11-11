CREATE TABLE IF NOT EXISTS poll_paypal_sandbox (
    poll_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    order_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    approval_url VARCHAR(512) DEFAULT NULL,
    capture_id VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    UNIQUE KEY uq_poll_paypal_request (request_key),
    UNIQUE KEY uq_poll_paypal_order (order_id),
    UNIQUE KEY uq_poll_paypal_capture (capture_id),
    CONSTRAINT fk_poll_paypal_sandbox FOREIGN KEY (poll_id) REFERENCES poll (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
