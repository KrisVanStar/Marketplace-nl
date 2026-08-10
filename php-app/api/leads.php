<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/leads.php';

require_method('GET');

json_response(fetch_leads(db(), [
    'city' => trim((string) ($_GET['city'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'priority' => trim((string) ($_GET['priority'] ?? '')),
    'min_score' => $_GET['min_score'] ?? null,
    'only_without_website' => !empty($_GET['only_without_website']),
    'sort' => $_GET['sort'] ?? 'score_desc',
    'limit' => $_GET['limit'] ?? 200,
]));
