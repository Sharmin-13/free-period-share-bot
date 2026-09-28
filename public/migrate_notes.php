<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$provided = (string) ($_SERVER['HTTP_X_MIGRATION_KEY'] ?? '');
if (!hash_equals((string) $config['app_secret'], $provided)) {
    http_response_code(401);
    exit('Unauthorized');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $db->exec("CREATE TABLE IF NOT EXISTS notes (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        author_user_id BIGINT UNSIGNED NOT NULL,
        author_gender ENUM('female', 'male') NOT NULL,
        body VARCHAR(300) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_notes_created (created_at, id),
        CONSTRAINT fk_notes_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $version = $db->prepare("INSERT INTO system_status (status_key, status_value) VALUES ('schema_version', '3') ON DUPLICATE KEY UPDATE status_value = VALUES(status_value)");
    $version->execute();
    $lastCron = $db->query("SELECT status_value FROM system_status WHERE status_key = 'last_cron_ok'")->fetchColumn() ?: null;
    $dbNow = $db->query('SELECT NOW()')->fetchColumn();
    $counts = $db->query("SELECT
        (SELECT COUNT(*) FROM users WHERE gender = 'female' AND last_period_date IS NOT NULL) AS tracked_users,
        (SELECT COUNT(*) FROM sent_notifications WHERE event_date = CURDATE()) AS notifications_today,
        (SELECT COUNT(*) FROM notes) AS notes_count")->fetch();
    $result = [
        'ok' => true,
        'schema_version' => 3,
        'timezone' => $config['timezone'] ?? 'Asia/Tehran',
        'db_now' => $dbNow,
        'last_cron_ok' => $lastCron,
        'tracked_users' => (int) $counts['tracked_users'],
        'notifications_today' => (int) $counts['notifications_today'],
        'notes_count' => (int) $counts['notes_count'],
    ];
    if (!@unlink(__FILE__)) {
        $result['cleanup_warning'] = true;
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('Period bot notes migration failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
}
