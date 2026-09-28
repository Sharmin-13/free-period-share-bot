<?php
declare(strict_types=1);

$configuredRoot = getenv('PERIODBOT_APP_ROOT');
$appRoot = is_string($configuredRoot) && $configuredRoot !== '' ? $configuredRoot : dirname(__DIR__);
$configPath = $appRoot . '/config.php';
if (!is_file($configPath)) {
    http_response_code(503);
    exit('Not configured');
}
$config = require $configPath;
date_default_timezone_set($config['timezone'] ?? 'Asia/Tehran');

require $appRoot . '/src/Database.php';
require $appRoot . '/src/Telegram.php';
require $appRoot . '/src/Bot.php';

$db = Database::connect($config['db']);
$telegram = new Telegram($config['telegram']['token']);
$bot = new Bot(
    $db,
    $telegram,
    $config['telegram']['username'],
    $config['app_secret'],
    new DateTimeZone($config['timezone'] ?? 'Asia/Tehran')
);
