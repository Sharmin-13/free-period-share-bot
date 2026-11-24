<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/Bot.php';

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$reflection = new ReflectionClass(Bot::class);
$bot = $reflection->newInstanceWithoutConstructor();
$reflection->getProperty('timezone')->setValue($bot, new DateTimeZone('Asia/Tehran'));
$messages = $reflection->getMethod('messages')->invoke(null);

$englishKeys = array_keys($messages['en']);
$russianKeys = array_keys($messages['ru']);
sort($englishKeys);
sort($russianKeys);
check($englishKeys === $russianKeys, 'Russian message keys must match English keys');
foreach ($englishKeys as $key) {
    preg_match_all('/\{[a-z_]+\}/', $messages['en'][$key], $englishVars);
    preg_match_all('/\{[a-z_]+\}/', $messages['ru'][$key], $russianVars);
    sort($englishVars[0]);
    sort($russianVars[0]);
    check($englishVars[0] === $russianVars[0], 'Placeholder mismatch: ' . $key);
}

$russian = ['language' => 'ru', 'gender' => 'female', 'period_active' => 1, 'partner_id' => null];
$keyboard = $reflection->getMethod('mainKeyboard')->invoke($bot, $russian)['keyboard'];
check(count($keyboard[0]) === 2, 'Active period needs two adjacent buttons');
check($keyboard[0][0]['text'] === '🩸 Месячные закончились', 'Russian end button missing');
check($keyboard[0][1]['text'] === '📅 Записать дату окончания', 'Russian backdated end button missing');
check($keyboard[0][0]['style'] === $keyboard[0][1]['style'], 'End buttons must use the same style');
foreach ($keyboard as $row) {
    foreach ($row as $button) {
        check($reflection->getMethod('isReplyKeyboardButton')->invoke($bot, $button['text']), 'Unrouted Russian button: ' . $button['text']);
    }
}

$russian['period_active'] = 0;
$keyboard = $reflection->getMethod('mainKeyboard')->invoke($bot, $russian)['keyboard'];
check(count($keyboard[0]) === 2 && $keyboard[0][0]['text'] === '🩸 Начались месячные', 'Inactive Russian keyboard is wrong');
check($keyboard[0][1]['text'] === '📅 Записать пропущенное начало', 'Russian backdated start button missing');
check($keyboard[0][0]['style'] === $keyboard[0][1]['style'], 'Start buttons must use the same style');

$russian['gender'] = 'male';
$keyboard = $reflection->getMethod('mainKeyboard')->invoke($bot, $russian)['keyboard'];
check($keyboard[0][0]['text'] === '🔐 Создать код/ссылку', 'Russian partner keyboard is wrong');

$russian['gender'] = null;
$keyboard = $reflection->getMethod('infoKeyboard')->invoke($bot, $russian)['keyboard'];
check($keyboard[0][0]['text'] === '💌 Записки (анонимно)', 'Russian pre-registration keyboard is wrong');

$parse = $reflection->getMethod('parseDateForUser');
$first = $parse->invoke($bot, $russian, '2026-10-06');
$second = $parse->invoke($bot, $russian, '06/10/2026');
check($first instanceof DateTimeImmutable && $second instanceof DateTimeImmutable, 'Russian Gregorian dates should parse');
check($first->format('Y-m-d') === $second->format('Y-m-d'), 'Both Gregorian input orders should agree');
check($parse->invoke($bot, $russian, '1405/07/14') === null, 'Russian dates must not be treated as Jalali');

echo "Russian locale checks passed.\n";
