<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/tasks.php';
require_once __DIR__ . '/../includes/leads.php';

require_method('POST');

$payload = read_json_body();
$id = clamp_int($payload['id'] ?? null, 1, PHP_INT_MAX, 0);
if ($id === 0) {
    json_error('id is required');
}

$fields = [];
if (isset($payload['status'])) {
    $status = trim((string) $payload['status']);
    if (!in_array($status, ['new', 'contacted', 'won', 'ignored'], true)) {
        json_error('Invalid status');
    }
    $fields['status'] = $status;
}
if (isset($payload['notes'])) {
    $fields['notes'] = mb_substr(trim((string) $payload['notes']), 0, 2000);
}
if (empty($fields)) {
    json_error('Niets om bij te werken (verwacht status en/of notes)');
}

$found = false;
jsondb_transaction(function (array $data) use ($id, $fields, &$found) {
    foreach ($data['businesses'] as &$b) {
        if ((int) $b['id'] === $id) {
            $b = array_merge($b, $fields);
            $found = true;
        }
    }
    unset($b);
    return $data;
});

if (!$found) {
    json_error('Lead not found', 404);
}

json_response(lead_row_to_array(find_business($id), latest_scan_for($id)));
