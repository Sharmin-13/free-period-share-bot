-- Apply only to the period bot database (divansal_periodbot).
-- Existing language values and user data are preserved.
ALTER TABLE users MODIFY language ENUM('fa', 'en', 'ru') NULL;

INSERT INTO system_status (status_key, status_value)
VALUES ('schema_version', '8')
ON DUPLICATE KEY UPDATE status_value = '8';
