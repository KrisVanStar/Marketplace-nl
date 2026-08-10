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

$business = find_business($id);
if (!$business) {
    json_error('Lead not found', 404);
}

$scan = latest_scan_for($id);
$out = lead_row_to_array($business, $scan);
$out['signals'] = $scan['signals'] ?? new stdClass();

json_response($out);
