-- This migration only touches the period bot database.
ALTER TABLE users
    MODIFY cycle_length TINYINT UNSIGNED NULL DEFAULT NULL,
    MODIFY bleed_length TINYINT UNSIGNED NULL DEFAULT NULL,
    MODIFY ovulation_day TINYINT UNSIGNED NULL DEFAULT NULL,
    MODIFY pms_days_before TINYINT UNSIGNED NULL DEFAULT NULL;

-- The partner role has no personal cycle. Lady-role accounts keep their values.
UPDATE users
SET cycle_length = NULL,
    bleed_length = NULL,
    ovulation_day = NULL,
    pms_days_before = NULL,
    last_period_date = NULL,
    last_period_end_date = NULL,
    period_active = 0
WHERE gender = 'male';

INSERT INTO system_status (status_key, status_value)
VALUES ('schema_version', '7')
ON DUPLICATE KEY UPDATE status_value = VALUES(status_value);
