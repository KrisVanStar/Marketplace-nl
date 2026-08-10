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
$stmt = $pdo->prepare('SELECT * FROM businesses WHERE id = ?');
$stmt->execute([$id]);
$business = $stmt->fetch();
if (!$business) {
    json_error('Lead not found', 404);
}

scan_and_store($pdo, $id, $business['website']);

$stmt = $pdo->prepare(
    "SELECT b.*, s.score AS latest_score, s.priority AS latest_priority,
            s.reasons_json AS latest_reasons_json, s.scanned_at AS scanned_at
     FROM businesses b
     LEFT JOIN scans s ON s.id = (SELECT id FROM scans WHERE business_id = b.id ORDER BY id DESC LIMIT 1)
     WHERE b.id = ?"
);
$stmt->execute([$id]);
json_response(lead_row_to_array($stmt->fetch()));
