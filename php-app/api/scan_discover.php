<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/discovery.php';
require_once __DIR__ . '/../includes/analyzer.php';
require_once __DIR__ . '/../includes/scoring.php';
require_once __DIR__ . '/../includes/tasks.php';

require_method('POST');

$payload = read_json_body();
$city = trim((string) ($payload['city'] ?? ''));
$category = trim((string) ($payload['category'] ?? ''));
$limit = clamp_int($payload['limit'] ?? 20, 1, 50, 20);

if ($city === '') {
    json_error('City is required');
}
$cats = categories();
if (!isset($cats[$category])) {
    json_error("Unknown category '{$category}'. See /api/categories.php.");
}

$pdo = db();
$jobId = uuidv4();
$pdo->prepare('INSERT INTO scan_jobs (id, status, city, category, total) VALUES (?, ?, ?, ?, 0)')
    ->execute([$jobId, 'pending', $city, $category]);

try {
    $candidates = discover_businesses($city, $category, $limit);
} catch (Throwable $e) {
    $pdo->prepare("UPDATE scan_jobs SET status = 'error', message = ? WHERE id = ?")
        ->execute(['Discovery failed: ' . $e->getMessage(), $jobId]);
    json_response(job_row($pdo, $jobId));
}

$pdo->prepare("UPDATE scan_jobs SET total = ?, status = 'running', message = ? WHERE id = ?")
    ->execute([count($candidates), count($candidates) . ' bedrijven gevonden, websites worden gescand...', $jobId]);

if (empty($candidates)) {
    $pdo->prepare("UPDATE scan_jobs SET status = 'done', message = ? WHERE id = ?")
        ->execute(['Geen bedrijven met website gevonden voor deze zoekopdracht.', $jobId]);
    json_response(job_row($pdo, $jobId));
}

foreach ($candidates as $c) {
    $businessId = get_or_create_business($pdo, $c);
    $pdo->prepare('INSERT INTO scan_queue (job_id, business_id) VALUES (?, ?)')->execute([$jobId, $businessId]);
}

// Process a first small batch synchronously so the user sees quick results;
// the rest is picked up gradually by cron_worker.php (see README).
process_queue_batch($pdo, $jobId, 5);

json_response(job_row($pdo, $jobId));

function job_row(PDO $pdo, string $jobId): array
{
    $stmt = $pdo->prepare('SELECT * FROM scan_jobs WHERE id = ?');
    $stmt->execute([$jobId]);
    return $stmt->fetch();
}
