-- Abo je Befragung über PayPal Subscriptions. Jede monatliche Abbuchung wird ein eigener Zeitraum in poll_period.
CREATE TABLE IF NOT EXISTS paypal_plan (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    environment VARCHAR(16) CHARACTER SET ascii NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    product_id VARCHAR(64) CHARACTER SET ascii NOT NULL,
    plan_id VARCHAR(64) CHARACTER SET ascii NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_paypal_plan (environment, amount)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS poll_subscription (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    poll_id BIGINT UNSIGNED NOT NULL,
    paypal_id VARCHAR(64) CHARACTER SET ascii NOT NULL UNIQUE,
    plan_id VARCHAR(64) CHARACTER SET ascii NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status VARCHAR(32) CHARACTER SET ascii NOT NULL,
    next_billing_at DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cancelled_at DATETIME DEFAULT NULL,
    synced_at DATETIME DEFAULT NULL,
    KEY idx_poll_subscription_poll (poll_id),
    FOREIGN KEY (poll_id) REFERENCES poll (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE poll_period
    ADD COLUMN subscription_id BIGINT UNSIGNED DEFAULT NULL,
    ADD CONSTRAINT fk_poll_period_subscription FOREIGN KEY (subscription_id) REFERENCES poll_subscription (id);
