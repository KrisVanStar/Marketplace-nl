<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/analyzer.php';
require_once __DIR__ . '/../includes/scoring.php';
require_once __DIR__ . '/../includes/tasks.php';

require_method('POST');

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    json_error('No file uploaded');
}

$handle = fopen($_FILES['file']['tmp_name'], 'r');
if ($handle === false) {
    json_error('Could not read uploaded file');
}

// Strip a UTF-8 BOM if present.
$bom = fread($handle, 3);
if ($bom !== "\xEF\xBB\xBF") {
    rewind($handle);
}

$header = fgetcsv($handle);
if ($header === false) {
    json_error('CSV file is empty');
}
$header = array_map(fn($h) => strtolower(trim((string) $h)), $header);

$jobId = uuidv4();
create_job($jobId, 'CSV import', 'manual');
update_job($jobId, ['status' => 'running']);

$count = 0;
$businessIds = [];
while (($row = fgetcsv($handle)) !== false) {
    $assoc = @array_combine($header, $row);
    if ($assoc === false) {
        continue;
    }
    $name = trim((string) ($assoc['name'] ?? ''));
    $website = trim((string) ($assoc['website'] ?? ''));
    if ($name === '' || $website === '') {
        continue;
    }
    if (!preg_match('#^https?://#i', $website)) {
        $website = 'https://' . $website;
    }

    $businessId = get_or_create_business([
        'name' => $name,
        'website' => $website,
        'city' => trim((string) ($assoc['city'] ?? '')),
        'category' => trim((string) ($assoc['category'] ?? '')) ?: 'manual',
        'address' => trim((string) ($assoc['address'] ?? '')),
        'phone' => trim((string) ($assoc['phone'] ?? '')),
        'source' => 'csv',
        'source_id' => null,
    ]);
    enqueue_business($jobId, $businessId);
    $businessIds[] = $businessId;
    $count++;
}
fclose($handle);

update_job($jobId, ['total' => $count]);

if ($count === 0) {
    update_job($jobId, ['status' => 'done', 'message' => 'Geen geldige rijen gevonden (kolommen name en website zijn verplicht).']);
} else {
    process_queue_batch($jobId, 8);
}

json_response(['imported' => $count, 'business_ids' => $businessIds, 'job_id' => $jobId]);
