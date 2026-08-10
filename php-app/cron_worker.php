<?php
declare(strict_types=1);

/**
 * Processes a batch of queued website scans. Shared hosting has no
 * persistent background workers, so a scan job's remaining work is picked
 * up gradually by this script, run on a schedule (see README.md for how
 * to set up the cron job on TransIP).
 *
 * Safe to run as a CLI cron command (`php cron_worker.php`) or, if your
 * host only offers URL-based cron, as a URL fetch with the configured
 * secret token: https://yourdomain.nl/cron_worker.php?token=...
 */
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/analyzer.php';
require_once __DIR__ . '/includes/scoring.php';
require_once __DIR__ . '/includes/tasks.php';

$isCli = PHP_SAPI === 'cli';

if (!$isCli) {
    $expected = app_config()['cron_token'] ?? '';
    $given = $_GET['token'] ?? '';
    if ($expected === '' || $expected === 'CHANGE_ME_TO_A_RANDOM_STRING' || !hash_equals($expected, (string) $given)) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo "Forbidden: set a real cron_token in config.php and pass it as ?token=...\n";
        exit;
    }
}

$batchSize = 10;
$processed = process_queue_batch(db(), null, $batchSize);

$message = "Processed {$processed} queued scan(s).\n";
if ($isCli) {
    echo $message;
} else {
    header('Content-Type: text/plain');
    echo $message;
}
