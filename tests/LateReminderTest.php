<?php
declare(strict_types=1);

final class Telegram
{
    public array $sent = [];

    public function send(int|string $chatId, string $text, ?array $replyMarkup = null): void
    {
        $this->sent[] = ['chat_id' => $chatId, 'text' => $text];
    }
}

final class TestDatabase extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(['INSERT IGNORE', ' FOR UPDATE'], ['INSERT OR IGNORE', ''], $query), $options);
    }
}

require dirname(__DIR__) . '/src/Bot.php';

function assertResult(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach ([['fa', 'en', 28], ['en', 'ru', 52], ['ru', 'fa', 60]] as [$ladyLanguage, $partnerLanguage, $cycleLength]) {
    $db = new TestDatabase('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, chat_id INTEGER, language TEXT, gender TEXT, partner_id INTEGER, last_period_date TEXT, cycle_length INTEGER, ovulation_day INTEGER, pms_days_before INTEGER, period_active INTEGER)');
    $db->exec('CREATE TABLE sent_notifications (tracked_user_id INTEGER, event_type TEXT, event_date TEXT, UNIQUE(tracked_user_id, event_type, event_date))');
    $db->exec("INSERT INTO users VALUES (1, 101, '$ladyLanguage', 'female', 2, NULL, $cycleLength, 14, 5, 0)");
    $db->exec("INSERT INTO users VALUES (2, 202, '$partnerLanguage', 'male', 1, NULL, NULL, NULL, NULL, 0)");
    $telegram = new Telegram();
    $bot = new Bot($db, $telegram, 'test_bot', 'test_secret', new DateTimeZone('Asia/Tehran'));

    foreach ([1, 5, 8, 9, 10] as $day) {
        $db->exec('DELETE FROM sent_notifications');
        $start = (new DateTimeImmutable('today', new DateTimeZone('Asia/Tehran')))
            ->modify('-' . ($cycleLength + $day) . ' days')->format('Y-m-d');
        $db->prepare('UPDATE users SET last_period_date = ? WHERE id = 1')->execute([$start]);
        $telegram->sent = [];
        $expected = $day <= 9 ? 1 : 0;
        assertResult($bot->sendDailyNotifications() === $expected, "Unexpected event count on late day $day");
        assertResult(count($telegram->sent) === $expected * 2, "Both recipients should receive late day $day exactly once");
        if ($expected) {
            assertResult($telegram->sent[0]['chat_id'] === 101 && $telegram->sent[1]['chat_id'] === 202, 'Recipients are incorrect');
            assertResult($bot->sendDailyNotifications() === 0, 'Duplicate scheduled notification');
            assertResult(count($telegram->sent) === 2, 'Duplicate Telegram delivery');
            foreach ($telegram->sent as $sent) {
                if ($day === 9) {
                    assertResult(str_contains($sent['text'], match ($sent['chat_id'] === 101 ? $ladyLanguage : $partnerLanguage) {
                        'fa' => 'پزشک', 'en' => 'clinician', 'ru' => 'врачу',
                    }), 'Day 9 must suggest medical consultation in the recipient language');
                } else {
                    assertResult(!str_contains($sent['text'], 'clinician') && !str_contains($sent['text'], 'پزشک') && !str_contains($sent['text'], 'врачу'), 'Days 1-8 must be reminders');
                }
            }
        }
    }
}

echo "Late reminder checks passed.\n";
