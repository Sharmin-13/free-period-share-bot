<?php
declare(strict_types=1);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

ini_set('display_errors', '0');
$configuredRoot = getenv('PERIODBOT_APP_ROOT');
$appRoot = is_string($configuredRoot) && $configuredRoot !== '' ? $configuredRoot : dirname(__DIR__);
$config = require $appRoot . '/config.php';

$secret = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';
if (!hash_equals((string) $config['telegram']['webhook_secret'], (string) $secret)) {
    http_response_code(401);
    exit('Unauthorized');
}

$claimed = false;
try {
    $raw = file_get_contents('php://input', false, null, 0, 1048577);
    if (!is_string($raw) || strlen($raw) > 1048576) {
        http_response_code(413);
        exit('Payload too large');
    }
    $payload = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
    $updateId = is_array($payload) ? ($payload['update_id'] ?? null) : null;
    if (!is_int($updateId) || $updateId <= 0) {
        http_response_code(400);
        exit('Invalid update');
    }
    require __DIR__ . '/bootstrap.php';
    $claim = $db->prepare('INSERT IGNORE INTO processed_updates (update_id) VALUES (?)');
    $claim->execute([$updateId]);
    if ($claim->rowCount() === 0) {
        http_response_code(200);
        exit('duplicate');
    }
    $claimed = true;
    $bot->handle($payload);
    http_response_code(200);
    echo 'ok';
} catch (JsonException $e) {
    http_response_code(400);
    echo 'Invalid JSON';
} catch (Throwable $e) {
    if ($claimed) {
        try {
            $release = $db->prepare('DELETE FROM processed_updates WHERE update_id = ?');
            $release->execute([$updateId]);
        } catch (Throwable) {
            error_log('Period bot update claim release failed');
        }
    }
    error_log('Period bot webhook error: ' . get_class($e));
    http_response_code(500);
    echo 'error';
}
