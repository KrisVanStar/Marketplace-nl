<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';

require_method('GET');

$id = trim((string) ($_GET['id'] ?? ''));
if ($id === '') {
    json_error('id is required');
}

$stmt = db()->prepare('SELECT * FROM scan_jobs WHERE id = ?');
$stmt->execute([$id]);
$job = $stmt->fetch();
if (!$job) {
    json_error('Job not found', 404);
}

json_response($job);
