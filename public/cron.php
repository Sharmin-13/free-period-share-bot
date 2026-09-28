<?php
declare(strict_types=1);

ini_set('display_errors', '0');
$configuredRoot = getenv('PERIODBOT_APP_ROOT');
$appRoot = is_string($configuredRoot) && $configuredRoot !== '' ? $configuredRoot : dirname(__DIR__);
$config = require $appRoot . '/config.php';

$authorization = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!hash_equals('Bearer ' . (string) $config['cron_secret'], (string) $authorization)) {
    http_response_code(401);
    exit('Unauthorized');
}

$lockHeld = false;
try {
    require __DIR__ . '/bootstrap.php';
    $lockStmt = $db->query("SELECT GET_LOCK('period_share_bot_daily_cron', 0)");
    $lockHeld = (int) $lockStmt->fetchColumn() === 1;
    header('Content-Type: application/json; charset=utf-8');
    if (!$lockHeld) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'busy' => true], JSON_UNESCAPED_UNICODE);
    } else {
        $count = $bot->sendDailyNotifications();
        $status = $db->prepare("INSERT INTO system_status (status_key, status_value) VALUES ('last_cron_ok', ?) ON DUPLICATE KEY UPDATE status_value = VALUES(status_value)");
        $status->execute([(new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM)]);
        $db->exec("DELETE FROM processed_updates WHERE processed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $db->exec("DELETE FROM pair_codes WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        echo json_encode(['ok' => true, 'sent' => $count], JSON_UNESCAPED_UNICODE);
    }
} catch (Throwable $e) {
    error_log('Period bot cron error: ' . get_class($e));
    $adminChatId = $config['telegram']['admin_chat_id'] ?? null;
    if ($adminChatId) {
        try {
            $telegram->send($adminChatId, '⚠️ Period bot cron failed. Please check the server logs.');
        } catch (Throwable) {
        }
    }
    http_response_code(500);
    echo 'error';
} finally {
    if ($lockHeld) {
        try {
            $db->query("SELECT RELEASE_LOCK('period_share_bot_daily_cron')");
        } catch (Throwable) {
        }
    }
}
