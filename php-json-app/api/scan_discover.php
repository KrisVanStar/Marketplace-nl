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

$jobId = uuidv4();
create_job($jobId, $city, $category);

try {
    $candidates = discover_businesses($city, $category, $limit);
} catch (Throwable $e) {
    update_job($jobId, ['status' => 'error', 'message' => 'Discovery failed: ' . $e->getMessage()]);
    json_response(get_job($jobId));
}

update_job($jobId, [
    'total' => count($candidates),
    'status' => 'running',
    'message' => count($candidates) . ' bedrijven gevonden, websites worden gescand...',
]);

if (empty($candidates)) {
    update_job($jobId, ['status' => 'done', 'message' => 'Geen bedrijven met website gevonden voor deze zoekopdracht.']);
    json_response(get_job($jobId));
}

foreach ($candidates as $c) {
    $businessId = get_or_create_business($c);
    enqueue_business($jobId, $businessId);
}

// Process a first small batch synchronously so the user sees quick results;
// the rest is picked up gradually by cron_worker.php (see README).
process_queue_batch($jobId, 5);

json_response(get_job($jobId));
