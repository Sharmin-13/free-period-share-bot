<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$provided = (string) ($_SERVER['HTTP_X_SETUP_SECRET'] ?? '');
if ($provided === '' || !hash_equals((string) $config['setup_secret'], $provided)) {
    http_response_code(404);
    exit;
}

function telegramCall(string $token, string $method, array $payload = []): array
{
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/' . $method);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => $payload !== [],
        CURLOPT_POSTFIELDS => $payload !== [] ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
    ]);
    $raw = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $decoded = is_string($raw) ? json_decode($raw, true) : null;
    if ($error !== '' || !is_array($decoded) || !($decoded['ok'] ?? false)) {
        throw new RuntimeException('Telegram API failed for ' . $method . ' (HTTP ' . $status . '): ' . ($error ?: (string) ($decoded['description'] ?? 'invalid response')));
    }
    return $decoded;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $token = (string) $config['telegram']['token'];
    $me = telegramCall($token, 'getMe');
    telegramCall($token, 'setWebhook', [
        'url' => (string) $config['telegram']['webhook_url'],
        'secret_token' => (string) $config['telegram']['webhook_secret'],
        'allowed_updates' => ['message', 'callback_query'],
        'drop_pending_updates' => false,
    ]);
    telegramCall($token, 'setMyCommands', [
        'commands' => [
            ['command' => 'start', 'description' => 'Start / شروع'],
            ['command' => 'menu', 'description' => 'Main menu / منوی اصلی'],
        ],
    ]);
    $info = telegramCall($token, 'getWebhookInfo');

    $deleted = @unlink(__FILE__);
    echo json_encode([
        'ok' => true,
        'username' => (string) ($me['result']['username'] ?? ''),
        'webhook_url' => (string) ($info['result']['url'] ?? ''),
        'pending_updates' => (int) ($info['result']['pending_update_count'] ?? 0),
        'connect_file_deleted' => $deleted,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
