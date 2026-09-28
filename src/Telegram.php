<?php
declare(strict_types=1);

final class Telegram
{
    public function __construct(private string $token)
    {
    }

    public function call(string $method, array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $ch = curl_init('https://api.telegram.org/bot' . $this->token . '/' . $method);
            if ($ch === false) {
                throw new RuntimeException('Telegram connection initialization failed');
            }
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_TIMEOUT => 15,
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($status === 429 && is_string($body) && $attempt === 0) {
                $limited = json_decode($body, true);
                $retryAfter = max(1, min(3, (int) ($limited['parameters']['retry_after'] ?? 1)));
                usleep($retryAfter * 1000000);
                continue;
            }
            if ($body === false || $status < 200 || $status >= 300) {
                throw new RuntimeException('Telegram request failed: ' . ($error ?: (string) $status));
            }
            $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            if (!($result['ok'] ?? false)) {
                throw new RuntimeException('Telegram API rejected request');
            }
            return $result;
        }
        throw new RuntimeException('Telegram request failed after rate-limit retry');
    }

    public function send(int|string $chatId, string $text, ?array $replyMarkup = null): void
    {
        $payload = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = $replyMarkup;
        }
        $this->call('sendMessage', $payload);
    }

    public function answerCallback(string $callbackId): void
    {
        $this->call('answerCallbackQuery', ['callback_query_id' => $callbackId]);
    }
}
