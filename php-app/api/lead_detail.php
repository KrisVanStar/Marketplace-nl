<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/leads.php';
require_once __DIR__ . '/../includes/tasks.php';

require_method('GET');

$id = clamp_int($_GET['id'] ?? null, 1, PHP_INT_MAX, 0);
if ($id === 0) {
    json_error('id is required');
}

$pdo = db();
$business = find_business($pdo, $id);
if (!$business) {
    json_error('Lead not found', 404);
}

$scan = latest_scan_for($pdo, $id);
$out = lead_row_to_array(array_merge($business, [
    'latest_score' => $scan['score'] ?? null,
    'latest_priority' => $scan['priority'] ?? null,
    'latest_summary' => $scan['summary'] ?? null,
    'latest_reasons_json' => $scan['reasons_json'] ?? null,
    'scanned_at' => $scan['scanned_at'] ?? null,
]));

$signals = !empty($scan['signals_json']) ? json_decode($scan['signals_json'], true) : [];
$out['summary'] = $scan['summary'] ?? null;
$out['findings'] = !empty($scan['findings_json']) ? json_decode($scan['findings_json'], true) : [];
$out['positives'] = !empty($scan['positives_json']) ? json_decode($scan['positives_json'], true) : [];
$out['signals'] = $signals ?: new stdClass();
$out['scan_error'] = $scan['error'] ?? null;

$out['contact'] = [
    'phone' => $business['phone'] ?: ($signals['phones_found'][0] ?? ''),
    'email' => $business['email'] ?: ($signals['emails_found'][0] ?? ''),
    'emails_on_site' => $signals['emails_found'] ?? [],
    'phones_on_site' => $signals['phones_found'] ?? [],
    'social_links' => $signals['social_links'] ?? [],
    'opening_hours' => $business['opening_hours'] ?? '',
];

$osmUrl = null;
if (!empty($business['source_id']) && str_contains((string) $business['source_id'], '/')) {
    [$type, $osmId] = explode('/', (string) $business['source_id'], 2);
    $osmUrl = "https://www.openstreetmap.org/{$type}/{$osmId}";
}
$out['osm_url'] = $osmUrl;
$out['map_url'] = ($business['lat'] !== null && $business['lon'] !== null)
    ? "https://www.openstreetmap.org/?mlat={$business['lat']}&mlon={$business['lon']}#map=18/{$business['lat']}/{$business['lon']}"
    : null;

json_response($out);
