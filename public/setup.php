<?php
declare(strict_types=1);

$configuredRoot = getenv('PERIODBOT_APP_ROOT');
$appRoot = is_string($configuredRoot) && $configuredRoot !== '' ? $configuredRoot : dirname(__DIR__);
$configPath = $appRoot . '/config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit('Not configured');
}
$config = require $configPath;
$provided = $_SERVER['HTTP_X_SETUP_SECRET'] ?? '';
if (!is_string($provided) || !hash_equals((string) $config['setup_secret'], $provided)) {
    http_response_code(404);
    exit('Not found');
}

require $appRoot . '/src/Database.php';

try {
    $db = Database::connect($config['db']);
    $sql = (string) file_get_contents($appRoot . '/schema.sql');
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $sql, -1, PREG_SPLIT_NO_EMPTY);
    foreach ($statements as $statement) {
        $trimmed = trim($statement);
        if ($trimmed !== '') {
            $db->exec($trimmed);
        }
    }
    $deleted = @unlink(__FILE__);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'setup_file_deleted' => $deleted], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('Period bot setup failed: ' . $e->getMessage());
    http_response_code(500);
    echo 'Setup failed';
}
