<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (!hash_equals((string) $config['app_secret'], (string) ($_SERVER['HTTP_X_MIGRATION_KEY'] ?? ''))) {
    http_response_code(401);
    exit('Unauthorized');
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $schema = (string) $db->query('SELECT DATABASE()')->fetchColumn();
    $hasColumn = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = ?");

    $hasColumn->execute([$schema, 'period_active']);
    if ((int) $hasColumn->fetchColumn() === 0) {
        $db->exec('ALTER TABLE users ADD COLUMN period_active TINYINT(1) NOT NULL DEFAULT 0 AFTER last_period_date');
    }

    $hasColumn->execute([$schema, 'last_period_end_date']);
    if ((int) $hasColumn->fetchColumn() === 0) {
        $db->exec('ALTER TABLE users ADD COLUMN last_period_end_date DATE NULL AFTER period_active');
    }

    $db->exec('ALTER TABLE users MODIFY last_action_at DATETIME(6) NULL');
    $db->exec("INSERT INTO system_status (status_key, status_value) VALUES ('schema_version', '5') ON DUPLICATE KEY UPDATE status_value = VALUES(status_value)");

    $verify = $db->prepare("SELECT COLUMN_NAME, DATETIME_PRECISION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME IN ('period_active', 'last_period_end_date', 'last_action_at') ORDER BY COLUMN_NAME");
    $verify->execute([$schema]);
    $columns = $verify->fetchAll();
    $result = ['ok' => count($columns) === 3, 'schema_version' => 5, 'columns' => $columns];
    if (!@unlink(__FILE__)) {
        $result['cleanup_warning'] = true;
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('Period bot cycle-state migration failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
}
