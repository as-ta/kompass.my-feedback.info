-- Teilnahme auf mehreren Seiten: Fortschritt und Abschluss je Teilnahme
-- Vor dem Hochladen der neuen PHP-Dateien importieren.
ALTER TABLE poll_response
    ADD COLUMN started_at DATETIME DEFAULT NULL,
    ADD COLUMN updated_at DATETIME DEFAULT NULL,
    ADD COLUMN completed_at DATETIME DEFAULT NULL;

-- Bisherige Antworten wurden immer vollständig abgesendet.
UPDATE poll_response
SET started_at = submitted_at, updated_at = submitted_at, completed_at = submitted_at
WHERE completed_at IS NULL;

CREATE INDEX idx_poll_response_progress ON poll_response (poll_period_id, completed_at, updated_at);
