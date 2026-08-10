<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Amsterdam');
mb_internal_encoding('UTF-8');

define('APP_ROOT', dirname(__DIR__));

/** Stops the request with a readable JSON error instead of a PHP fatal. */
function fail_setup(string $message): never
{
    http_response_code(500);
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function app_config(): array
{
    static $config = null;
    if ($config === null) {
        $path = APP_ROOT . '/config.php';
        if (!is_file($path)) {
            fail_setup(
                'config.php ontbreekt. Kopieer config.sample.php naar config.php op de server '
                . 'en vul je gegevens in.'
            );
        }

        $loaded = require $path;

        // `require` yields int(1) when the file has no `return` statement —
        // most often because the closing `];` or the opening `<?php` got lost
        // while editing or uploading. Say that, instead of dying on a TypeError.
        if (!is_array($loaded)) {
            fail_setup(
                'config.php is niet geldig: het bestand geeft geen instellingen terug '
                . '(PHP kreeg ' . gettype($loaded) . ' in plaats van een lijst). '
                . 'Controleer of het bestand begint met <?php op de allereerste regel, '
                . 'of er niets vóór staat (geen spatie of lege regel), en of het de regel '
                . 'return [ ... ]; bevat die met ]; wordt afgesloten. '
                . 'Het eenvoudigst is opnieuw beginnen: kopieer config.sample.php naar '
                . 'config.php en pas alleen de waarden tussen de quotes aan. '
                . 'Open check.php in je browser voor een volledige controle van de installatie.'
            );
        }

        $config = $loaded;
    }
    return $config;
}

function crawler_user_agent(): string
{
    $email = app_config()['crawler_contact_email'] ?? 'you@example.com';
    return "MarketplaceNLLeadScanner/1.0 (+contact: {$email}; purpose: identifying small NL businesses whose website may benefit from a redesign; respects robots.txt; low request rate)";
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/jsondb.php';
