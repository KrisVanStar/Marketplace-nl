<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/leads.php';

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

$pdo = db();
$stmt = $pdo->prepare('SELECT id FROM businesses WHERE id = ?');
$stmt->execute([$id]);
if (!$stmt->fetchColumn()) {
    json_error('Lead not found', 404);
}

$pdo->prepare('UPDATE businesses SET status = ? WHERE id = ?')->execute([$status, $id]);

$stmt = $pdo->prepare(
    "SELECT b.*, s.score AS latest_score, s.priority AS latest_priority,
            s.reasons_json AS latest_reasons_json, s.scanned_at AS scanned_at
     FROM businesses b
     LEFT JOIN scans s ON s.id = (SELECT id FROM scans WHERE business_id = b.id ORDER BY id DESC LIMIT 1)
     WHERE b.id = ?"
);
$stmt->execute([$id]);
json_response(lead_row_to_array($stmt->fetch()));
