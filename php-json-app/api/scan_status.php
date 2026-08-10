<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/tasks.php';

require_method('GET');

$id = trim((string) ($_GET['id'] ?? ''));
if ($id === '') {
    json_error('id is required');
}

$job = get_job($id);
if (!$job) {
    json_error('Job not found', 404);
}

json_response($job);
