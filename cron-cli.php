<?php
declare(strict_types=1);

function runPeriodShareCron(): int
{
    $root = __DIR__;
    $config = require $root . '/config.php';
    date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');
    require_once $root . '/src/Database.php';
    require_once $root . '/src/Telegram.php';
    require_once $root . '/src/Bot.php';

    $db = Database::connect($config['db']);
    $telegram = new Telegram($config['telegram']['token']);
    $bot = new Bot(
        $db,
        $telegram,
        $config['telegram']['username'],
        $config['app_secret'],
        new DateTimeZone($config['timezone'] ?? 'Asia/Tehran')
    );
    $lockHeld = false;
    try {
        $lockStmt = $db->query("SELECT GET_LOCK('period_share_bot_daily_cron', 0)");
        $lockHeld = (int) $lockStmt->fetchColumn() === 1;
        if (!$lockHeld) {
            return 0;
        }
        $count = $bot->sendDailyNotifications();
        $status = $db->prepare("INSERT INTO system_status (status_key, status_value) VALUES ('last_cron_ok', ?) ON DUPLICATE KEY UPDATE status_value = VALUES(status_value)");
        $status->execute([(new DateTimeImmutable('now'))->format(DateTimeInterface::ATOM)]);
        $db->exec("DELETE FROM processed_updates WHERE processed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        $db->exec("DELETE FROM pair_codes WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
        return $count;
    } catch (Throwable $e) {
        error_log('Period bot CLI cron error: ' . $e->getMessage());
        $adminChatId = $config['telegram']['admin_chat_id'] ?? null;
        if ($adminChatId) {
            try {
                $telegram->send($adminChatId, '⚠️ Period bot cron failed. Please check the server logs.');
            } catch (Throwable) {
            }
        }
        throw $e;
    } finally {
        if ($lockHeld) {
            try {
                $db->query("SELECT RELEASE_LOCK('period_share_bot_daily_cron')");
            } catch (Throwable) {
            }
        }
    }
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    try {
        $sent = runPeriodShareCron();
        fwrite(STDOUT, json_encode(['ok' => true, 'sent' => $sent], JSON_UNESCAPED_UNICODE) . PHP_EOL);
        exit(0);
    } catch (Throwable) {
        fwrite(STDERR, "error\n");
        exit(1);
    }
}
