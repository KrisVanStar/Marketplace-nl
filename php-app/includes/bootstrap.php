<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');
mb_internal_encoding('UTF-8');

define('APP_ROOT', dirname(__DIR__));

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = APP_ROOT . '/config.php';
        if (!is_file($path)) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'error' => 'config.php ontbreekt. Kopieer config.sample.php naar config.php en vul je gegevens in.',
            ]);
            exit;
        }
        $config = require $path;
    }
    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = app_config();
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $c['db_host'], $c['db_name']);
        $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $pdo;
}

function crawler_user_agent(): string
{
    $email = app_config()['crawler_contact_email'] ?? 'you@example.com';
    return "MarketplaceNLLeadScanner/1.0 (+contact: {$email}; purpose: identifying small NL businesses whose website may benefit from a redesign; respects robots.txt; low request rate)";
}

require_once __DIR__ . '/helpers.php';
