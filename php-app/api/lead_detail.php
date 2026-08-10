<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/leads.php';

require_method('GET');

$id = clamp_int($_GET['id'] ?? null, 1, PHP_INT_MAX, 0);
if ($id === 0) {
    json_error('id is required');
}

$pdo = db();
$stmt = $pdo->prepare('SELECT * FROM businesses WHERE id = ?');
$stmt->execute([$id]);
$business = $stmt->fetch();
if (!$business) {
    json_error('Lead not found', 404);
}

$stmt = $pdo->prepare('SELECT * FROM scans WHERE business_id = ? ORDER BY id DESC LIMIT 1');
$stmt->execute([$id]);
$scan = $stmt->fetch();

$out = lead_row_to_array(array_merge($business, [
    'latest_score' => $scan['score'] ?? null,
    'latest_priority' => $scan['priority'] ?? null,
    'latest_reasons_json' => $scan['reasons_json'] ?? null,
    'scanned_at' => $scan['scanned_at'] ?? null,
]));
$out['signals'] = $scan && $scan['signals_json'] ? json_decode($scan['signals_json'], true) : new stdClass();

json_response($out);
