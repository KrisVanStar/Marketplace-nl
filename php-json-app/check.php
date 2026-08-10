<?php
declare(strict_types=1);

/**
 * Installatiecontrole. Open dit bestand in je browser (bijvoorbeeld
 * https://jouwdomein.nl/check.php) om te zien of alles klopt: PHP-versie,
 * benodigde extensies, config.php, schrijfrechten en de verbinding met
 * OpenStreetMap.
 *
 * Deze pagina leest config.php bewust NIET via de gewone bootstrap, zodat
 * hij ook werkt (en uitleg geeft) als config.php juist het probleem is.
 * Er worden geen wachtwoorden of tokens getoond.
 */

$appRoot = __DIR__;
$checks = [];

function check(string $name, bool $ok, string $detail, string $fix = ''): array
{
    return ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'fix' => $fix];
}

// --- PHP ------------------------------------------------------------------
$checks[] = check(
    'PHP-versie',
    PHP_VERSION_ID >= 80100,
    'PHP ' . PHP_VERSION,
    'Deze app vereist PHP 8.1 of hoger. Stel een nieuwere PHP-versie in via je hostingpaneel.'
);

foreach (['curl' => 'websites ophalen', 'dom' => 'HTML analyseren', 'mbstring' => 'tekst verwerken', 'json' => 'gegevens opslaan'] as $ext => $why) {
    $checks[] = check(
        "PHP-extensie: {$ext}",
        extension_loaded($ext),
        extension_loaded($ext) ? 'aanwezig' : 'ONTBREEKT',
        "De extensie {$ext} is nodig om {$why}. Schakel hem in via je hostingpaneel."
    );
}

// --- config.php -----------------------------------------------------------
$configPath = $appRoot . '/config.php';
$config = null;

if (!is_file($configPath)) {
    $checks[] = check('config.php', false, 'niet gevonden',
        'Kopieer config.sample.php naar config.php op de server en vul je gegevens in.');
} else {
    $raw = (string) file_get_contents($configPath);
    $startsCorrectly = str_starts_with(ltrim($raw), '<?php');
    $checks[] = check(
        'config.php begint met <?php',
        $startsCorrectly,
        $startsCorrectly ? 'ja' : 'NEE — het bestand begint met iets anders',
        'De allereerste tekens van het bestand moeten <?php zijn, zonder spatie of lege regel ervoor. '
        . 'Sommige FTP-programma\'s en teksteditors slopen dit. Upload het bestand opnieuw als "tekst"/ASCII.'
    );

    $loaded = @include $configPath;
    if (is_array($loaded)) {
        $config = $loaded;
        $checks[] = check('config.php geeft instellingen terug', true, 'ja, ' . count($loaded) . ' instellingen gevonden');
    } else {
        $checks[] = check(
            'config.php geeft instellingen terug',
            false,
            'NEE — PHP kreeg ' . gettype($loaded) . ' terug in plaats van een lijst',
            'Het bestand mist de regel "return [" ... "];" of die is niet correct afgesloten met ];  '
            . 'Begin het makkelijkst opnieuw: kopieer config.sample.php naar config.php en pas alleen '
            . 'de waarden tussen de aanhalingstekens aan.'
        );
    }
}

if (is_array($config)) {
    $email = trim((string) ($config['crawler_contact_email'] ?? ''));
    $checks[] = check(
        'Contact-e-mailadres ingevuld',
        $email !== '' && $email !== 'you@example.com' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false,
        $email !== '' ? $email : 'leeg',
        'Vul bij crawler_contact_email je eigen e-mailadres in. Dat wordt meegestuurd naar de '
        . 'websites die je analyseert, zodat eigenaren kunnen zien wie hun site bezoekt.'
    );

    $token = (string) ($config['cron_token'] ?? '');
    $tokenOk = $token !== '' && $token !== 'CHANGE_ME_TO_A_RANDOM_STRING' && strlen($token) >= 16;
    $checks[] = check(
        'Cron-token ingesteld',
        $tokenOk,
        $tokenOk ? 'ja (' . strlen($token) . ' tekens)' : 'niet of te kort ingesteld',
        'Zet bij cron_token een lange willekeurige tekst. Nodig als je de cron-taak via een URL draait.'
    );
}

// --- Opslag ---------------------------------------------------------------
$dataDir = $appRoot . '/data';
$dbFile = $dataDir . '/db.json';

if (!is_dir($dataDir)) {
    $created = @mkdir($dataDir, 0775, true);
    $checks[] = check('Map data/ aanwezig', $created, $created ? 'aangemaakt' : 'ontbreekt en kan niet worden aangemaakt',
        'Maak via FTP een map "data" aan naast index.html en geef die schrijfrechten (755, anders 775).');
} else {
    $checks[] = check('Map data/ aanwezig', true, 'ja');
}

if (is_dir($dataDir)) {
    $probe = $dataDir . '/.write-test';
    $writable = @file_put_contents($probe, 'test') !== false;
    if ($writable) {
        @unlink($probe);
    }
    $checks[] = check('Map data/ is beschrijfbaar', $writable, $writable ? 'ja' : 'NEE',
        'PHP mag niet schrijven in de map data/. Zet de rechten via FTP op 755, en werkt dat niet, op 775.');

    if (is_file($dbFile)) {
        $size = round(filesize($dbFile) / 1024, 1);
        $data = json_decode((string) file_get_contents($dbFile), true);
        $checks[] = check('Database-bestand data/db.json', is_array($data),
            is_array($data)
                ? count($data['businesses'] ?? []) . ' bedrijven, ' . count($data['scans'] ?? []) . ' analyses, ' . $size . ' kB'
                : 'bestaat, maar is onleesbaar',
            'Als het bestand beschadigd is: hernoem data/db.json en start een nieuwe zoekopdracht.');
    } else {
        $checks[] = check('Database-bestand data/db.json', true, 'nog niet aangemaakt (normaal vóór je eerste zoekopdracht)');
    }
}

// --- Bescherming ----------------------------------------------------------
$checks[] = check('.htaccess aanwezig', is_file($appRoot . '/.htaccess'), is_file($appRoot . '/.htaccess') ? 'ja' : 'ontbreekt',
    'Zonder .htaccess kan data/db.json mogelijk publiek opvraagbaar zijn. Upload het bestand opnieuw '
    . '(let op: bestanden die met een punt beginnen zijn in FTP-programma\'s vaak verborgen).');

// --- Internetverbinding ---------------------------------------------------
function probe(string $url, int $timeout = 12): array
{
    if (!extension_loaded('curl')) {
        return [false, 'curl-extensie ontbreekt'];
    }
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERAGENT => 'MarketplaceNLLeadScanner/1.0 (installatiecontrole)',
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        return [false, $err ?: 'geen verbinding'];
    }
    return [$status === 200, "HTTP {$status}"];
}

$nominatim = $config['nominatim_url'] ?? 'https://nominatim.openstreetmap.org/search';
[$nomOk, $nomDetail] = probe($nominatim . '?q=Delft,Netherlands&format=json&limit=1&countrycodes=nl');
$checks[] = check('Verbinding met OpenStreetMap (plaatsen zoeken)', $nomOk, $nomDetail,
    'Je hosting kan de OpenStreetMap-server niet bereiken. Sommige hostingpakketten blokkeren '
    . 'uitgaand verkeer; vraag je hostingpartij of uitgaande HTTPS-verbindingen zijn toegestaan.');

$overpass = ($config['overpass_endpoints'][0] ?? 'https://overpass-api.de/api/interpreter');
[$ovOk, $ovDetail] = probe(rtrim($overpass, '/') . '?data=' . rawurlencode('[out:json];node(1);out;'));
$checks[] = check('Verbinding met OpenStreetMap (bedrijven zoeken)', $ovOk, $ovDetail,
    'De Overpass-server is niet bereikbaar. Dit is vaak tijdelijk — de app probeert automatisch '
    . 'meerdere servers. Blijft het misgaan, controleer dan of je hosting uitgaand verkeer toestaat.');

$failed = array_filter($checks, fn($c) => !$c['ok']);
$allOk = count($failed) === 0;
?>
<!doctype html>
<html lang="nl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Installatiecontrole — Marketplace-NL Lead Scanner</title>
<link rel="stylesheet" href="style.css">
<style>
  .check { border:1px solid var(--border); border-left:3px solid var(--low); border-radius:6px;
           padding:.7rem .9rem; margin-bottom:.5rem; background:#0f1720; }
  .check.fail { border-left-color: var(--high); }
  .check-head { display:flex; gap:.6rem; align-items:baseline; flex-wrap:wrap; }
  .check-mark { font-weight:700; }
  .check.pass .check-mark { color: var(--low); }
  .check.fail .check-mark { color: var(--high); }
  .check-detail { color: var(--muted); font-size:.85rem; }
  .check-fix { margin:.5rem 0 0; font-size:.86rem; background:rgba(255,107,53,.08);
               border-radius:4px; padding:.45rem .6rem; }
</style>
</head>
<body>
<header>
  <h1>Marketplace-NL <span>Installatiecontrole</span></h1>
  <p class="subtitle">Controleert of alles klaarstaat om te kunnen zoeken en analyseren</p>
</header>
<main>
  <div class="callout <?= $allOk ? '' : 'callout-critical' ?>">
    <?= $allOk
      ? 'Alles in orde. Je kunt aan de slag via <a href="index.html">de hoofdpagina</a>.'
      : 'Er ' . (count($failed) === 1 ? 'is 1 punt' : 'zijn ' . count($failed) . ' punten') . ' die aandacht nodig hebben. Hieronder staat per punt wat je moet doen.' ?>
  </div>

  <section class="panel">
    <?php foreach ($checks as $c): ?>
      <div class="check <?= $c['ok'] ? 'pass' : 'fail' ?>">
        <div class="check-head">
          <span class="check-mark"><?= $c['ok'] ? '✓' : '✗' ?></span>
          <strong><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></strong>
          <span class="check-detail"><?= htmlspecialchars($c['detail'], ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <?php if (!$c['ok'] && $c['fix'] !== ''): ?>
          <p class="check-fix"><?= htmlspecialchars($c['fix'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </section>

  <p class="hint">
    Verwijder dit bestand van de server zodra alles werkt — het toont geen wachtwoorden,
    maar hoeft ook niet openbaar te staan.
  </p>
</main>
</body>
</html>
