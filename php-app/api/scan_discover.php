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
$onlyWithoutWebsite = !empty($payload['only_without_website']);

if ($city === '') {
    json_error('Vul een plaatsnaam in.');
}
$cats = categories();
if (!isset($cats[$category])) {
    json_error("Onbekende categorie '{$category}'.");
}

$pdo = db();
$jobId = uuidv4();
$pdo->prepare('INSERT INTO scan_jobs (id, status, city, category, total) VALUES (?, ?, ?, ?, 0)')
    ->execute([$jobId, 'pending', $city, $category]);

function job_row(PDO $pdo, string $jobId): array
{
    $stmt = $pdo->prepare('SELECT * FROM scan_jobs WHERE id = ?');
    $stmt->execute([$jobId]);
    return $stmt->fetch();
}

try {
    $candidates = discover_businesses($city, $category, $limit, $onlyWithoutWebsite);
} catch (Throwable $e) {
    $pdo->prepare("UPDATE scan_jobs SET status = 'error', message = ? WHERE id = ?")
        ->execute([mb_substr($e->getMessage(), 0, 500), $jobId]);
    json_response(job_row($pdo, $jobId));
}

if (empty($candidates)) {
    $hint = $category === 'all'
        ? 'Probeer een grotere plaats, of controleer de spelling van de plaatsnaam.'
        : 'Deze categorie is in OpenStreetMap vaak dun gevuld. Probeer de categorie "Alle bedrijven" of een grotere plaats.';
    $pdo->prepare("UPDATE scan_jobs SET status = 'done', message = ? WHERE id = ?")
        ->execute(["Geen bedrijven gevonden in {$city}. {$hint}", $jobId]);
    json_response(job_row($pdo, $jobId));
}

$withoutWebsite = 0;
foreach ($candidates as $c) {
    if (empty($c['website'])) {
        $withoutWebsite++;
    }
    $businessId = get_or_create_business($pdo, $c);
    $pdo->prepare('INSERT INTO scan_queue (job_id, business_id) VALUES (?, ?)')->execute([$jobId, $businessId]);
}

$message = count($candidates) . ' bedrijven gevonden'
    . ($withoutWebsite > 0 ? " (waarvan {$withoutWebsite} zonder website)" : '')
    . ', websites worden geanalyseerd...';
$pdo->prepare("UPDATE scan_jobs SET total = ?, status = 'running', message = ? WHERE id = ?")
    ->execute([count($candidates), $message, $jobId]);

process_queue_batch($pdo, $jobId, 5);

json_response(job_row($pdo, $jobId));
