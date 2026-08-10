<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/leads.php';

require_method('GET');

$filters = [
    'city' => trim((string) ($_GET['city'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'min_score' => $_GET['min_score'] ?? null,
    'sort' => $_GET['sort'] ?? 'score_desc',
    'limit' => $_GET['limit'] ?? 200,
];

json_response(fetch_leads($filters));
