CREATE TABLE IF NOT EXISTS period_cycles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tracked_user_id BIGINT UNSIGNED NOT NULL,
    started_on DATE NOT NULL,
    ended_on DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cycle_start (tracked_user_id, started_on),
    CONSTRAINT fk_period_cycle_user FOREIGN KEY (tracked_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO period_cycles (tracked_user_id, started_on, ended_on)
SELECT id, last_period_date,
       CASE WHEN last_period_end_date >= last_period_date THEN last_period_end_date ELSE NULL END
FROM users
WHERE gender = 'female' AND last_period_date IS NOT NULL;

-- Restore the end-action for recent starts saved by the old onboarding flow.
UPDATE users
SET period_active = 1
WHERE gender = 'female' AND period_active = 0
  AND last_period_date IS NOT NULL AND last_period_end_date IS NULL
  AND last_period_date BETWEEN DATE_SUB(DATE(UTC_TIMESTAMP() + INTERVAL 210 MINUTE), INTERVAL (bleed_length - 1) DAY)
                           AND DATE(UTC_TIMESTAMP() + INTERVAL 210 MINUTE);

INSERT INTO system_status (status_key, status_value)
VALUES ('schema_version', '6')
ON DUPLICATE KEY UPDATE status_value = VALUES(status_value);
