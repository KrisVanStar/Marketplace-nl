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

$jobId = uuidv4();
create_job($jobId, 'CSV-import', 'manual');
update_job($jobId, ['status' => 'running']);

$count = 0;
$businessIds = [];
while (($row = fgetcsv($handle)) !== false) {
    $assoc = @array_combine($header, $row);
    if ($assoc === false) {
        continue;
    }
    $name = trim((string) ($assoc['name'] ?? ($assoc['bedrijf'] ?? '')));
    if ($name === '') {
        continue; // a name is the only hard requirement; website may be empty
    }

    $businessId = get_or_create_business([
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
    enqueue_business($jobId, $businessId);
    $businessIds[] = $businessId;
    $count++;
}
fclose($handle);

update_job($jobId, ['total' => $count]);

if ($count === 0) {
    update_job($jobId, [
        'status' => 'done',
        'message' => 'Geen geldige rijen gevonden. Verplichte kolom: name (of bedrijf).',
    ]);
} else {
    process_queue_batch($jobId, 8);
}

json_response(['imported' => $count, 'business_ids' => $businessIds, 'job_id' => $jobId]);
