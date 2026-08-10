<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/leads.php';
require_once __DIR__ . '/../includes/tasks.php';

require_method('GET');

$pdo = db();
$leads = fetch_leads($pdo, [
    'city' => trim((string) ($_GET['city'] ?? '')),
    'category' => trim((string) ($_GET['category'] ?? '')),
    'status' => trim((string) ($_GET['status'] ?? '')),
    'priority' => trim((string) ($_GET['priority'] ?? '')),
    'min_score' => $_GET['min_score'] ?? null,
    'only_without_website' => !empty($_GET['only_without_website']),
    'sort' => 'score_desc',
    'limit' => 1000,
]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename=leads.csv');

$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, [
    'bedrijf', 'website', 'heeft_website', 'adres', 'postcode', 'plaats', 'telefoon', 'email',
    'openingstijden', 'categorie', 'status', 'score', 'prioriteit', 'samenvatting',
    'problemen', 'aanbevelingen', 'notities',
]);

foreach ($leads as $lead) {
    $scan = latest_scan_for($pdo, (int) $lead['id']);
    $findings = !empty($scan['findings_json']) ? json_decode($scan['findings_json'], true) : [];
    fputcsv($out, [
        $lead['name'], $lead['website'], $lead['has_website'] ? 'ja' : 'nee',
        $lead['address'], $lead['postcode'], $lead['city'], $lead['phone'], $lead['email'],
        $lead['opening_hours'], $lead['category'], $lead['status'],
        $lead['latest_score'], $lead['latest_priority'], $lead['latest_summary'],
        implode(' | ', array_column($findings, 'title')),
        implode(' | ', array_column($findings, 'recommendation')),
        $lead['notes'],
    ]);
}
fclose($out);
exit;
