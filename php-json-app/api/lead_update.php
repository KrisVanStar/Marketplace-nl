<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/leads.php';
require_once __DIR__ . '/../includes/tasks.php';

require_method('POST');

$payload = read_json_body();
$id = clamp_int($payload['id'] ?? null, 1, PHP_INT_MAX, 0);
$status = trim((string) ($payload['status'] ?? ''));

if ($id === 0) {
    json_error('id is required');
}
if (!in_array($status, ['new', 'contacted', 'won', 'ignored'], true)) {
    json_error('Invalid status');
}

$found = false;
jsondb_transaction(function (array $data) use ($id, $status, &$found) {
    foreach ($data['businesses'] as &$b) {
        if ((int) $b['id'] === $id) {
            $b['status'] = $status;
            $found = true;
        }
    }
    unset($b);
    return $data;
});

if (!$found) {
    json_error('Lead not found', 404);
}

$business = find_business($id);
$scan = latest_scan_for($id);
json_response(lead_row_to_array($business, $scan));
