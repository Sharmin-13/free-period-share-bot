<?php
declare(strict_types=1);

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'YOUR_BOT_DATABASE',
        'user' => 'YOUR_BOT_DATABASE_USER',
        'pass' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'telegram' => [
        'token' => 'CHANGE_ME',
        'username' => 'CHANGE_ME_WITHOUT_AT',
        'webhook_url' => 'https://YOUR_DOMAIN.example/period-share-bot/webhook.php',
        'webhook_secret' => 'CHANGE_ME',
        'admin_chat_id' => null,
    ],
    'app_secret' => 'CHANGE_ME',
    'setup_secret' => 'CHANGE_ME',
    'cron_secret' => 'CHANGE_ME',
    'timezone' => 'Asia/Tehran',
];
