<?php
declare(strict_types=1);

final class Bot
{
    private const DEFAULT_CYCLE = 28;
    private const DEFAULT_BLEED = 5;
    private const DEFAULT_OVULATION_DAY = 14;
    private const DEFAULT_PMS_BEFORE = 5;
    private const SCHEDULE_MAX_AGE_DAYS = 60;
    private const ABOUT_TEXT_FA = 'این ربات را من، فرشاد مصفا، با هدف دسترسی رایگان مردم در سراسر جهان به یک ابزار ساده برای آگاهی بیشتر درباره چرخه قاعدگی ساخته‌ام؛ بدون نیاز به پرداخت هزینه. فعالیت اصلی من در حوزه سبدگردانی ارز دیجیتال و بورس است و در کنار آن یک استارتاپ حقوقی برای مشاوره حقوقی و وکالت هم دارم. برای همکاری سبدگردانی می‌توانید پیام بدهید: <a href="https://t.me/farshdamosaffa">@farshdamosaffa</a>  برای استفاده از خدمات وکیل و مشاور حقوقی <a href="https://t.me/Online_vakil_24_bot">@Online_vakil_24_bot</a>' . "\n\n" . 'حتما از سایت شخصی من بازدید کنید: <a href="https://farshadmosaffa.ir/">farshadmosaffa.ir</a>';
    private const ABOUT_TEXT_EN = 'About the Creator I’m Farshad Mosaffa. I built this bot as a free tool for people around the world to better understand and track menstrual cycles without having to pay for access. My main field is cryptocurrency portfolio management. For collaboration: <a href="https://t.me/farshdamosaffa">@farshdamosaffa</a>' . "\n\n" . 'Be sure to visit my personal website: <a href="https://farshadmosaffa.ir/">farshadmosaffa.ir</a>';

    public function __construct(
        private PDO $db,
        private Telegram $telegram,
        private string $botUsername,
        private string $appSecret,
        private DateTimeZone $timezone
    ) {
    }

    public function handle(array $update): void
    {
        if (isset($update['callback_query'])) {
            $this->handleCallback($update['callback_query']);
            return;
        }
        $message = $update['message'] ?? null;
        if (!is_array($message) || !isset($message['from']['id'], $message['chat']['id'])) {
            return;
        }
        if (($message['chat']['type'] ?? '') !== 'private'
            || (string) $message['from']['id'] !== (string) $message['chat']['id']) {
            return;
        }
        $user = $this->upsertUser($message['from'], (int) $message['chat']['id']);
        $text = trim((string) ($message['text'] ?? ''));

        if (preg_match('/^\/start(?:@\w+)?(?:\s+link_([0-9]{8}))?/u', $text, $matches)) {
            if (!empty($matches[1])) {
                $this->updateUser($user['id'], ['pending_pair_code' => $matches[1]]);
                $user['pending_pair_code'] = $matches[1];
            }
            $this->start($user);
            return;
        }
        if ($text === '/menu') {
            $this->showMenu($user);
            return;
        }
        if ($this->matches($text, ['↩️ بازگشت', '↩️ Back'])) {
            $this->showMenu($user);
            return;
        }
        if ($this->isReplyKeyboardButton($text)
            && !$this->matches($text, ['🩸 الان پریود شدم', '🩸 My period started', '🩸 الان پریودم تموم شد', '🩸 My period ended'])
            && !$this->allowUiClick((int) $user['id'])) {
            return;
        }
        if ($this->matches($text, ['🩸 الان پریود شدم', '🩸 My period started'])) {
            $this->recordPeriod($user);
            return;
        }
        if ($this->matches($text, ['🩸 الان پریودم تموم شد', '🩸 My period ended'])) {
            $this->recordPeriodEnd($user);
            return;
        }
        if ($this->matches($text, ['🔗 افزودن پارتنر', '🔗 Add partner'])) {
            $this->showPartnerMenu($user);
            return;
        }
        if ($this->matches($text, ['🤝 با یارت به اشتراک بذار', '🤝 Share with your partner'])) {
            $this->sendInvite($user);
            return;
        }
        if ($this->matches($text, ['🔐 ساخت کد/لینک اتصال', '🔐 Create invite code/link'])) {
            $this->sendInvite($user);
            return;
        }
        if ($this->matches($text, ['🔑 کد یار را وارد کن', '🔑 Enter partner code'])) {
            $this->beginCodeEntry($user);
            return;
        }
        if ($this->matches($text, ['⚙️ تنظیمات', '⚙️ Settings'])) {
            $this->showSettings($user);
            return;
        }
        if ($this->matches($text, ['📊 وضعیت من', '📊 My status'])) {
            $this->sendStatus($user, false);
            return;
        }
        if ($this->matches($text, ['📊 وضعیت پارتنر', '📊 Partner status'])) {
            $this->sendStatus($user, true);
            return;
        }
        if ($this->matches($text, ['📚 تاریخچه ۵ چرخه اخیر', '📚 Last 5 cycles'])) {
            $this->showCycleHistory($user, $user['gender'] === 'male');
            return;
        }
        if ($this->matches($text, ['📚 تاریخچه پارتنر', '📚 Partner cycle history'])) {
            $this->showCycleHistory($user, true);
            return;
        }
        if ($this->matches($text, ['👤 درباره سازنده', '👤 About the Creator'])) {
            $this->sendCreator($user);
            return;
        }
        if ($this->matches($text, ['🎁 چرا رایگان است؟', '🎁 Why is it free?'])) {
            $this->sendWhyFree($user);
            return;
        }
        if ($this->matches($text, ['💌 دل‌نوشته‌ها', '💌 دل‌نوشته‌ها (ناشناس)', '💌 Notes', '💌 Notes (Anonymous)'])) {
            $this->showNotesMenu($user);
            return;
        }
        $this->handleStateText($user, $text, $message);
    }

    private function start(array $user): void
    {
        if ($user['language'] === null) {
            $this->askLanguage($user['chat_id']);
            return;
        }
        if ($user['gender'] === null) {
            $this->askGender($user);
            return;
        }
        if ($user['gender'] === 'female' && $user['state'] !== 'ready') {
            $this->resumeFemaleSetup($user);
            return;
        }
        if ($user['pending_pair_code']) {
            $code = (string) $user['pending_pair_code'];
            $this->updateUser($user['id'], ['pending_pair_code' => null]);
            $this->connectWithCode($user, $code);
            return;
        }
        $this->showMenu($user);
    }

    private function handleCallback(array $callback): void
    {
        if (!isset($callback['id'], $callback['from']['id'], $callback['message']['chat']['id'])) {
            return;
        }
        if (($callback['message']['chat']['type'] ?? '') !== 'private'
            || (string) $callback['from']['id'] !== (string) $callback['message']['chat']['id']) {
            return;
        }
        $this->telegram->answerCallback((string) $callback['id']);
        $user = $this->upsertUser($callback['from'], (int) $callback['message']['chat']['id']);
        $data = (string) ($callback['data'] ?? '');
        if (!in_array($data, ['settings:menu', 'notes:menu', 'period:start', 'period:end'], true) && !$this->allowUiClick((int) $user['id'])) {
            return;
        }

        if ($data === 'lang:fa' || $data === 'lang:en') {
            $language = substr($data, 5);
            $fields = ['language' => $language];
            if ($user['gender'] === null) {
                $fields['state'] = 'choose_gender';
            }
            $this->updateUser($user['id'], $fields);
            $user = $this->getUserById((int) $user['id']);
            if ($user['gender'] === null) {
                $this->askGender($user);
            } elseif ($user['gender'] === 'female' && $user['state'] !== 'ready') {
                $this->resumeFemaleSetup($user);
            } else {
                $this->showMenu($user);
            }
            return;
        }
        if ($data === 'gender:female' || $data === 'gender:male') {
            if ($user['gender'] !== null) {
                $this->showMenu($user);
                return;
            }
            $gender = substr($data, 7);
            $state = $gender === 'female' ? 'cycle_length' : 'ready';
            $fields = ['gender' => $gender, 'state' => $state];
            if ($gender === 'female') {
                $fields += [
                    'cycle_length' => self::DEFAULT_CYCLE,
                    'bleed_length' => self::DEFAULT_BLEED,
                    'ovulation_day' => self::DEFAULT_OVULATION_DAY,
                    'pms_days_before' => self::DEFAULT_PMS_BEFORE,
                ];
            } else {
                $fields += [
                    'cycle_length' => null,
                    'bleed_length' => null,
                    'ovulation_day' => null,
                    'pms_days_before' => null,
                    'last_period_date' => null,
                    'last_period_end_date' => null,
                    'period_active' => 0,
                ];
            }
            $this->updateUser($user['id'], $fields);
            $user = $this->getUserById((int) $user['id']);
            if ($user['pending_pair_code'] && $gender === 'male') {
                $code = (string) $user['pending_pair_code'];
                $this->updateUser($user['id'], ['pending_pair_code' => null]);
                $this->connectWithCode($user, $code);
                $fresh = $this->getUserById((int) $user['id']);
                if ($fresh['partner_id'] === null) {
                    $this->showPartnerMenu($fresh);
                }
                return;
            }
            if ($gender === 'female') {
                $this->askCycleLength($user);
            } else {
                $this->showPartnerMenu($user);
            }
            return;
        }
        if (str_starts_with($data, 'cycle:')) {
            $this->handleChoice($user, 'cycle', substr($data, 6));
            return;
        }
        if (str_starts_with($data, 'bleed:')) {
            $this->handleChoice($user, 'bleed', substr($data, 6));
            return;
        }
        if (str_starts_with($data, 'ovulation:')) {
            $this->handleChoice($user, 'ovulation', substr($data, 10));
            return;
        }
        if (str_starts_with($data, 'pms:')) {
            $this->handleChoice($user, 'pms', substr($data, 4));
            return;
        }
        if (str_starts_with($data, 'startdate:')) {
            $this->handleStartDateChoice($user, substr($data, 10));
            return;
        }
        if ($data === 'period:start') {
            $this->recordPeriod($user);
            return;
        }
        if ($data === 'period:end') {
            $this->recordPeriodEnd($user);
            return;
        }
        if ($data === 'period:not_yet') {
            if ($user['gender'] !== 'female') {
                $this->showMenu($user);
                return;
            }
            $this->telegram->send($user['chat_id'], $this->t($user, 'period_not_yet_ack'), $this->mainKeyboard($user));
            return;
        }

        match ($data) {
            'partner:generate' => $this->sendInvite($user),
            'partner:enter' => $this->beginCodeEntry($user),
            'partner:skip' => $this->showMenu($user),
            'menu:status' => $this->sendStatus($user, $user['gender'] === 'male'),
            'menu:settings' => $this->showSettings($user),
            'menu:creator' => $this->sendCreator($user),
            'menu:free' => $this->sendWhyFree($user),
            'notes:menu' => $this->showNotesMenu($user),
            'notes:write' => $this->beginNote($user),
            'notes:list' => $this->showRecentNotes($user),
            'notes:cancel' => $this->cancelNote($user),
            'settings:profile' => $this->beginCycleSetup($user),
            'settings:language' => $this->askLanguage($user['chat_id']),
            'settings:disconnect' => $this->showDisconnectConfirmation($user),
            'settings:disconnect_confirm' => $this->disconnectPartner($user),
            'settings:disconnect_cancel' => $this->cancelDisconnect($user),
            'settings:delete' => $this->showDeleteProfileConfirmation($user),
            'settings:delete_confirm' => $this->deleteProfile($user),
            'settings:delete_cancel' => $this->cancelDeleteProfile($user),
            'settings:menu' => $this->showMenu($user),
            default => null,
        };
    }

    private function handleChoice(array $user, string $kind, string $value): void
    {
        $expectedStates = [
            'cycle' => 'cycle_length',
            'bleed' => 'bleed_length',
            'ovulation' => 'ovulation_day',
            'pms' => 'pms_days',
        ];
        if ($user['gender'] !== 'female' || ($expectedStates[$kind] ?? null) !== $user['state']) {
            $this->showMenu($user);
            return;
        }
        $customStates = [
            'cycle' => 'cycle_custom',
            'bleed' => 'bleed_custom',
            'ovulation' => 'ovulation_custom',
            'pms' => 'pms_custom',
        ];
        if ($value === 'custom') {
            $this->updateUser($user['id'], ['state' => $customStates[$kind]]);
            $this->telegram->send($user['chat_id'], $this->t($user, $customStates[$kind] . '_prompt'));
            return;
        }
        if ($kind === 'cycle') {
            $days = $value === 'unknown' ? self::DEFAULT_CYCLE : (int) $value;
            $this->updateUser($user['id'], ['cycle_length' => $days, 'state' => 'bleed_length']);
            $this->askBleedLength($user);
        } elseif ($kind === 'bleed') {
            $days = $value === 'unknown' ? self::DEFAULT_BLEED : (int) $value;
            $this->updateUser($user['id'], ['bleed_length' => $days, 'state' => 'ovulation_day']);
            $this->askOvulation($user);
        } elseif ($kind === 'ovulation') {
            $day = $value === 'unknown' ? self::DEFAULT_OVULATION_DAY : (int) $value;
            $this->updateUser($user['id'], ['ovulation_day' => $day, 'state' => 'pms_days']);
            $this->askPms($user);
        } else {
            $days = $value === 'unknown' ? self::DEFAULT_PMS_BEFORE : (int) $value;
            $this->updateUser($user['id'], ['pms_days_before' => $days, 'state' => 'last_period_date']);
            $this->askLastPeriodStart($this->getUserById((int) $user['id']));
        }
    }

    private function resumeFemaleSetup(array $user): void
    {
        match ((string) $user['state']) {
            'cycle_length', 'cycle_custom' => $this->askCycleLength($user),
            'bleed_length', 'bleed_custom' => $this->askBleedLength($user),
            'ovulation_day', 'ovulation_custom' => $this->askOvulation($user),
            'pms_days', 'pms_custom' => $this->askPms($user),
            'last_period_date', 'last_period_custom' => $this->askLastPeriodStart($user),
            default => $this->showMenu($user),
        };
    }

    private function handleStateText(array $user, string $text, array $message = []): void
    {
        if ($user['language'] === null) {
            $this->askLanguage($user['chat_id']);
            return;
        }
        if ($user['state'] === 'pair_code') {
            $digits = preg_replace('/\D+/', '', $this->toEnglishDigits($text));
            $this->connectWithCode($user, $digits);
            return;
        }
        if ($user['state'] === 'note_write') {
            $this->saveNote($user, $text, $message);
            return;
        }
        $value = filter_var($this->toEnglishDigits($text), FILTER_VALIDATE_INT);
        $rules = [
            'cycle_length' => [1, 255, 'cycle_length', 'bleed_length'],
            'cycle_custom' => [1, 255, 'cycle_length', 'bleed_length'],
            'bleed_custom' => [1, 10, 'bleed_length', 'ovulation_day'],
            'ovulation_custom' => [6, 30, 'ovulation_day', 'pms_days'],
            'pms_custom' => [1, 14, 'pms_days_before', 'ready'],
        ];
        if (isset($rules[$user['state']])) {
            [$min, $max, $field, $next] = $rules[$user['state']];
            if ($value === false || $value < $min || $value > $max) {
                $key = in_array($user['state'], ['cycle_length', 'cycle_custom'], true) ? 'invalid_cycle' : 'invalid_number';
                $this->telegram->send($user['chat_id'], $this->t($user, $key, ['min' => $min, 'max' => $max]));
                return;
            }
            if ($user['state'] === 'pms_custom') {
                $this->updateUser($user['id'], [$field => $value, 'state' => 'last_period_date']);
                $this->askLastPeriodStart($this->getUserById((int) $user['id']));
                return;
            }
            $this->updateUser($user['id'], [$field => $value, 'state' => $next]);
            match ($next) {
                'bleed_length' => $this->askBleedLength($user),
                'ovulation_day' => $this->askOvulation($user),
                'pms_days' => $this->askPms($user),
            };
            return;
        }
        if ($user['state'] === 'last_period_custom') {
            $this->handleCustomStartDate($user, $text);
            return;
        }
        $this->showMenu($user);
    }

    private function askLanguage(int|string $chatId): void
    {
        $this->telegram->send($chatId, "زبان را انتخاب کنید.\nChoose your language:", [
            'inline_keyboard' => [[
                ['text' => '🇮🇷 فارسی', 'callback_data' => 'lang:fa', 'style' => 'primary'],
                ['text' => '🇬🇧 English', 'callback_data' => 'lang:en', 'style' => 'primary'],
            ]],
        ]);
    }

    private function askGender(array $user): void
    {
        $this->telegram->send($user['chat_id'], $this->t($user, 'choose_gender'), [
            'inline_keyboard' => [
                [
                    ['text' => $this->t($user, 'female'), 'callback_data' => 'gender:female', 'style' => 'primary'],
                    ['text' => $this->t($user, 'male'), 'callback_data' => 'gender:male', 'style' => 'primary'],
                ],
                [
                    ['text' => $this->t($user, 'creator_button'), 'callback_data' => 'menu:creator'],
                    ['text' => $this->t($user, 'free_button'), 'callback_data' => 'menu:free'],
                ],
            ],
        ]);
    }

    private function askCycleLength(array $user): void
    {
        $this->telegram->send($user['chat_id'], $this->t($user, 'ask_cycle'), [
            'inline_keyboard' => [
                [['text' => '26', 'callback_data' => 'cycle:26'], ['text' => '28', 'callback_data' => 'cycle:28'], ['text' => '30', 'callback_data' => 'cycle:30']],
                [['text' => $this->t($user, 'custom'), 'callback_data' => 'cycle:custom'], ['text' => $this->t($user, 'unknown_28'), 'callback_data' => 'cycle:unknown']],
            ],
        ]);
    }

    private function askBleedLength(array $user): void
    {
        $this->telegram->send($user['chat_id'], $this->t($user, 'ask_bleed'), [
            'inline_keyboard' => [
                [['text' => '3', 'callback_data' => 'bleed:3'], ['text' => '5', 'callback_data' => 'bleed:5'], ['text' => '7', 'callback_data' => 'bleed:7']],
                [['text' => $this->t($user, 'custom'), 'callback_data' => 'bleed:custom'], ['text' => $this->t($user, 'unknown_5'), 'callback_data' => 'bleed:unknown']],
            ],
        ]);
    }

    private function askOvulation(array $user): void
    {
        $this->telegram->send($user['chat_id'], $this->t($user, 'ask_ovulation'), [
            'inline_keyboard' => [
                [['text' => '12', 'callback_data' => 'ovulation:12'], ['text' => '14', 'callback_data' => 'ovulation:14'], ['text' => '16', 'callback_data' => 'ovulation:16']],
                [['text' => $this->t($user, 'custom'), 'callback_data' => 'ovulation:custom'], ['text' => $this->t($user, 'unknown_14'), 'callback_data' => 'ovulation:unknown']],
            ],
        ]);
    }

    private function askPms(array $user): void
    {
        $this->telegram->send($user['chat_id'], $this->t($user, 'ask_pms'), [
            'inline_keyboard' => [
                [['text' => '3', 'callback_data' => 'pms:3'], ['text' => '5', 'callback_data' => 'pms:5'], ['text' => '7', 'callback_data' => 'pms:7']],
                [['text' => $this->t($user, 'custom'), 'callback_data' => 'pms:custom'], ['text' => $this->t($user, 'unknown_5'), 'callback_data' => 'pms:unknown']],
            ],
        ]);
    }

    private function askLastPeriodStart(array $user): void
    {
        $this->telegram->send($user['chat_id'], $this->t($user, 'ask_last_period'), [
            'inline_keyboard' => [
                [['text' => $this->t($user, 'started_today'), 'callback_data' => 'startdate:today', 'style' => 'danger']],
                [['text' => $this->t($user, 'enter_start_date'), 'callback_data' => 'startdate:custom', 'style' => 'primary']],
                [['text' => $this->t($user, 'record_later'), 'callback_data' => 'startdate:later']],
            ],
        ]);
    }

    private function handleStartDateChoice(array $user, string $choice): void
    {
        if ($user['gender'] !== 'female' || !in_array($user['state'], ['last_period_date', 'last_period_custom'], true)) {
            $this->showMenu($user);
            return;
        }
        if ($choice === 'today') {
            $this->finishSetup($user, ['last_period_date' => (new DateTimeImmutable('today', $this->timezone))->format('Y-m-d')]);
            return;
        }
        if ($choice === 'later') {
            $this->finishSetup($user, ['last_period_date' => null]);
            return;
        }
        $this->updateUser($user['id'], ['state' => 'last_period_custom']);
        $this->telegram->send($user['chat_id'], $this->t($user, 'start_date_prompt'));
    }

    private function handleCustomStartDate(array $user, string $text): void
    {
        $text = $this->toEnglishDigits(trim($text));
        $date = ($user['language'] ?? 'fa') === 'fa'
            ? $this->parseJalaliDate($text)
            : $this->parseGregorianDate($text);
        $today = new DateTimeImmutable('today', $this->timezone);
        if ($date === null || $date > $today) {
            $this->telegram->send($user['chat_id'], $this->t($user, 'invalid_start_date'));
            return;
        }
        $this->finishSetup($user, ['last_period_date' => $date->format('Y-m-d')]);
    }

    private function finishSetup(array $user, array $extra): void
    {
        $start = isset($extra['last_period_date']) && $extra['last_period_date'] !== null
            ? new DateTimeImmutable($extra['last_period_date'], $this->timezone) : null;
        $today = new DateTimeImmutable('today', $this->timezone);
        $elapsed = $start === null ? null : (int) $start->diff($today)->format('%r%a');
        $active = $elapsed !== null && $elapsed >= 0 && $elapsed < max(1, (int) $user['bleed_length']);
        $this->db->beginTransaction();
        try {
            $this->updateUser($user['id'], array_merge($extra, [
                'state' => 'ready',
                'period_active' => $active ? 1 : 0,
                'last_period_end_date' => null,
            ]));
            if ($start !== null) {
                $this->db->prepare('INSERT IGNORE INTO period_cycles (tracked_user_id, started_on) VALUES (?, ?)')
                    ->execute([(int) $user['id'], $start->format('Y-m-d')]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
        $fresh = $this->getUserById((int) $user['id']);
        if ($fresh['pending_pair_code']) {
            $code = (string) $fresh['pending_pair_code'];
            $this->updateUser($fresh['id'], ['pending_pair_code' => null]);
            $this->connectWithCode($fresh, $code);
            $fresh = $this->getUserById((int) $fresh['id']);
        }
        $this->telegram->send($fresh['chat_id'], $this->t($fresh, $active ? 'setup_done_active' : 'setup_done'), $this->mainKeyboard($fresh));
        if ($fresh['partner_id'] === null) {
            $this->telegram->send($fresh['chat_id'], $this->t($fresh, 'partner_optional'), [
                'inline_keyboard' => [
                    [
                        ['text' => $this->t($fresh, 'share_partner'), 'callback_data' => 'partner:generate', 'style' => 'success'],
                        ['text' => $this->t($fresh, 'enter_code'), 'callback_data' => 'partner:enter', 'style' => 'primary'],
                    ],
                    [['text' => $this->t($fresh, 'not_now'), 'callback_data' => 'partner:skip']],
                ],
            ]);
        }
    }

    private function showMenu(array $user): void
    {
        if (($user['state'] ?? null) === 'note_write') {
            $user = $this->restoreNoteState($user);
        }
        $user = $this->getUserById((int) $user['id']);
        if ($user['language'] === null || $user['gender'] === null) {
            $this->start($user);
            return;
        }
        if ($user['gender'] === 'female' && in_array($user['state'], [
            'cycle_length', 'cycle_custom', 'bleed_length', 'bleed_custom',
            'ovulation_day', 'ovulation_custom', 'pms_days', 'pms_custom',
            'last_period_date', 'last_period_custom',
        ], true)) {
            $this->resumeFemaleSetup($user);
            return;
        }
        $inline = [];
        $inline[] = [
            ['text' => $this->t($user, 'status'), 'callback_data' => 'menu:status', 'style' => 'primary'],
            ['text' => $this->t($user, 'settings'), 'callback_data' => 'menu:settings', 'style' => 'primary'],
        ];
        $inline[] = [
            ['text' => $this->t($user, 'creator_button'), 'callback_data' => 'menu:creator'],
            ['text' => $this->t($user, 'free_button'), 'callback_data' => 'menu:free'],
        ];
        $this->telegram->send($user['chat_id'], $this->t($user, 'welcome'), ['inline_keyboard' => $inline]);
        $this->telegram->send($user['chat_id'], $this->t($user, 'keyboard_ready'), $this->mainKeyboard($user));
    }

    private function showSettings(array $user): void
    {
        $buttons = [];
        if ($user['gender'] === 'female') {
            $buttons[] = [['text' => $this->t($user, 'cycle_settings'), 'callback_data' => 'settings:profile']];
        }
        $buttons[] = [['text' => $this->t($user, 'language_settings'), 'callback_data' => 'settings:language']];
        if ($user['partner_id'] !== null) {
            $buttons[] = [['text' => $this->t($user, 'disconnect_partner'), 'callback_data' => 'settings:disconnect', 'style' => 'danger']];
        }
        $buttons[] = [['text' => $this->t($user, 'delete_profile'), 'callback_data' => 'settings:delete', 'style' => 'danger']];
        $buttons[] = [['text' => $this->t($user, 'back'), 'callback_data' => 'settings:menu']];
        $this->telegram->send($user['chat_id'], $this->t($user, 'settings'), ['inline_keyboard' => $buttons]);
    }

    private function showDisconnectConfirmation(array $user): void
    {
        if ($user['partner_id'] === null) {
            $this->telegram->send($user['chat_id'], $this->t($user, 'no_partner'), $this->mainKeyboard($user));
            return;
        }
        $this->updateUser($user['id'], ['state' => 'confirm_disconnect']);
        $this->telegram->send($user['chat_id'], $this->t($user, 'disconnect_warning'), [
            'inline_keyboard' => [[
                ['text' => $this->t($user, 'confirm_disconnect'), 'callback_data' => 'settings:disconnect_confirm', 'style' => 'danger'],
                ['text' => $this->t($user, 'cancel_disconnect'), 'callback_data' => 'settings:disconnect_cancel'],
            ]],
        ]);
    }

    private function cancelDisconnect(array $user): void
    {
        if ($user['state'] === 'confirm_disconnect') {
            $this->updateUser($user['id'], ['state' => 'ready']);
        }
        $this->showSettings($this->getUserById((int) $user['id']));
    }

    private function disconnectPartner(array $user): void
    {
        if ($user['state'] !== 'confirm_disconnect') {
            $this->showSettings($user);
            return;
        }
        if ($user['partner_id'] === null) {
            $this->updateUser($user['id'], ['state' => 'ready']);
            $fresh = $this->getUserById((int) $user['id']);
            $this->telegram->send($fresh['chat_id'], $this->t($fresh, 'no_partner'), $this->mainKeyboard($fresh));
            return;
        }

        $userId = (int) $user['id'];
        $partnerId = (int) $user['partner_id'];
        $disconnected = false;
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT id, partner_id FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
            $stmt->execute([$userId, $partnerId]);
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                if ((int) $row['id'] === $userId && $row['partner_id'] !== null && (int) $row['partner_id'] === $partnerId) {
                    $disconnected = true;
                    break;
                }
            }
            $stmt = $this->db->prepare("UPDATE users SET partner_id = NULL, state = CASE WHEN state = 'confirm_disconnect' THEN 'ready' ELSE state END WHERE (id = ? AND partner_id = ?) OR (id = ? AND partner_id = ?)");
            $stmt->execute([$userId, $partnerId, $partnerId, $userId]);
            if ($disconnected) {
                $stmt = $this->db->prepare('UPDATE pair_codes SET used_at = NOW() WHERE owner_user_id IN (?, ?) AND used_at IS NULL');
                $stmt->execute([$userId, $partnerId]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $fresh = $this->getUserById($userId);
        if (!$disconnected) {
            $this->telegram->send($fresh['chat_id'], $this->t($fresh, 'no_partner'), $this->mainKeyboard($fresh));
            return;
        }
        $this->telegram->send($fresh['chat_id'], $this->t($fresh, 'disconnect_done'), $this->mainKeyboard($fresh));
        try {
            $partner = $this->getUserById($partnerId);
            $this->safeSend($partner['chat_id'], $this->t($partner, 'partner_disconnected'), $this->mainKeyboard($partner));
        } catch (Throwable $e) {
            error_log('Period bot disconnect notice skipped: ' . $e->getMessage());
        }
    }

    private function showDeleteProfileConfirmation(array $user): void
    {
        if ($user['gender'] === null) {
            $this->start($user);
            return;
        }
        $this->updateUser($user['id'], ['state' => 'confirm_delete']);
        $this->telegram->send($user['chat_id'], $this->t($user, 'delete_profile_warning'), [
            'inline_keyboard' => [[
                ['text' => $this->t($user, 'confirm_delete'), 'callback_data' => 'settings:delete_confirm', 'style' => 'danger'],
                ['text' => $this->t($user, 'cancel_delete'), 'callback_data' => 'settings:delete_cancel'],
            ]],
        ]);
    }

    private function cancelDeleteProfile(array $user): void
    {
        if ($user['state'] === 'confirm_delete') {
            $this->updateUser($user['id'], ['state' => 'ready']);
        }
        $this->showSettings($this->getUserById((int) $user['id']));
    }

    private function deleteProfile(array $user): void
    {
        if ($user['state'] !== 'confirm_delete') {
            $this->showSettings($user);
            return;
        }
        $partnerId = $user['partner_id'] !== null ? (int) $user['partner_id'] : null;
        $chatId = (int) $user['chat_id'];
        $deletedMessage = $this->t($user, 'profile_deleted');

        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('DELETE FROM users WHERE id = ?');
            $stmt->execute([(int) $user['id']]);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        $this->telegram->send($chatId, $deletedMessage, ['remove_keyboard' => true]);
        if ($partnerId !== null) {
            try {
                $partner = $this->getUserById($partnerId);
                $this->safeSend($partner['chat_id'], $this->t($partner, 'partner_profile_deleted'), $this->mainKeyboard($partner));
            } catch (Throwable $e) {
                error_log('Period bot partner delete notice skipped: ' . $e->getMessage());
            }
        }
        $this->askLanguage($chatId);
    }

    private function beginCycleSetup(array $user): void
    {
        if ($user['gender'] !== 'female') {
            $this->showMenu($user);
            return;
        }
        $this->updateUser($user['id'], ['state' => 'cycle_length']);
        $this->askCycleLength($user);
    }

    private function showPartnerMenu(array $user): void
    {
        if ($user['partner_id'] !== null) {
            $this->telegram->send($user['chat_id'], $this->t($user, 'already_connected'), $this->mainKeyboard($user));
            return;
        }
        if ($user['gender'] === 'male') {
            $this->telegram->send($user['chat_id'], $this->t($user, 'partner_menu'), $this->mainKeyboard($user));
            return;
        }
        $buttons = [[
            ['text' => $this->t($user, 'share_partner'), 'callback_data' => 'partner:generate', 'style' => 'success'],
            ['text' => $this->t($user, 'enter_code'), 'callback_data' => 'partner:enter', 'style' => 'primary'],
        ]];
        $this->telegram->send($user['chat_id'], $this->t($user, 'lady_partner_menu'), [
            'inline_keyboard' => $buttons,
        ]);
    }

    private function beginCodeEntry(array $user): void
    {
        if (!in_array($user['gender'], ['female', 'male'], true)) {
            $this->start($user);
            return;
        }
        if ($user['partner_id'] !== null) {
            $this->telegram->send($user['chat_id'], $this->t($user, 'already_connected'), $this->mainKeyboard($user));
            return;
        }
        $this->updateUser($user['id'], ['state' => 'pair_code']);
        $this->telegram->send($user['chat_id'], $this->t($user, 'enter_code_prompt'));
    }

    private function sendInvite(array $user): void
    {
        if (!in_array($user['gender'], ['female', 'male'], true)) {
            $this->start($user);
            return;
        }
        if ($user['partner_id'] !== null) {
            $this->telegram->send($user['chat_id'], $this->t($user, 'already_connected'));
            return;
        }
        $code = $this->newPairCode((int) $user['id']);
        $link = 'https://t.me/' . rawurlencode($this->botUsername) . '?start=link_' . $code;
        $text = $this->t($user, 'invite_text', ['code' => $code, 'link' => $link]);
        $this->telegram->send($user['chat_id'], $text, [
            'inline_keyboard' => [[['text' => $this->t($user, 'open_link'), 'url' => $link, 'style' => 'success']]],
        ]);
    }

    private function connectWithCode(array $user, string $code): void
    {
        $code = $this->toEnglishDigits(trim($code));
        if (!$this->allowAttempt((int) $user['id'], 'pair_code', 6, 15)) {
            $this->telegram->send($user['chat_id'], $this->t($user, 'too_many_attempts'));
            return;
        }
        if (!preg_match('/^[0-9]{8}$/', $code) || !$this->validLuhn($code)) {
            $this->telegram->send($user['chat_id'], $this->t($user, 'wrong_code'));
            return;
        }
        $hash = hash_hmac('sha256', $code, $this->appSecret);
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT pc.*, u.gender AS owner_gender, u.partner_id AS owner_partner FROM pair_codes pc JOIN users u ON u.id = pc.owner_user_id WHERE pc.code_hash = ? FOR UPDATE');
            $stmt->execute([$hash]);
            $pair = $stmt->fetch();
            if (!$pair) {
                throw new DomainException('wrong');
            }
            if ($pair['used_at'] !== null) {
                throw new DomainException('used');
            }
            if (new DateTimeImmutable($pair['expires_at'], $this->timezone) <= new DateTimeImmutable('now', $this->timezone)) {
                throw new DomainException('expired');
            }
            if ((int) $pair['owner_user_id'] === (int) $user['id']) {
                throw new DomainException('self');
            }
            if ($user['gender'] === null) {
                $this->db->rollBack();
                $this->updateUser($user['id'], ['pending_pair_code' => $code]);
                $this->start($user);
                return;
            }
            $stmt = $this->db->prepare('SELECT gender, partner_id FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([(int) $user['id']]);
            $receiver = $stmt->fetch();
            if (!$receiver) {
                throw new DomainException('wrong');
            }
            $validRoles = ($pair['owner_gender'] === 'female' && in_array($receiver['gender'], ['female', 'male'], true))
                || ($pair['owner_gender'] === 'male' && $receiver['gender'] === 'female');
            if (!$validRoles) {
                throw new DomainException('role');
            }
            if ($pair['owner_partner'] !== null || $receiver['partner_id'] !== null) {
                throw new DomainException('connected');
            }
            $stmt = $this->db->prepare('UPDATE users SET partner_id = CASE WHEN id = ? THEN ? WHEN id = ? THEN ? END, state = CASE WHEN state = \'pair_code\' THEN \'ready\' ELSE state END WHERE id IN (?, ?)');
            $stmt->execute([$pair['owner_user_id'], $user['id'], $user['id'], $pair['owner_user_id'], $pair['owner_user_id'], $user['id']]);
            $stmt = $this->db->prepare('UPDATE pair_codes SET used_at = NOW() WHERE id = ?');
            $stmt->execute([$pair['id']]);
            $this->db->commit();
            $owner = $this->getUserById((int) $pair['owner_user_id']);
            $fresh = $this->getUserById((int) $user['id']);
            $this->safeSend($owner['chat_id'], $this->t($owner, 'connected'), $this->mainKeyboard($owner));
            $this->safeSend($fresh['chat_id'], $this->t($fresh, 'connected'), $this->mainKeyboard($fresh));
        } catch (DomainException $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $key = match ($e->getMessage()) {
                'expired' => 'expired_code',
                'used' => 'used_code',
                'role' => 'role_mismatch',
                'connected' => 'already_connected',
                'self' => 'self_code',
                default => 'wrong_code',
            };
            $this->telegram->send($user['chat_id'], $this->t($user, $key));
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function recordPeriod(array $user): void
    {
        if ($user['gender'] !== 'female') {
            $this->telegram->send($user['chat_id'], $this->t($user, 'female_only'));
            return;
        }
        if (!$this->allowAttempt((int) $user['id'], 'period_start', 3, 60)) {
            $this->telegram->send($user['chat_id'], $this->t($user, 'period_rate_limit'));
            return;
        }
        $today = new DateTimeImmutable('today', $this->timezone);
        $deviation = null;
        $result = 'recorded';
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([(int) $user['id']]);
            $locked = $stmt->fetch();
            if (!$locked || $locked['gender'] !== 'female') {
                throw new RuntimeException('Period owner unavailable');
            }
            if (!empty($locked['period_active'])) {
                $result = 'active';
            } elseif ($locked['last_period_date'] === $today->format('Y-m-d')) {
                $result = 'same_day';
            } else {
                if ($locked['last_period_date']) {
                    $previous = new DateTimeImmutable($locked['last_period_date'], $this->timezone);
                    $predicted = $previous->modify('+' . (int) $locked['cycle_length'] . ' days');
                    $deviation = (int) $predicted->diff($today)->format('%r%a');
                }
                $this->updateUser($user['id'], [
                    'last_period_date' => $today->format('Y-m-d'),
                    'period_active' => 1,
                    'last_period_end_date' => null,
                    'state' => 'ready',
                ]);
                $this->db->prepare('INSERT IGNORE INTO period_cycles (tracked_user_id, started_on) VALUES (?, ?)')
                    ->execute([(int) $user['id'], $today->format('Y-m-d')]);
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
        $fresh = $this->getUserById((int) $user['id']);
        if ($result !== 'recorded') {
            $this->telegram->send($fresh['chat_id'], $this->t($fresh, $result === 'active' ? 'period_already_active' : 'already_recorded'), $this->mainKeyboard($fresh));
            return;
        }
        $this->markNotification((int) $fresh['id'], 'period_started', $today);
        $this->notifyTracked($fresh, $this->t($fresh, 'period_woman'), $this->partnerText($fresh, 'period_partner'));
        if ($deviation !== null && $deviation <= -3) {
            $this->notifyTracked($fresh, $this->t($fresh, 'early_woman'), $this->partnerText($fresh, 'early_partner'));
        } elseif ($deviation !== null && $deviation >= 1) {
            $this->notifyTracked($fresh, $this->t($fresh, 'late_arrived_woman'), $this->partnerText($fresh, 'late_arrived_partner'));
        }
    }

    private function recordPeriodEnd(array $user): void
    {
        if ($user['gender'] !== 'female') {
            $this->telegram->send($user['chat_id'], $this->t($user, 'female_only'));
            return;
        }
        $today = new DateTimeImmutable('today', $this->timezone);
        $ended = false;
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ? FOR UPDATE');
            $stmt->execute([(int) $user['id']]);
            $locked = $stmt->fetch();
            if (!$locked || $locked['gender'] !== 'female') {
                throw new RuntimeException('Period owner unavailable');
            }
            if (!empty($locked['period_active']) && $locked['last_period_date'] !== null) {
                $this->updateUser($user['id'], [
                    'period_active' => 0,
                    'last_period_end_date' => $today->format('Y-m-d'),
                    'state' => 'ready',
                ]);
                $this->db->prepare('INSERT INTO period_cycles (tracked_user_id, started_on, ended_on) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE ended_on = VALUES(ended_on)')
                    ->execute([(int) $user['id'], $locked['last_period_date'], $today->format('Y-m-d')]);
                $ended = true;
            }
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
        $fresh = $this->getUserById((int) $user['id']);
        if (!$ended) {
            $this->telegram->send($fresh['chat_id'], $this->t($fresh, 'period_not_active'), $this->mainKeyboard($fresh));
            return;
        }
        $this->markNotification((int) $fresh['id'], 'period_ended', $today);
        $this->notifyTracked($fresh, $this->t($fresh, 'period_end_woman'), $this->partnerText($fresh, 'period_end_partner'));
    }

    public function sendDailyNotifications(): int
    {
        $today = new DateTimeImmutable('today', $this->timezone);
        $rows = $this->db->query("SELECT * FROM users WHERE gender = 'female' AND last_period_date IS NOT NULL")->fetchAll();
        $count = 0;
        foreach ($rows as $user) {
            try {
            $start = new DateTimeImmutable($user['last_period_date'], $this->timezone);
            if ($today < $start) {
                continue;
            }
            if (!$this->scheduleIsCurrent($start, $today)) {
                continue;
            }
            $cycle = max(1, min(255, (int) $user['cycle_length']));
            $ovulationDay = min($cycle, max(1, (int) $user['ovulation_day']));
            $pmsDaysBefore = min($cycle, max(1, (int) $user['pms_days_before']));
            $nextPeriod = $start->modify('+' . $cycle . ' days');
            $ovulation = $start->modify('+' . ($ovulationDay - 1) . ' days');
            $pms = $nextPeriod->modify('-' . $pmsDaysBefore . ' days');

            if ($today->format('Y-m-d') === $ovulation->format('Y-m-d')) {
                $count += $this->sendEventOnce($user, 'ovulation', $today, 'ovulation_woman', 'ovulation_partner');
            }
            if ($today->format('Y-m-d') === $pms->format('Y-m-d')) {
                $count += $this->sendEventOnce($user, 'pms', $today, 'pms_woman', 'pms_partner');
            }
            if ($today->format('Y-m-d') === $nextPeriod->format('Y-m-d')) {
                $count += $this->sendPeriodDueOnce($user, $today);
            }
            $lateDays = (int) $nextPeriod->diff($today)->format('%r%a');
            if ($lateDays >= 1 && $lateDays <= 5) {
                $count += $this->sendEventOnce(
                    $user,
                    'late_' . $lateDays,
                    $today,
                    'late_daily_woman',
                    'late_daily_partner',
                    ['days' => $lateDays]
                );
            }
            } catch (Throwable $e) {
                error_log('Period bot notification skipped for user ' . (int) $user['id'] . ': ' . $e->getMessage());
            }
        }
        return $count;
    }

    private function sendPeriodDueOnce(array $user, DateTimeImmutable $date): int
    {
        if (!$this->markNotification((int) $user['id'], 'period_due', $date)) {
            return 0;
        }
        try {
            $this->telegram->send($user['chat_id'], $this->t($user, 'period_due_woman'), $this->mainKeyboard($user));
        } catch (Throwable $e) {
            $this->unmarkNotification((int) $user['id'], 'period_due', $date);
            throw $e;
        }
        if ($user['partner_id'] !== null) {
            try {
                $this->withCurrentPartner($user, function (array $fresh, array $partner): void {
                    $this->safeSend($partner['chat_id'], $this->t($partner, 'period_due_partner'), $this->mainKeyboard($partner));
                });
            } catch (Throwable $e) {
                error_log('Period bot due-date partner delivery skipped: ' . $e->getMessage());
            }
        }
        return 1;
    }

    private function sendEventOnce(array $user, string $type, DateTimeImmutable $date, string $womanKey, string $partnerKey, array $vars = []): int
    {
        if (!$this->markNotification((int) $user['id'], $type, $date)) {
            return 0;
        }
        try {
            $this->notifyTracked($user, $this->t($user, $womanKey, $vars), $this->partnerText($user, $partnerKey, $vars));
        } catch (Throwable $e) {
            $this->unmarkNotification((int) $user['id'], $type, $date);
            throw $e;
        }
        return 1;
    }

    private function unmarkNotification(int $userId, string $type, DateTimeImmutable $date): void
    {
        $stmt = $this->db->prepare('DELETE FROM sent_notifications WHERE tracked_user_id = ? AND event_type = ? AND event_date = ?');
        $stmt->execute([$userId, $type, $date->format('Y-m-d')]);
    }

    private function markNotification(int $userId, string $type, DateTimeImmutable $date): bool
    {
        $stmt = $this->db->prepare('INSERT IGNORE INTO sent_notifications (tracked_user_id, event_type, event_date) VALUES (?, ?, ?)');
        $stmt->execute([$userId, $type, $date->format('Y-m-d')]);
        return $stmt->rowCount() === 1;
    }

    private function notifyTracked(array $woman, string $womanText, string $partnerText): void
    {
        $this->telegram->send($woman['chat_id'], $womanText, $this->mainKeyboard($woman));
        if ($woman['partner_id'] !== null) {
            try {
                $this->withCurrentPartner($woman, function (array $fresh, array $partner) use ($partnerText): void {
                    $this->safeSend($partner['chat_id'], $partnerText, $this->mainKeyboard($partner));
                });
            } catch (Throwable $e) {
                error_log('Period bot partner notification skipped: ' . $e->getMessage());
            }
        }
    }

    private function safeSend(int|string $chatId, string $text, ?array $replyMarkup = null): bool
    {
        try {
            $this->telegram->send($chatId, $text, $replyMarkup);
            return true;
        } catch (Throwable $e) {
            error_log('Period bot secondary Telegram delivery failed: ' . $e->getMessage());
            return false;
        }
    }

    private function partnerText(array $woman, string $key, array $vars = []): string
    {
        if ($woman['partner_id'] === null) {
            return $this->t($woman, $key, $vars);
        }
        return $this->t($this->getUserById((int) $woman['partner_id']), $key, $vars);
    }

    /** Serialize private partner-data delivery with disconnect/delete operations. */
    private function withCurrentPartner(array $snapshot, callable $action): bool
    {
        $userId = (int) $snapshot['id'];
        $partnerId = (int) ($snapshot['partner_id'] ?? 0);
        if ($partnerId <= 0 || $partnerId === $userId) {
            return false;
        }
        $ownsTransaction = !$this->db->inTransaction();
        if ($ownsTransaction) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare('SELECT * FROM users WHERE id IN (?, ?) ORDER BY id FOR UPDATE');
            $stmt->execute([$userId, $partnerId]);
            $rows = [];
            foreach ($stmt->fetchAll() as $row) {
                $rows[(int) $row['id']] = $row;
            }
            $valid = isset($rows[$userId], $rows[$partnerId])
                && (int) ($rows[$userId]['partner_id'] ?? 0) === $partnerId
                && (int) ($rows[$partnerId]['partner_id'] ?? 0) === $userId;
            if ($valid) {
                // Keep both row locks until the bounded Telegram request completes.
                $action($rows[$userId], $rows[$partnerId]);
            }
            if ($ownsTransaction) {
                $this->db->commit();
            }
            return $valid;
        } catch (Throwable $e) {
            if ($ownsTransaction && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    private function sendStatus(array $user, bool $partnerStatus = false): void
    {
        if ($user['gender'] === 'male' || $partnerStatus) {
            $sent = $this->withCurrentPartner($user, function (array $fresh, array $partner): void {
                $this->sendTrackedStatus($fresh, $partner);
            });
            if (!$sent) {
                $this->telegram->send($user['chat_id'], $this->t($user, 'no_partner'), $this->mainKeyboard($user));
            }
            return;
        }
        $this->sendTrackedStatus($user, $user);
    }

    private function sendTrackedStatus(array $user, array $tracked): void
    {
        if ($tracked['last_period_date'] === null) {
            $this->telegram->send($user['chat_id'], $this->t($user, 'no_period'), $this->mainKeyboard($user));
            return;
        }
        $start = new DateTimeImmutable($tracked['last_period_date'], $this->timezone);
        $today = new DateTimeImmutable('today', $this->timezone);
        if (!$this->scheduleIsCurrent($start, $today)) {
            $text = $this->t($user, 'status_stale_text', [
                'last' => $this->formatDateForUser($user, $start),
                'cycle' => $this->formatNumberForUser($user, (int) $tracked['cycle_length']),
                'bleed' => $this->formatNumberForUser($user, (int) $tracked['bleed_length']),
            ]);
            $this->telegram->send($user['chat_id'], $text, $this->mainKeyboard($user));
            return;
        }
        $cycle = max(1, min(255, (int) $tracked['cycle_length']));
        $ovulationDay = min($cycle, max(1, (int) $tracked['ovulation_day']));
        $pmsDaysBefore = min($cycle, max(1, (int) $tracked['pms_days_before']));
        $next = $start->modify('+' . $cycle . ' days');
        $ovulationDate = $start->modify('+' . ($ovulationDay - 1) . ' days');
        $pmsDate = $next->modify('-' . $pmsDaysBefore . ' days');
        $offset = (int) $next->diff($today)->format('%r%a');
        if ($offset > 0) {
            $timing = $this->t($user, 'status_late', ['days' => $this->formatNumberForUser($user, $offset)]);
        } elseif ($offset === 0) {
            $timing = $this->t($user, 'status_due_today');
        } else {
            $timing = $this->t($user, 'status_until_due', ['days' => $this->formatNumberForUser($user, abs($offset))]);
        }
        $text = $this->t($user, 'status_text', [
            'last' => $this->formatDateForUser($user, $start),
            'next' => $this->formatDateForUser($user, $next),
            'cycle' => $tracked['cycle_length'],
            'bleed' => $tracked['bleed_length'],
            'ovulation_date' => $this->formatDateForUser($user, $ovulationDate),
            'pms_date' => $this->formatDateForUser($user, $pmsDate),
            'timing' => $timing,
        ]);
        $this->telegram->send($user['chat_id'], $text, $this->mainKeyboard($user));
    }

    private function showCycleHistory(array $user, bool $partnerHistory): void
    {
        if ($partnerHistory) {
            $shown = $this->withCurrentPartner($user, function (array $fresh, array $partner): void {
                $this->sendCycleHistory($fresh, $partner);
            });
            if (!$shown) {
                $this->telegram->send($user['chat_id'], $this->t($user, 'no_partner'), $this->mainKeyboard($user));
            }
            return;
        }
        if ($user['gender'] !== 'female') {
            $this->telegram->send($user['chat_id'], $this->t($user, 'no_partner'), $this->mainKeyboard($user));
            return;
        }
        $this->sendCycleHistory($user, $user);
    }

    private function sendCycleHistory(array $viewer, array $tracked): void
    {
        if ($tracked['gender'] !== 'female') {
            $this->telegram->send($viewer['chat_id'], $this->t($viewer, 'history_no_cycle'), $this->mainKeyboard($viewer));
            return;
        }
        $stmt = $this->db->prepare('SELECT p.started_on, p.ended_on,
            (SELECT MIN(n.started_on) FROM period_cycles n
             WHERE n.tracked_user_id = p.tracked_user_id AND n.started_on > p.started_on) AS next_start_on
            FROM period_cycles p WHERE p.tracked_user_id = ? ORDER BY p.started_on DESC LIMIT 5');
        $stmt->execute([(int) $tracked['id']]);
        $cycles = $stmt->fetchAll();
        if ($cycles === []) {
            $this->telegram->send($viewer['chat_id'], $this->t($viewer, 'history_empty'), $this->mainKeyboard($viewer));
            return;
        }
        $lines = [$this->t($viewer, 'history_title')];
        foreach ($cycles as $index => $cycle) {
            $started = new DateTimeImmutable($cycle['started_on'], $this->timezone);
            $next = $cycle['next_start_on'] !== null ? new DateTimeImmutable($cycle['next_start_on'], $this->timezone) : null;
            $ended = $cycle['ended_on'] !== null ? new DateTimeImmutable($cycle['ended_on'], $this->timezone) : null;
            $cycleDays = $next === null ? $this->t($viewer, 'history_unknown')
                : $this->formatNumberForUser($viewer, (int) $started->diff($next)->format('%a')) . ' ' . $this->t($viewer, 'history_days');
            $bleedDays = $ended === null ? $this->t($viewer, 'history_unknown')
                : $this->formatNumberForUser($viewer, (int) $started->diff($ended)->format('%a') + 1) . ' ' . $this->t($viewer, 'history_days');
            if ($ended === null && !empty($tracked['period_active']) && $tracked['last_period_date'] === $cycle['started_on']) {
                $bleedDays = $this->t($viewer, 'history_in_progress');
            }
            $lines[] = $this->t($viewer, 'history_entry', [
                'number' => $this->formatNumberForUser($viewer, $index + 1),
                'start' => $this->formatDateForUser($viewer, $started),
                'cycle' => $cycleDays,
                'bleed' => $bleedDays,
            ]);
        }
        $this->telegram->send($viewer['chat_id'], implode("\n\n", $lines), $this->mainKeyboard($viewer));
    }

    private function sendCreator(array $user): void
    {
        $this->telegram->send($user['chat_id'], $this->t($user, 'creator_text'), $this->mainKeyboard($user));
    }

    private function sendWhyFree(array $user): void
    {
        $this->telegram->send($user['chat_id'], $this->t($user, 'free_text'), $this->mainKeyboard($user));
    }

    private function showNotesMenu(array $user): void
    {
        if (($user['state'] ?? null) === 'note_write') {
            $user = $this->restoreNoteState($user);
        }
        $this->telegram->send($user['chat_id'], $this->t($user, 'notes_intro'), [
            'inline_keyboard' => [
                [['text' => $this->t($user, 'notes_write'), 'callback_data' => 'notes:write']],
                [['text' => $this->t($user, 'notes_recent'), 'callback_data' => 'notes:list']],
                [['text' => $this->t($user, 'back'), 'callback_data' => 'settings:menu']],
            ],
        ]);
    }

    private function beginNote(array $user): void
    {
        $returnState = (string) ($user['state'] ?? 'ready');
        if ($returnState === 'note_write') {
            $returnState = (string) ($user['note_return_state'] ?? 'ready');
        }
        $this->updateUser($user['id'], ['state' => 'note_write', 'note_return_state' => $returnState]);
        $this->telegram->send($user['chat_id'], $this->t($user, 'notes_prompt'), [
            'inline_keyboard' => [[['text' => $this->t($user, 'notes_cancel'), 'callback_data' => 'notes:cancel']]],
        ]);
    }

    private function cancelNote(array $user): void
    {
        if ($user['state'] === 'note_write') {
            $user = $this->restoreNoteState($user);
        }
        $this->showNotesMenu($user);
    }

    private function saveNote(array $user, string $text, array $message): void
    {
        $hasMedia = isset($message['photo']) || isset($message['video']) || isset($message['animation'])
            || isset($message['document']) || isset($message['audio']) || isset($message['voice'])
            || isset($message['video_note']) || isset($message['sticker']);
        if ($hasMedia || $text === '') {
            $this->telegram->send($user['chat_id'], $this->t($user, 'notes_text_only'));
            return;
        }
        $text = preg_replace('/[\x{200B}-\x{200D}\x{2060}\x{FEFF}]/u', '', trim($text)) ?? '';
        if (mb_strlen($text) > 300) {
            $this->telegram->send($user['chat_id'], $this->t($user, 'notes_too_long'));
            return;
        }
        if ($this->noteContainsContact($text, $message['entities'] ?? [])) {
            $this->telegram->send($user['chat_id'], $this->t($user, 'notes_contact_blocked'));
            return;
        }
        if (!$this->allowAttempt((int) $user['id'], 'note_create', 3, 60)) {
            $this->telegram->send($user['chat_id'], $this->t($user, 'notes_rate_limited'));
            return;
        }
        $stmt = $this->db->prepare('INSERT INTO notes (author_user_id, author_gender, body) VALUES (?, ?, ?)');
        $stmt->execute([(int) $user['id'], $user['gender'] ?: null, $text]);
        $fresh = $this->restoreNoteState($user);
        $this->telegram->send($user['chat_id'], $this->t($user, 'notes_saved'), $this->mainKeyboard($fresh));
    }

    private function noteContainsContact(string $text, array $entities = []): bool
    {
        foreach ($entities as $entity) {
            if (in_array((string) ($entity['type'] ?? ''), ['mention', 'text_mention', 'phone_number', 'url', 'text_link', 'email'], true)) {
                return true;
            }
        }
        $normalized = $this->toEnglishDigits($text);
        if (preg_match('/@[\p{L}\p{N}_]{3,}/u', $normalized)) {
            return true;
        }
        if (preg_match('~(?:https?://|www\.|t\.me/|telegram\.me/|wa\.me/|mailto:)~iu', $normalized)) {
            return true;
        }
        if (preg_match('/(?:\+?\d[^\p{L}\p{N}]{0,3}){7,}/u', $normalized)) {
            return true;
        }
        return (bool) preg_match('/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}.\-]+\.[\p{L}]{2,}/u', $normalized);
    }

    private function showRecentNotes(array $user): void
    {
        if ($user['state'] === 'note_write') {
            $user = $this->restoreNoteState($user);
        }
        $stmt = $this->db->query('SELECT author_gender, body FROM notes ORDER BY created_at DESC, id DESC LIMIT 10');
        $notes = $stmt->fetchAll();
        if ($notes === []) {
            $this->telegram->send($user['chat_id'], $this->t($user, 'notes_empty'), $this->mainKeyboard($user));
            return;
        }
        $parts = [];
        foreach ($notes as $note) {
            $role = $this->t($user, ($note['author_gender'] ?? null) === 'female' ? 'note_lady' : 'note_partner');
            $body = htmlspecialchars((string) $note['body'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $parts[] = $role . "\n" . $this->t($user, 'note_text_label') . "\n" . $body;
        }
        $this->telegram->send($user['chat_id'], implode("\n\n──────────\n\n", $parts), [
            'inline_keyboard' => [[
                ['text' => $this->t($user, 'notes_write'), 'callback_data' => 'notes:write'],
                ['text' => $this->t($user, 'back'), 'callback_data' => 'notes:menu'],
            ]],
        ]);
    }

    private function mainKeyboard(array $user): array
    {
        if (($user['gender'] ?? null) === null) {
            return $this->infoKeyboard($user);
        }
        $fa = ($user['language'] ?? 'fa') === 'fa';
        $rows = [];
        if ($user['gender'] === 'female') {
            $periodButton = !empty($user['period_active'])
                ? ($fa ? '🩸 الان پریودم تموم شد' : '🩸 My period ended')
                : ($fa ? '🩸 الان پریود شدم' : '🩸 My period started');
            $rows[] = [['text' => $periodButton]];
            $rows[] = [['text' => $fa ? '📊 وضعیت من' : '📊 My status'], ['text' => $fa ? '⚙️ تنظیمات' : '⚙️ Settings']];
            $rows[] = [['text' => $fa ? '📚 تاریخچه ۵ چرخه اخیر' : '📚 Last 5 cycles']];
            if ($user['partner_id'] !== null) {
                $partner = $this->getUserById((int) $user['partner_id']);
                if ($partner['gender'] === 'female') {
                    $rows[] = [
                        ['text' => $fa ? '📊 وضعیت پارتنر' : '📊 Partner status'],
                        ['text' => $fa ? '📚 تاریخچه پارتنر' : '📚 Partner cycle history'],
                    ];
                }
            }
        } else {
            if ($user['partner_id'] === null) {
                $rows[] = [
                    ['text' => $fa ? '🔐 ساخت کد/لینک اتصال' : '🔐 Create invite code/link'],
                    ['text' => $fa ? '🔑 کد یار را وارد کن' : '🔑 Enter partner code'],
                ];
            }
            $rows[] = [['text' => $fa ? '📊 وضعیت پارتنر' : '📊 Partner status'], ['text' => $fa ? '⚙️ تنظیمات' : '⚙️ Settings']];
            $rows[] = [['text' => $fa ? '📚 تاریخچه ۵ چرخه اخیر' : '📚 Last 5 cycles']];
        }
        if ($user['partner_id'] === null) {
            if ($user['gender'] === 'female') {
                $rows[] = [
                    ['text' => $fa ? '🤝 با یارت به اشتراک بذار' : '🤝 Share with your partner'],
                    ['text' => $fa ? '🔑 کد یار را وارد کن' : '🔑 Enter partner code'],
                ];
            }
        }
        $rows[] = [['text' => $fa ? '💌 دل‌نوشته‌ها (ناشناس)' : '💌 Notes (Anonymous)']];
        $rows[] = [['text' => $fa ? '👤 درباره سازنده' : '👤 About the Creator'], ['text' => $fa ? '🎁 چرا رایگان است؟' : '🎁 Why is it free?']];
        return ['keyboard' => $rows, 'resize_keyboard' => true, 'is_persistent' => true];
    }

    private function infoKeyboard(array $user): array
    {
        $fa = ($user['language'] ?? 'fa') === 'fa';
        return [
            'keyboard' => [
                [['text' => $fa ? '💌 دل‌نوشته‌ها (ناشناس)' : '💌 Notes (Anonymous)']],
                [
                    ['text' => $fa ? '👤 درباره سازنده' : '👤 About the Creator'],
                    ['text' => $fa ? '🎁 چرا رایگان است؟' : '🎁 Why is it free?'],
                ],
            ],
            'resize_keyboard' => true,
            'is_persistent' => true,
        ];
    }

    private function restoreNoteState(array $user): array
    {
        $returnState = (string) ($user['note_return_state'] ?? '');
        if ($returnState === '' || $returnState === 'note_write') {
            $returnState = 'ready';
        }
        $this->updateUser((int) $user['id'], ['state' => $returnState, 'note_return_state' => null]);
        return $this->getUserById((int) $user['id']);
    }

    private function newPairCode(int $ownerUserId): string
    {
        $this->db->prepare('UPDATE pair_codes SET used_at = NOW() WHERE owner_user_id = ? AND used_at IS NULL')->execute([$ownerUserId]);
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $base = (string) random_int(1000000, 9999999);
            $code = $base . $this->luhnCheckDigit($base);
            $hash = hash_hmac('sha256', $code, $this->appSecret);
            try {
                $stmt = $this->db->prepare('INSERT INTO pair_codes (code_hash, owner_user_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))');
                $stmt->execute([$hash, $ownerUserId]);
                return $code;
            } catch (PDOException $e) {
                if ((string) $e->getCode() !== '23000') {
                    throw $e;
                }
            }
        }
        throw new RuntimeException('Could not generate a unique pair code');
    }

    private function luhnCheckDigit(string $base): int
    {
        $sum = 0;
        $parity = (strlen($base) + 1) % 2;
        foreach (str_split($base) as $i => $char) {
            $digit = (int) $char;
            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }
        return (10 - ($sum % 10)) % 10;
    }

    private function validLuhn(string $number): bool
    {
        $sum = 0;
        $parity = strlen($number) % 2;
        foreach (str_split($number) as $i => $char) {
            $digit = (int) $char;
            if ($i % 2 === $parity) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }
            $sum += $digit;
        }
        return $sum % 10 === 0;
    }

    private function allowAttempt(int $userId, string $action, int $limit, int $minutes): bool
    {
        $minutes = max(1, min(1440, $minutes));
        $sql = 'INSERT INTO rate_limits (user_id, action_key, window_started, attempts) VALUES (?, ?, NOW(), 1) '
            . 'ON DUPLICATE KEY UPDATE attempts = IF(window_started < DATE_SUB(NOW(), INTERVAL ' . $minutes . ' MINUTE), 1, attempts + 1), '
            . 'window_started = IF(window_started < DATE_SUB(NOW(), INTERVAL ' . $minutes . ' MINUTE), NOW(), window_started)';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$userId, $action]);
        $stmt = $this->db->prepare('SELECT attempts FROM rate_limits WHERE user_id = ? AND action_key = ?');
        $stmt->execute([$userId, $action]);
        return (int) $stmt->fetchColumn() <= $limit;
    }

    private function allowUiClick(int $userId): bool
    {
        $stmt = $this->db->prepare('UPDATE users SET last_action_at = NOW(6) WHERE id = ? AND (last_action_at IS NULL OR last_action_at <= DATE_SUB(NOW(6), INTERVAL 1 SECOND))');
        $stmt->execute([$userId]);
        return $stmt->rowCount() === 1;
    }

    private function isReplyKeyboardButton(string $text): bool
    {
        return $this->matches($text, [
            '🩸 الان پریود شدم', '🩸 My period started',
            '🩸 الان پریودم تموم شد', '🩸 My period ended',
            '🔗 افزودن پارتنر', '🔗 Add partner',
            '🤝 با یارت به اشتراک بذار', '🤝 Share with your partner',
            '🔐 ساخت کد/لینک اتصال', '🔐 Create invite code/link',
            '🔑 کد یار را وارد کن', '🔑 Enter partner code',
            '⚙️ تنظیمات', '⚙️ Settings',
            '📊 وضعیت من', '📊 My status', '📊 وضعیت پارتنر', '📊 Partner status',
            '📚 تاریخچه ۵ چرخه اخیر', '📚 Last 5 cycles', '📚 تاریخچه پارتنر', '📚 Partner cycle history',
            '👤 درباره سازنده', '👤 About the Creator',
            '🎁 چرا رایگان است؟', '🎁 Why is it free?',
            '💌 دل‌نوشته‌ها', '💌 دل‌نوشته‌ها (ناشناس)', '💌 Notes', '💌 Notes (Anonymous)',
        ]);
    }

    private function upsertUser(array $from, int $chatId): array
    {
        $stmt = $this->db->prepare('INSERT INTO users (telegram_id, chat_id, first_name, username) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE chat_id = VALUES(chat_id), first_name = VALUES(first_name), username = VALUES(username)');
        $stmt->execute([(int) $from['id'], $chatId, mb_substr((string) ($from['first_name'] ?? ''), 0, 120), isset($from['username']) ? mb_substr((string) $from['username'], 0, 64) : null]);
        $stmt = $this->db->prepare('SELECT * FROM users WHERE telegram_id = ?');
        $stmt->execute([(int) $from['id']]);
        return $stmt->fetch();
    }

    private function updateUser(int|string $id, array $fields): void
    {
        $allowed = ['language', 'gender', 'state', 'note_return_state', 'cycle_length', 'bleed_length', 'ovulation_day', 'pms_days_before', 'last_period_date', 'period_active', 'last_period_end_date', 'pending_pair_code'];
        $set = [];
        $values = [];
        foreach ($fields as $field => $value) {
            if (!in_array($field, $allowed, true)) {
                throw new InvalidArgumentException('Invalid user field');
            }
            $set[] = $field . ' = ?';
            $values[] = $value;
        }
        if ($set === []) {
            return;
        }
        $values[] = $id;
        $stmt = $this->db->prepare('UPDATE users SET ' . implode(', ', $set) . ' WHERE id = ?');
        $stmt->execute($values);
    }

    private function getUserById(int $id): array
    {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new RuntimeException('User not found');
        }
        return $user;
    }

    private function matches(string $value, array $options): bool
    {
        return in_array($value, $options, true);
    }

    private function toEnglishDigits(string $value): string
    {
        return strtr($value, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9']);
    }

    private function toPersianDigits(string $value): string
    {
        return strtr($value, ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴', '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹']);
    }

    private function parseGregorianDate(string $value): ?DateTimeImmutable
    {
        $parts = $this->parseFlexibleDateParts($value, 1900, 2200);
        if ($parts === null) {
            return null;
        }
        [$year, $month, $day] = $parts;
        $date = DateTimeImmutable::createFromFormat('!Y-n-j', $year . '-' . $month . '-' . $day, $this->timezone);
        $errors = DateTimeImmutable::getLastErrors();
        $valid = $date !== false && ($errors === false || (($errors['warning_count'] ?? 0) === 0 && ($errors['error_count'] ?? 0) === 0));
        return $valid && $date->format('Y-n-j') === $year . '-' . $month . '-' . $day ? $date : null;
    }

    private function parseJalaliDate(string $value): ?DateTimeImmutable
    {
        $parts = $this->parseFlexibleDateParts($value, 1200, 1600);
        if ($parts === null) {
            return null;
        }
        [$jy, $jm, $jd] = $parts;
        if ($jm < 1 || $jm > 12 || $jd < 1 || $jd > 31) {
            return null;
        }
        [$gy, $gm, $gd] = $this->jalaliToGregorian($jy, $jm, $jd);
        [$checkYear, $checkMonth, $checkDay] = $this->gregorianToJalali($gy, $gm, $gd);
        if ($checkYear !== $jy || $checkMonth !== $jm || $checkDay !== $jd) {
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-n-j', $gy . '-' . $gm . '-' . $gd, $this->timezone);
        return $date === false ? null : $date;
    }

    private function parseFlexibleDateParts(string $value, int $minYear, int $maxYear): ?array
    {
        if (!preg_match('/^(\d{1,4})([\/-])(\d{1,2})\2(\d{1,4})$/', trim($value), $parts)) {
            return null;
        }
        $left = $parts[1];
        $month = (int) $parts[3];
        $right = $parts[4];
        if (strlen($left) === 4 && strlen($right) <= 2) {
            $year = (int) $left;
            $day = (int) $right;
        } elseif (strlen($right) === 4 && strlen($left) <= 2) {
            $year = (int) $right;
            $day = (int) $left;
        } else {
            return null;
        }
        if ($year < $minYear || $year > $maxYear || $month < 1 || $month > 12 || $day < 1 || $day > 31) {
            return null;
        }
        return [$year, $month, $day];
    }

    private function scheduleIsCurrent(DateTimeImmutable $start, DateTimeImmutable $today): bool
    {
        return $start >= $today->modify('-' . self::SCHEDULE_MAX_AGE_DAYS . ' days');
    }

    private function formatDateForUser(array $user, DateTimeImmutable $date): string
    {
        if (($user['language'] ?? 'fa') !== 'fa') {
            return $date->format('Y-m-d');
        }
        [$jy, $jm, $jd] = $this->gregorianToJalali((int) $date->format('Y'), (int) $date->format('n'), (int) $date->format('j'));
        return $this->toPersianDigits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd));
    }

    private function formatNumberForUser(array $user, int $number): string
    {
        $value = (string) $number;
        return ($user['language'] ?? 'fa') === 'fa' ? $this->toPersianDigits($value) : $value;
    }

    private function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $monthDays = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        if ($gy > 1600) {
            $jy = 979;
            $gy -= 1600;
        } else {
            $jy = 0;
            $gy -= 621;
        }
        $gy2 = $gm > 2 ? $gy + 1 : $gy;
        $days = 365 * $gy + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) - 80 + $gd + $monthDays[$gm - 1];
        $jy += 33 * intdiv($days, 12053);
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        if ($days < 186) {
            return [$jy, 1 + intdiv($days, 31), 1 + ($days % 31)];
        }
        return [$jy, 7 + intdiv($days - 186, 30), 1 + (($days - 186) % 30)];
    }

    private function jalaliToGregorian(int $jy, int $jm, int $jd): array
    {
        if ($jy > 979) {
            $gy = 1600;
            $jy -= 979;
        } else {
            $gy = 621;
        }
        $days = 365 * $jy + intdiv($jy, 33) * 8 + intdiv(($jy % 33) + 3, 4)
            + 78 + $jd + ($jm < 7 ? ($jm - 1) * 31 : ($jm - 7) * 30 + 186);
        $gy += 400 * intdiv($days, 146097);
        $days %= 146097;
        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }
        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;
        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }
        $gd = $days + 1;
        $leap = ($gy % 4 === 0 && $gy % 100 !== 0) || $gy % 400 === 0;
        $monthDays = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        for ($gm = 1; $gm <= 12 && $gd > $monthDays[$gm]; $gm++) {
            $gd -= $monthDays[$gm];
        }
        return [$gy, $gm, $gd];
    }

    private function t(array $user, string $key, array $vars = []): string
    {
        $lang = ($user['language'] ?? 'fa') === 'en' ? 'en' : 'fa';
        $messages = self::messages();
        $text = $messages[$lang][$key] ?? $messages['en'][$key] ?? $key;
        foreach ($vars as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }
        return $text;
    }

    private static function messages(): array
    {
        return [
            'fa' => [
                'choose_gender' => 'برای ادامه یکی را انتخاب کن. اطلاعات چرخه خصوصی است و فقط برای محاسبه اعلان‌های شخصی استفاده می‌شود.',
                'female' => '👩 خانم هستم', 'male' => '🤝 پارتنر هستم',
                'role_help' => 'هر زمان خواستی، دکمه‌های «درباره سازنده» و «چرا رایگان است؟» پایین صفحه در دسترس‌اند.',
                'male_ready' => 'ثبت شد. کد ۸ رقمی یک‌بارمصرفی را که خانم برایت می‌فرستد وارد کن.',
                'ask_cycle' => 'طول معمول چرخه‌ات چند روز است؟',
                'ask_bleed' => 'خون‌ریزی معمولاً چند روز طول می‌کشد؟',
                'ask_ovulation' => 'اگر می‌دانی، معمولاً روز چندم چرخه تخمک‌گذاری می‌کنی؟ (میل جنسی و احتمال بارداری بالا است)',
                'ask_pms' => 'معمولاً PMS چند روز پیش از پریود شروع می‌شود؟',
                'ask_last_period' => 'شروع آخرین پریودت چه زمانی بوده؟ این تاریخ مبنای تقویم شخصی تو می‌شود.',
                'started_today' => '🩸 امروز شروع شد', 'enter_start_date' => '📅 وارد کردن تاریخ', 'record_later' => 'بعداً با دکمه ثبت می‌کنم',
                'start_date_prompt' => 'تاریخ شروع را به شمسی بفرست؛ مثل ۱۴۰۵/۱۰/۲۰ یا ۲۰/۱۰/۱۴۰۵. عدد وسط ماه است و خط تیره هم پذیرفته می‌شود.',
                'invalid_start_date' => 'تاریخ معتبر نیست. تاریخ شمسی را مثل ۱۴۰۵/۱۰/۲۰ یا ۲۰/۱۰/۱۴۰۵ بفرست. تاریخ آینده پذیرفته نمی‌شود.',
                'custom' => 'عدد دیگر', 'unknown_28' => 'نمی‌دانم (۲۸)', 'unknown_5' => 'نمی‌دانم (۵)', 'unknown_14' => 'نمی‌دانم (۱۴)',
                'cycle_custom_prompt' => 'تعداد روزهای معمول چرخه‌ات را به‌صورت عدد بفرست.', 'bleed_custom_prompt' => 'عددی بین ۱ تا ۱۰ بفرست.',
                'ovulation_custom_prompt' => 'عددی بین ۶ تا ۳۰ بفرست.', 'pms_custom_prompt' => 'عددی بین ۱ تا ۱۴ بفرست.',
                'invalid_cycle' => 'تعداد روزهای چرخه را به‌صورت یک عدد صحیح مثبت وارد کن.', 'invalid_number' => 'عدد باید بین {min} و {max} باشد.',
                'setup_done' => 'تنظیمات ذخیره شد 🌷 هر بار خون‌ریزی شروع شد، دکمه «الان پریود شدم» را بزن.',
                'setup_done_active' => 'تاریخ شروع ثبت شد 🌷 اگر خون‌ریزی تمام شده، از دکمه «الان پریودم تموم شد» در منوی پایین استفاده کن.',
                'partner_optional' => 'می‌خواهی پارتنرت را هم اضافه کنی؟ این کار کاملاً اختیاری است.',
                'make_code' => '🔐 ساخت کد اتصال', 'share_partner' => '🤝 با یارت به اشتراک بذار', 'not_now' => 'فعلاً نه', 'enter_code' => '⌨️ وارد کردن کد',
                'enter_code_prompt' => 'کد ۸ رقمی‌ای را که یارت برایت فرستاده وارد کن.', 'partner_menu' => 'برای اتصال، یک کد/لینک برای خانم بساز یا کد ۸ رقمی او را وارد کن. لینک او را هم می‌توانی مستقیم باز کنی.',
                'lady_partner_menu' => 'می‌توانی برای یارت کد یک‌بارمصرف بسازی یا کدی را که او ساخته وارد کنی.',
                'invite_text' => "کد اتصال یک‌بارمصرف تو:\n<code>{code}</code>\n\nتا ۲۴ ساعت معتبر است و پس از استفاده منقضی می‌شود. می‌توانی این لینک را فقط برای پارتنرت بفرستی:\n{link}",
                'open_link' => '🔗 باز کردن لینک اتصال', 'connected' => '✅ اتصال امن با پارتنرت انجام شد.',
                'wrong_code' => 'کد اشتباه است. هر کد ۸ رقمی یک رقم کنترل دارد؛ دوباره بررسی کن.',
                'expired_code' => 'این کد منقضی شده است. از پارتنرت بخواه کد جدید بسازد.',
                'used_code' => 'این کد قبلاً استفاده یا باطل شده است.', 'role_mismatch' => 'این کد برای نقش‌های انتخاب‌شده قابل استفاده نیست. حساب پارتنر باید به حساب خانم وصل شود.',
                'self_code' => 'نمی‌توانی کد خودت را وارد کنی.', 'already_connected' => 'یکی از شما قبلاً به پارتنر متصل شده است.',
                'too_many_attempts' => 'تلاش‌های ناموفق زیاد بود. ۱۵ دقیقه بعد دوباره امتحان کن.',
                'welcome' => 'سلام! من «پریود یار رایگان» هستم؛ یک ابزار ساده برای آگاهی و یادآوری چرخه.',
                'keyboard_ready' => 'منوی همیشگی پایین صفحه آماده است.', 'period_started' => '🩸 ثبت شروع پریود',
                'status' => '📊 وضعیت', 'settings' => '⚙️ تنظیمات', 'creator_button' => '👤 سازنده', 'free_button' => '🎁 چرا رایگان؟',
                'cycle_settings' => '🗓 تنظیم چرخه', 'language_settings' => '🌐 تغییر زبان', 'disconnect_partner' => '🔌 من را از پارتنرم جدا کن', 'delete_profile' => '🗑 حذف مشخصات/شروع مجدد', 'back' => '↩️ بازگشت',
                'disconnect_warning' => '⚠️ فقط اتصال شما قطع می‌شود؛ هیچ‌یک از اطلاعات چرخه، تاریخچه اعلان‌ها یا دل‌نوشته‌ها حذف نخواهد شد. پس از قطع، دیگر وضعیت و اعلان‌های چرخه برای یکدیگر ارسال نمی‌شود. مطمئنی؟',
                'confirm_disconnect' => '🔌 بله، اتصال را قطع کن', 'cancel_disconnect' => 'نه، اتصال بماند',
                'disconnect_done' => 'اتصال با پارتنرت قطع شد. هیچ‌یک از اطلاعات و تاریخچه حسابت حذف نشد.',
                'partner_disconnected' => 'پارتنرت اتصالش را با تو قطع کرد. اطلاعات و تاریخچه حساب تو حذف نشده است.',
                'delete_profile_warning' => '⚠️ با تأیید این گزینه، همه مشخصات چرخه، تاریخچه اعلان‌ها، کدهای اتصال و ارتباط با پارتنر حذف می‌شود و ثبت‌نام از ابتدا آغاز خواهد شد. این کار قابل بازگشت نیست. مطمئنی؟',
                'confirm_delete' => '🗑 بله، حذف کن', 'cancel_delete' => 'نه، منصرف شدم',
                'profile_deleted' => 'مشخصاتت حذف شد و ثبت‌نام از ابتدا شروع می‌شود. اگر پارتنر داشتی، اتصال شما هم قطع شد.',
                'partner_profile_deleted' => 'پارتنرت مشخصاتش را حذف و ثبت‌نام را از ابتدا شروع کرد؛ اتصال شما قطع شد. برای اتصال دوباره باید کد جدیدی ساخته شود.',
                'female_only' => 'ثبت شروع پریود فقط در حساب زن فعال است.', 'period_rate_limit' => 'برای جلوگیری از ثبت اشتباه، فعلاً امکان ثبت دوباره نیست.',
                'already_recorded' => 'شروع پریود امروز قبلاً ثبت شده است.', 'period_already_active' => 'پریود فعال است؛ وقتی تمام شد دکمه «الان پریودم تموم شد» را بزن.',
                'period_woman' => '🩸 شروع چرخه جدید ثبت شد. امروز روز اول چرخه است.',
                'period_partner' => '🩸 شروع پریود پارتنرت ثبت شد.',
                'period_not_active' => 'در حال حاضر پریود فعالی ثبت نشده است.',
                'period_end_woman' => 'پایان پریود فعلی ثبت شد.', 'period_end_partner' => 'پایان پریود پارتنرت ثبت شد.',
                'period_due_woman' => '🗓 امروز موعد تقریبی شروع پریود است. اگر خون‌ریزی شروع شده، با دکمه پایین صفحه ثبتش کن.',
                'period_due_partner' => '🗓 امروز موعد تقریبی شروع پریود پارتنرت است.',
                'yes_record_period' => '🩸 بله، ثبتش کن', 'not_yet' => 'هنوز نه',
                'period_not_yet_ack' => 'باشه؛ فعلاً چیزی ثبت نشد. اگر شروع شد دکمه «الان پریود شدم» را بزن. در صورت تأخیر، حداکثر پنج روز روزی یک یادآوری کوتاه می‌فرستم.',
                'early_woman' => 'پریود این چرخه زودتر از تاریخ تخمینی شروع شد.',
                'early_partner' => 'پریود پارتنرت زودتر از تاریخ تخمینی شروع شد.',
                'late_arrived_woman' => 'پریود این چرخه دیرتر از تاریخ تخمینی شروع شد.',
                'late_arrived_partner' => 'پریود پارتنرت دیرتر از تاریخ تخمینی شروع شد.',
                'ovulation_woman' => '🌼 امروز تاریخ تقریبی تخمک‌گذاری است. در حوالی تخمک‌گذاری ممکن است میل جنسی افزایش پیدا کند و احتمال بارداری بیشتر است.',
                'ovulation_partner' => '🌼 امروز تاریخ تقریبی تخمک‌گذاری پارتنرت است. در حوالی تخمک‌گذاری ممکن است میل جنسی افزایش پیدا کند و احتمال بارداری بیشتر است.',
                'pms_woman' => 'امروز تاریخ تقریبی شروع PMS است.',
                'pms_partner' => 'امروز تاریخ تقریبی شروع PMS پارتنرت است.',
                'late_1_woman' => '⏳ پریود یک روز از تاریخ تخمینی عقب افتاده است.',
                'late_1_partner' => '⏳ پریود پارتنرت یک روز از تاریخ تخمینی عقب افتاده است.',
                'late_daily_woman' => '⏳ پریود {days} روز از تاریخ تخمینی عقب افتاده است.',
                'late_daily_partner' => '⏳ پریود پارتنرت {days} روز از تاریخ تخمینی عقب افتاده است.',
                'late_7_woman' => '⏳ پریود هفت روز از تاریخ تخمینی عقب افتاده است.',
                'late_7_partner' => '⏳ پریود پارتنرت هفت روز از تاریخ تخمینی عقب افتاده است.',
                'no_partner' => 'هنوز پارتنری متصل نیست.', 'no_period' => 'هنوز شروع پریود ثبت نشده است.',
                'status_late' => '⏳ {days} روز تأخیر', 'status_due_today' => '🗓 امروز', 'status_until_due' => '⏱ {days} روز',
                'status_text' => "تاریخ شروع آخرین پریود: {last}\nپریود بعدیِ تقریبی: {next}\nروزهای مانده تا پریود بعدی: {timing}\nچرخه: {cycle} روز | خون‌ریزی: {bleed} روز\nتاریخ تقریبی تخمک‌گذاری: {ovulation_date}\nتاریخ تقریبی شروع PMS: {pms_date}\n\nتاریخ‌ها شمسی و تخمینی‌اند.",
                'status_stale_text' => "تاریخ شروع آخرین پریود: {last}\nچرخه: {cycle} روز | خون‌ریزی: {bleed} روز\n\nبیش از ۶۰ روز از این تاریخ گذشته است؛ اعلان‌های PMS، تخمک‌گذاری و موعد پریود تا ثبت شروع بعدی فعال نمی‌شوند. ثبت واقعی شروع پریود همچنان به تو و پارتنرت اعلام می‌شود.",
                'history_title' => '📚 تاریخچه ۵ چرخه اخیر',
                'history_entry' => "{number}) شروع: {start}\nطول چرخه: {cycle}\nمدت خون‌ریزی: {bleed}",
                'history_days' => 'روز', 'history_unknown' => 'هنوز مشخص نیست',
                'history_in_progress' => 'در حال انجام',
                'history_empty' => 'هنوز تاریخ شروعی برای نمایش در تاریخچه ثبت نشده است.',
                'history_no_cycle' => 'این حساب چرخه‌ای برای نمایش ندارد.',
                'creator_text' => self::ABOUT_TEXT_FA,
                'free_text' => self::ABOUT_TEXT_FA,
                'notes_intro' => '💌 دل‌نوشته‌ها فضایی ناشناس برای نوشته‌های کوتاه کاربران است. نام، آیدی، شماره تماس و رسانه نمایش داده نمی‌شود.',
                'notes_write' => '✍️ ثبت دل‌نوشته', 'notes_recent' => '📖 ۱۰ دل‌نوشته آخر', 'notes_cancel' => 'انصراف',
                'notes_prompt' => 'متنت را در یک پیام بفرست؛ حداکثر ۳۰۰ نویسه. شماره تماس، آیدی، لینک، ایمیل و تصویر پذیرفته نمی‌شود.',
                'notes_text_only' => 'فقط پیام متنی پذیرفته می‌شود؛ تصویر، فایل، صدا و ویدئو مجاز نیست.',
                'notes_too_long' => 'متن باید حداکثر ۳۰۰ نویسه باشد.', 'notes_contact_blocked' => 'برای حفظ ناشناس‌بودن، شماره تماس، آیدی، لینک یا ایمیل قابل ثبت نیست.',
                'notes_rate_limited' => 'برای جلوگیری از سوءاستفاده، در هر ساعت حداکثر سه دل‌نوشته می‌توانی ثبت کنی.',
                'notes_saved' => '💌 دل‌نوشته‌ات بدون نام ثبت شد.', 'notes_empty' => 'هنوز دل‌نوشته‌ای ثبت نشده است.',
                'note_lady' => '👩💌 پیام خانم', 'note_partner' => '🤝💌 پیام پارتنر', 'note_text_label' => 'متن:',
            ],
            'en' => [
                'choose_gender' => 'Choose how you will use the bot. Cycle data stays private and is used only for personal reminders.',
                'female' => '👩 I’m a lady', 'male' => '🤝 I’m a partner',
                'role_help' => 'The “About the Creator” and “Why is it free?” buttons remain available below.',
                'male_ready' => 'Saved. Enter the secure one-time 8-digit code the lady shares with you.',
                'ask_cycle' => 'What is your usual cycle length?', 'ask_bleed' => 'How many days does bleeding usually last?',
                'ask_ovulation' => 'If known, on which cycle day do you usually ovulate? (Sex drive and the chance of pregnancy are higher)', 'ask_pms' => 'How many days before your period does PMS usually begin?',
                'ask_last_period' => 'When did your most recent period start? This date anchors your personal calendar.',
                'started_today' => '🩸 It started today', 'enter_start_date' => '📅 Enter the date', 'record_later' => 'I’ll record it later',
                'start_date_prompt' => 'Send the start date as YYYY-MM-DD or DD-MM-YYYY, for example 2026-09-20 or 20-09-2026. The middle number is always the month; slashes are also accepted.',
                'invalid_start_date' => 'That date is invalid. Use YYYY-MM-DD or DD-MM-YYYY. Future dates are not accepted.',
                'custom' => 'Other number', 'unknown_28' => 'Not sure (28)', 'unknown_5' => 'Not sure (5)', 'unknown_14' => 'Not sure (14)',
                'cycle_custom_prompt' => 'Send your usual cycle length as a number of days.', 'bleed_custom_prompt' => 'Send a number from 1 to 10.',
                'ovulation_custom_prompt' => 'Send a number from 6 to 30.', 'pms_custom_prompt' => 'Send a number from 1 to 14.',
                'invalid_cycle' => 'Enter the cycle length as a positive whole number.', 'invalid_number' => 'The number must be between {min} and {max}.',
                'setup_done' => 'Settings saved 🌷 Tap “My period started” whenever bleeding begins.',
                'setup_done_active' => 'Start date saved 🌷 If bleeding has ended, use “My period ended” in the keyboard below.',
                'partner_optional' => 'Would you like to add your partner? This is completely optional.',
                'make_code' => '🔐 Create connection code', 'share_partner' => '🤝 Share with your partner', 'not_now' => 'Not now', 'enter_code' => '⌨️ Enter a code',
                'enter_code_prompt' => 'Enter the 8-digit code your partner shared with you.', 'partner_menu' => 'Create a code/link for the lady, enter her 8-digit code, or open the link she shared with you.',
                'lady_partner_menu' => 'Create a one-time code for your partner, or enter the code your partner created.',
                'invite_text' => "Your one-time connection code:\n<code>{code}</code>\n\nIt expires in 24 hours and becomes invalid after use. Share this link only with your partner:\n{link}",
                'open_link' => '🔗 Open connection link', 'connected' => '✅ You are securely connected to your partner.',
                'wrong_code' => 'That code is incorrect. Each 8-digit code contains a check digit; please check it and try again.',
                'expired_code' => 'This code has expired. Ask your partner to create a new one.', 'used_code' => 'This code has already been used or revoked.',
                'role_mismatch' => 'This code cannot connect the selected roles. A partner account must connect to a lady account.', 'self_code' => 'You cannot use your own code.',
                'already_connected' => 'One of these accounts is already connected to a partner.', 'too_many_attempts' => 'Too many failed attempts. Try again in 15 minutes.',
                'welcome' => 'Hi! I am Free Period Share, a simple cycle-awareness and reminder tool.',
                'keyboard_ready' => 'Your permanent menu is ready below.', 'period_started' => '🩸 Record period start',
                'status' => '📊 Status', 'settings' => '⚙️ Settings', 'creator_button' => '👤 Creator', 'free_button' => '🎁 Why free?',
                'cycle_settings' => '🗓 Cycle settings', 'language_settings' => '🌐 Change language', 'disconnect_partner' => '🔌 Disconnect from my partner', 'delete_profile' => '🗑 Delete profile/Start over', 'back' => '↩️ Back',
                'disconnect_warning' => '⚠️ Only the partner connection will be removed. Cycle details, notification history, and notes will not be deleted. After disconnecting, you will no longer receive each other’s cycle status or alerts. Are you sure?',
                'confirm_disconnect' => '🔌 Yes, disconnect', 'cancel_disconnect' => 'No, stay connected',
                'disconnect_done' => 'You are now disconnected from your partner. None of your account information or history was deleted.',
                'partner_disconnected' => 'Your partner disconnected from you. Your account information and history were not deleted.',
                'delete_profile_warning' => '⚠️ Confirming will permanently delete all cycle details, notification history, connection codes, and the partner link. Registration will restart from the beginning. This cannot be undone. Are you sure?',
                'confirm_delete' => '🗑 Yes, delete it', 'cancel_delete' => 'No, cancel',
                'profile_deleted' => 'Your profile was deleted and registration will restart. If you had a partner, that connection was also removed.',
                'partner_profile_deleted' => 'Your partner deleted their profile and restarted registration. Your connection was removed; a new code is required to reconnect.',
                'female_only' => 'Period start can only be recorded from the female account.', 'period_rate_limit' => 'To prevent accidental duplicates, another entry is temporarily blocked.',
                'already_recorded' => 'Today’s period start has already been recorded.', 'period_already_active' => 'A period is currently active. Tap “My period ended” when it finishes.',
                'period_woman' => '🩸 A new cycle was recorded. Today is cycle day 1.',
                'period_partner' => '🩸 Your partner’s period start was recorded.',
                'period_not_active' => 'No active period is currently recorded.',
                'period_end_woman' => 'The end of your current period was recorded.', 'period_end_partner' => 'Your partner recorded the end of their period.',
                'period_due_woman' => '🗓 Today is the estimated period start date. If bleeding has started, record it with the button below.',
                'period_due_partner' => '🗓 Today is your partner’s estimated period start date.',
                'yes_record_period' => '🩸 Yes, record it', 'not_yet' => 'Not yet',
                'period_not_yet_ack' => 'Okay—nothing was recorded. Tap “My period started” when it begins. If it is late, I’ll send one brief reminder per day for up to five days.',
                'early_woman' => 'This period started earlier than the estimated date.',
                'early_partner' => 'Your partner’s period started earlier than the estimated date.',
                'late_arrived_woman' => 'This period started later than the estimated date.',
                'late_arrived_partner' => 'Your partner’s period started later than the estimated date.',
                'ovulation_woman' => '🌼 Today is the estimated ovulation date. Around ovulation, sex drive may increase and the chance of pregnancy is higher.',
                'ovulation_partner' => '🌼 Today is your partner’s estimated ovulation date. Around ovulation, sex drive may increase and the chance of pregnancy is higher.',
                'pms_woman' => 'Today is the estimated PMS start date.',
                'pms_partner' => 'Today is your partner’s estimated PMS start date.',
                'late_1_woman' => '⏳ Your period is 1 day past the estimated date.',
                'late_1_partner' => '⏳ Your partner’s period is 1 day past the estimated date.',
                'late_daily_woman' => '⏳ Your period is {days} days past the estimated date.',
                'late_daily_partner' => '⏳ Your partner’s period is {days} days past the estimated date.',
                'late_7_woman' => '⏳ Your period is 7 days past the estimated date.',
                'late_7_partner' => '⏳ Your partner’s period is 7 days past the estimated date.',
                'no_partner' => 'No partner is connected yet.', 'no_period' => 'No period start has been recorded yet.',
                'status_late' => '⏳ {days} day(s) late', 'status_due_today' => '🗓 Today', 'status_until_due' => '⏱ {days} day(s)',
                'status_text' => "Last period start date: {last}\nEstimated next period: {next}\nDays until next period: {timing}\nCycle: {cycle} days | Bleeding: {bleed} days\nEstimated ovulation date: {ovulation_date}\nEstimated PMS start: {pms_date}\n\nThese dates are estimates.",
                'status_stale_text' => "Last period start date: {last}\nCycle: {cycle} days | Bleeding: {bleed} days\n\nMore than 60 days have passed since this date, so PMS, ovulation, and due-date notifications stay off until the next period start is recorded. A real period-start entry is still announced to you and your partner.",
                'history_title' => '📚 Last 5 cycles',
                'history_entry' => "{number}) Started: {start}\nCycle length: {cycle}\nBleeding: {bleed}",
                'history_days' => 'days', 'history_unknown' => 'Not known yet',
                'history_in_progress' => 'In progress',
                'history_empty' => 'No period start has been recorded for the history yet.',
                'history_no_cycle' => 'This account has no cycle history to show.',
                'creator_text' => self::ABOUT_TEXT_EN,
                'free_text' => self::ABOUT_TEXT_EN,
                'notes_intro' => '💌 Notes is an anonymous space for short user messages. Names, usernames, phone numbers, and media are not shown.',
                'notes_write' => '✍️ Write a note', 'notes_recent' => '📖 Last 10 notes', 'notes_cancel' => 'Cancel',
                'notes_prompt' => 'Send your text in one message, up to 300 characters. Phone numbers, usernames, links, email addresses, and images are not allowed.',
                'notes_text_only' => 'Only text is accepted. Images, files, audio, and video are not allowed.',
                'notes_too_long' => 'The note must be 300 characters or fewer.', 'notes_contact_blocked' => 'To keep notes anonymous, phone numbers, usernames, links, and email addresses are blocked.',
                'notes_rate_limited' => 'To prevent abuse, you can submit up to three notes per hour.',
                'notes_saved' => '💌 Your note was saved anonymously.', 'notes_empty' => 'No notes have been posted yet.',
                'note_lady' => '👩💌 Lady message', 'note_partner' => '🤝💌 Partner message', 'note_text_label' => 'Text:',
            ],
        ];
    }
}
