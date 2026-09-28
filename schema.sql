CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    telegram_id BIGINT NOT NULL UNIQUE,
    chat_id BIGINT NOT NULL,
    first_name VARCHAR(120) NOT NULL DEFAULT '',
    username VARCHAR(64) NULL,
    language ENUM('fa', 'en') NULL,
    gender ENUM('female', 'male') NULL,
    state VARCHAR(40) NOT NULL DEFAULT 'choose_language',
    note_return_state VARCHAR(40) NULL,
    cycle_length TINYINT UNSIGNED NULL DEFAULT NULL,
    bleed_length TINYINT UNSIGNED NULL DEFAULT NULL,
    ovulation_day TINYINT UNSIGNED NULL DEFAULT NULL,
    pms_days_before TINYINT UNSIGNED NULL DEFAULT NULL,
    last_period_date DATE NULL,
    period_active TINYINT(1) NOT NULL DEFAULT 0,
    last_period_end_date DATE NULL,
    partner_id BIGINT UNSIGNED NULL,
    pending_pair_code CHAR(8) NULL,
    last_action_at DATETIME(6) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_users_partner (partner_id),
    INDEX idx_users_period (gender, last_period_date),
    CONSTRAINT fk_users_partner FOREIGN KEY (partner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pair_codes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code_hash CHAR(64) NOT NULL UNIQUE,
    owner_user_id BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pair_owner_active (owner_user_id, used_at, expires_at),
    INDEX idx_pair_expiry (expires_at),
    CONSTRAINT fk_pair_owner FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sent_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tracked_user_id BIGINT UNSIGNED NOT NULL,
    event_type VARCHAR(32) NOT NULL,
    event_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_notification (tracked_user_id, event_type, event_date),
    INDEX idx_notification_date (event_date),
    CONSTRAINT fk_notification_user FOREIGN KEY (tracked_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS processed_updates (
    update_id BIGINT PRIMARY KEY,
    processed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_processed_at (processed_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    user_id BIGINT UNSIGNED NOT NULL,
    action_key VARCHAR(32) NOT NULL,
    window_started DATETIME NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (user_id, action_key),
    CONSTRAINT fk_rate_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS system_status (
    status_key VARCHAR(64) PRIMARY KEY,
    status_value VARCHAR(255) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    author_user_id BIGINT UNSIGNED NULL,
    author_gender ENUM('female', 'male') NULL,
    body VARCHAR(300) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notes_created (created_at, id),
    CONSTRAINT fk_notes_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS period_cycles (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tracked_user_id BIGINT UNSIGNED NOT NULL,
    started_on DATE NOT NULL,
    ended_on DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_cycle_start (tracked_user_id, started_on),
    CONSTRAINT fk_period_cycle_user FOREIGN KEY (tracked_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO system_status (status_key, status_value)
VALUES ('schema_version', '7')
ON DUPLICATE KEY UPDATE status_value = VALUES(status_value);
