<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/leads.php';
require_once __DIR__ . '/../includes/tasks.php';

require_method('POST');

$payload = read_json_body();
$id = clamp_int($payload['id'] ?? null, 1, PHP_INT_MAX, 0);
if ($id === 0) {
    json_error('id is required');
}

$pdo = db();
if (!find_business($pdo, $id)) {
    json_error('Lead not found', 404);
}

$updated = false;
if (isset($payload['status'])) {
    $status = trim((string) $payload['status']);
    if (!in_array($status, ['new', 'contacted', 'won', 'ignored'], true)) {
        json_error('Invalid status');
    }
    $pdo->prepare('UPDATE businesses SET status = ? WHERE id = ?')->execute([$status, $id]);
    $updated = true;
}
if (isset($payload['notes'])) {
    $pdo->prepare('UPDATE businesses SET notes = ? WHERE id = ?')
        ->execute([mb_substr(trim((string) $payload['notes']), 0, 2000), $id]);
    $updated = true;
}
if (!$updated) {
    json_error('Niets om bij te werken (verwacht status en/of notes)');
}

$business = find_business($pdo, $id);
$scan = latest_scan_for($pdo, $id);
json_response(lead_row_to_array(array_merge($business, [
    'latest_score' => $scan['score'] ?? null,
    'latest_priority' => $scan['priority'] ?? null,
    'latest_summary' => $scan['summary'] ?? null,
    'latest_reasons_json' => $scan['reasons_json'] ?? null,
    'scanned_at' => $scan['scanned_at'] ?? null,
])));
