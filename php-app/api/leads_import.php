<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/discovery.php';
require_once __DIR__ . '/../includes/analyzer.php';
require_once __DIR__ . '/../includes/scoring.php';
require_once __DIR__ . '/../includes/tasks.php';

require_method('POST');

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_error('Geen bestand ontvangen');
}

$handle = fopen($_FILES['file']['tmp_name'], 'r');
if ($handle === false) {
    json_error('Kon het bestand niet lezen');
}

$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}

$header = fgetcsv($handle);
if ($header === false) {
    json_error('Het CSV-bestand is leeg');
}
$header = array_map(fn($h) => strtolower(trim((string) $h)), $header);

$pdo = db();
$jobId = uuidv4();
$pdo->prepare("INSERT INTO scan_jobs (id, status, city, category, total) VALUES (?, 'running', 'CSV-import', 'manual', 0)")
    ->execute([$jobId]);

$count = 0;
$businessIds = [];
while (($row = fgetcsv($handle)) !== false) {
    $assoc = @array_combine($header, $row);
    if ($assoc === false) {
        continue;
    }
    $name = trim((string) ($assoc['name'] ?? ($assoc['bedrijf'] ?? '')));
    if ($name === '') {
        continue;
    }

    $businessId = get_or_create_business($pdo, [
        'name' => $name,
        'website' => normalize_website((string) ($assoc['website'] ?? '')),
        'city' => trim((string) ($assoc['city'] ?? ($assoc['plaats'] ?? ''))),
        'category' => trim((string) ($assoc['category'] ?? ($assoc['categorie'] ?? ''))) ?: 'manual',
        'address' => trim((string) ($assoc['address'] ?? ($assoc['adres'] ?? ''))),
        'postcode' => trim((string) ($assoc['postcode'] ?? '')),
        'phone' => trim((string) ($assoc['phone'] ?? ($assoc['telefoon'] ?? ''))),
        'email' => trim((string) ($assoc['email'] ?? '')),
        'source' => 'csv',
    ]);
    $pdo->prepare('INSERT INTO scan_queue (job_id, business_id) VALUES (?, ?)')->execute([$jobId, $businessId]);
    $businessIds[] = $businessId;
    $count++;
}
fclose($handle);

$pdo->prepare('UPDATE scan_jobs SET total = ? WHERE id = ?')->execute([$count, $jobId]);

if ($count === 0) {
    $pdo->prepare("UPDATE scan_jobs SET status = 'done', message = 'Geen geldige rijen gevonden. Verplichte kolom: name (of bedrijf).' WHERE id = ?")
        ->execute([$jobId]);
} else {
    process_queue_batch($pdo, $jobId, 8);
}

json_response(['imported' => $count, 'business_ids' => $businessIds, 'job_id' => $jobId]);
