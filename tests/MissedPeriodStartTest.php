<?php
declare(strict_types=1);

final class Telegram
{
    public array $sent = [];
    public function send(int|string $chatId, string $text, ?array $replyMarkup = null): void
    {
        $this->sent[] = ['text' => $text, 'keyboard' => $replyMarkup];
    }
}

final class TestDatabase extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
    }
}

require dirname(__DIR__) . '/src/Bot.php';

function check(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}

foreach (['fa', 'en', 'ru'] as $language) {
    $db = new TestDatabase('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, chat_id INTEGER, language TEXT, gender TEXT, state TEXT, note_return_state TEXT, partner_id INTEGER, last_period_date TEXT, last_period_end_date TEXT, period_active INTEGER, cycle_length INTEGER, bleed_length INTEGER, ovulation_day INTEGER, pms_days_before INTEGER, pending_pair_code TEXT)');
    $db->exec('CREATE TABLE period_cycles (tracked_user_id INTEGER, started_on TEXT, ended_on TEXT, UNIQUE(tracked_user_id, started_on))');
    $db->exec("INSERT INTO users (id, chat_id, language, gender, state, period_active, cycle_length, bleed_length, ovulation_day, pms_days_before) VALUES (1, 101, '$language', 'female', 'ready', 0, 28, 5, 14, 5)");
    $telegram = new Telegram();
    $bot = new Bot($db, $telegram, 'test_bot', 'test_secret', new DateTimeZone('Asia/Tehran'));
    $reflection = new ReflectionClass(Bot::class);
    $getUser = fn() => $db->query('SELECT * FROM users WHERE id = 1')->fetch();
    $row = $reflection->getMethod('mainKeyboard')->invoke($bot, $getUser())['keyboard'][0];
    check(count($row) === 2 && $row[0]['style'] === $row[1]['style'], "Adjacent start buttons: $language");

    $reflection->getMethod('beginMissedPeriodStart')->invoke($bot, $getUser());
    check($getUser()['state'] === 'missed_period_start', "Start entry state: $language");
    $reflection->getMethod('handleMissedPeriodStart')->invoke($bot, $getUser(), 'not a date');
    check($db->query('SELECT count(*) FROM period_cycles')->fetchColumn() === 0, "Invalid date must not write: $language");
    $today = new DateTimeImmutable('today', new DateTimeZone('Asia/Tehran'));
    $todayInput = $reflection->getMethod('formatDateForUser')->invoke($bot, $getUser(), $today);
    $reflection->getMethod('handleMissedPeriodStart')->invoke($bot, $getUser(), $todayInput);
    check($db->query('SELECT count(*) FROM period_cycles')->fetchColumn() === 0, "Today belongs to the regular button: $language");

    $date = (new DateTimeImmutable('today', new DateTimeZone('Asia/Tehran')))->modify('-2 days');
    $formatted = $reflection->getMethod('formatDateForUser')->invoke($bot, $getUser(), $date);
    $parts = explode('/', $formatted);
    if ($language === 'fa') { $formatted = $parts[2] . '/' . $parts[1] . '/' . $parts[0]; }
    $reflection->getMethod('handleMissedPeriodStart')->invoke($bot, $getUser(), $formatted);
    check($getUser()['last_period_date'] === $date->format('Y-m-d'), "Saved backdated start: $language");
    check((int) $getUser()['period_active'] === 1 && $getUser()['state'] === 'ready', "Active state: $language");
    check($db->query('SELECT count(*) FROM period_cycles')->fetchColumn() === 1, "One cycle: $language");
    $row = $reflection->getMethod('mainKeyboard')->invoke($bot, $getUser())['keyboard'][0];
    check(count($row) === 2 && $row[0]['style'] === $row[1]['style'], "Adjacent end buttons: $language");

    $db->exec("UPDATE users SET period_active = 0, state = 'ready' WHERE id = 1");
    $reflection->getMethod('beginMissedPeriodStart')->invoke($bot, $getUser());
    $reflection->getMethod('handleMissedPeriodStart')->invoke($bot, $getUser(), $formatted);
    check($db->query('SELECT count(*) FROM period_cycles')->fetchColumn() === 1, "Duplicate start must not write: $language");
}

echo "Missed start checks passed.\n";
