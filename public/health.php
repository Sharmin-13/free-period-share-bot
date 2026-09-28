<?php
declare(strict_types=1);
ini_set('display_errors', '0');

$result = ['ok' => false, 'service' => 'period-share-bot'];
try {
    require __DIR__ . '/bootstrap.php';
    $db->query('SELECT 1 FROM users LIMIT 1');
    $stmt = $db->prepare("SELECT status_value FROM system_status WHERE status_key = 'last_cron_ok'");
    $stmt->execute();
    $lastCron = $stmt->fetchColumn() ?: null;
    $cronRecent = null;
    if (is_string($lastCron)) {
        $last = new DateTimeImmutable($lastCron);
        $cronRecent = $last > (new DateTimeImmutable('now'))->modify('-36 hours');
    }
    $result = [
        'ok' => true,
        'service' => 'period-share-bot',
        'database' => 'ok',
        'cron_recent' => $cronRecent,
    ];
    http_response_code(200);
} catch (Throwable $e) {
    error_log('Period bot health error: ' . get_class($e));
    $result['database'] = 'error';
    http_response_code(503);
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode($result, JSON_UNESCAPED_UNICODE);
