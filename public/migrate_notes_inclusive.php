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
    $schema = (string) $db->query('SELECT DATABASE()')->fetchColumn();

    $column = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'users' AND COLUMN_NAME = 'note_return_state'");
    $column->execute([$schema]);
    if ((int) $column->fetchColumn() === 0) {
        $db->exec("ALTER TABLE users ADD COLUMN note_return_state VARCHAR(40) NULL AFTER state");
    }

    $constraint = $db->prepare("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'notes' AND COLUMN_NAME = 'author_user_id' AND REFERENCED_TABLE_NAME = 'users' LIMIT 1");
    $constraint->execute([$schema]);
    $constraintName = $constraint->fetchColumn();
    if (is_string($constraintName) && preg_match('/^[A-Za-z0-9_]+$/', $constraintName)) {
        $db->exec('ALTER TABLE notes DROP FOREIGN KEY `' . $constraintName . '`');
    }

    $db->exec("ALTER TABLE notes MODIFY author_user_id BIGINT UNSIGNED NULL, MODIFY author_gender ENUM('female', 'male') NULL");

    $constraint->execute([$schema]);
    if ($constraint->fetchColumn() === false) {
        $db->exec('ALTER TABLE notes ADD CONSTRAINT fk_notes_author FOREIGN KEY (author_user_id) REFERENCES users(id) ON DELETE SET NULL');
    }

    $version = $db->prepare("INSERT INTO system_status (status_key, status_value) VALUES ('schema_version', '4') ON DUPLICATE KEY UPDATE status_value = VALUES(status_value)");
    $version->execute();

    $deleteRule = $db->prepare("SELECT rc.DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS rc WHERE rc.CONSTRAINT_SCHEMA = ? AND rc.TABLE_NAME = 'notes' AND rc.REFERENCED_TABLE_NAME = 'users' LIMIT 1");
    $deleteRule->execute([$schema]);
    $notesCount = (int) $db->query('SELECT COUNT(*) FROM notes')->fetchColumn();
    $result = [
        'ok' => true,
        'schema_version' => 4,
        'notes_count' => $notesCount,
        'notes_delete_rule' => $deleteRule->fetchColumn(),
        'note_return_state' => true,
    ];
    if (!@unlink(__FILE__)) {
        $result['cleanup_warning'] = true;
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    error_log('Period bot inclusive notes migration failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false], JSON_UNESCAPED_UNICODE);
}
