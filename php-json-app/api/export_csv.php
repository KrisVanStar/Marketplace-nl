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
    'sort' => 'score_desc',
    'limit' => 1000,
];

$leads = fetch_leads($filters);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=leads.csv');

$out = fopen('php://output', 'w');
fputcsv($out, ['name', 'website', 'city', 'category', 'address', 'phone', 'status', 'score', 'priority', 'reasons']);
foreach ($leads as $lead) {
    fputcsv($out, [
        $lead['name'],
        $lead['website'],
        $lead['city'],
        $lead['category'],
        $lead['address'],
        $lead['phone'],
        $lead['status'],
        $lead['latest_score'],
        $lead['latest_priority'],
        implode('; ', $lead['latest_reasons']),
    ]);
}
fclose($out);
exit;
