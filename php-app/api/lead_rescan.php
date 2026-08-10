<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/analyzer.php';
require_once __DIR__ . '/../includes/scoring.php';
require_once __DIR__ . '/../includes/tasks.php';
require_once __DIR__ . '/../includes/leads.php';

require_method('POST');

$payload = read_json_body();
$id = clamp_int($payload['id'] ?? null, 1, PHP_INT_MAX, 0);
if ($id === 0) {
    json_error('id is required');
}

$pdo = db();
$business = find_business($pdo, $id);
if (!$business) {
    json_error('Lead not found', 404);
}

scan_and_store($pdo, $id, $business);

$scan = latest_scan_for($pdo, $id);
json_response(lead_row_to_array(array_merge($business, [
    'latest_score' => $scan['score'] ?? null,
    'latest_priority' => $scan['priority'] ?? null,
    'latest_summary' => $scan['summary'] ?? null,
    'latest_reasons_json' => $scan['reasons_json'] ?? null,
    'scanned_at' => $scan['scanned_at'] ?? null,
])));
