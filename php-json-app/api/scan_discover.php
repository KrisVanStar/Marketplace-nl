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

$jobId = uuidv4();
create_job($jobId, $city, $category);

try {
    $candidates = discover_businesses($city, $category, $limit, $onlyWithoutWebsite);
} catch (Throwable $e) {
    update_job($jobId, ['status' => 'error', 'message' => $e->getMessage()]);
    json_response(get_job($jobId));
}

if (empty($candidates)) {
    $hint = $category === 'all'
        ? 'Probeer een grotere plaats, of controleer de spelling van de plaatsnaam.'
        : 'Deze categorie is in OpenStreetMap vaak dun gevuld. Probeer de categorie '
          . '"Alle bedrijven" of een grotere plaats.';
    update_job($jobId, [
        'status' => 'done',
        'message' => "Geen bedrijven gevonden in {$city}. {$hint}",
    ]);
    json_response(get_job($jobId));
}

$withoutWebsite = 0;
foreach ($candidates as $c) {
    if (empty($c['website'])) {
        $withoutWebsite++;
    }
    $businessId = get_or_create_business($c);
    enqueue_business($jobId, $businessId);
}

update_job($jobId, [
    'total' => count($candidates),
    'status' => 'running',
    'message' => count($candidates) . ' bedrijven gevonden'
        . ($withoutWebsite > 0 ? " (waarvan {$withoutWebsite} zonder website)" : '')
        . ', websites worden geanalyseerd...',
]);

// Process a first small batch synchronously so the user sees quick results;
// the rest is picked up gradually by cron_worker.php (see README).
process_queue_batch($jobId, 5);

json_response(get_job($jobId));
