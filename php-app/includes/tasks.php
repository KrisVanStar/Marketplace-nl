<?php
declare(strict_types=1);

function get_or_create_business(PDO $pdo, array $b): int
{
    $hash = sha1($b['website']);
    $stmt = $pdo->prepare('SELECT id FROM businesses WHERE website_hash = ?');
    $stmt->execute([$hash]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int) $existing;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO businesses (name, website, website_hash, city, category, address, phone, source, source_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $b['name'],
        $b['website'],
        $hash,
        $b['city'] ?? '',
        $b['category'] ?? '',
        $b['address'] ?? '',
        $b['phone'] ?? '',
        $b['source'] ?? 'manual',
        $b['source_id'] ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

/** Scans one business's website and stores the resulting scan row. Returns the scoring result. */
function scan_and_store(PDO $pdo, int $businessId, string $website): array
{
    $signals = analyze_website($website);
    $result = score_site($signals);

    $stmt = $pdo->prepare(
        'INSERT INTO scans (business_id, score, priority, reasons_json, signals_json, is_https, has_viewport,
            status_code, final_url, response_time_ms, error)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $businessId,
        $result['score'],
        $result['priority'],
        json_encode($result['reasons'], JSON_UNESCAPED_UNICODE),
        json_encode($signals, JSON_UNESCAPED_UNICODE),
        !empty($signals['is_https']) ? 1 : 0,
        !empty($signals['has_viewport_meta']) ? 1 : 0,
        $signals['status_code'],
        $signals['final_url'] ?: null,
        $signals['response_time_ms'],
        $signals['error'] !== '' ? $signals['error'] : null,
    ]);

    return $result;
}

/** Processes up to $limit queued items for a job (or across all running jobs if $jobId is null). */
function process_queue_batch(PDO $pdo, ?string $jobId = null, int $limit = 5): int
{
    if ($jobId !== null) {
        $stmt = $pdo->prepare(
            'SELECT sq.id AS queue_id, sq.job_id, sq.business_id, b.website
             FROM scan_queue sq JOIN businesses b ON b.id = sq.business_id
             WHERE sq.job_id = ? ORDER BY sq.id ASC LIMIT ' . (int) $limit
        );
        $stmt->execute([$jobId]);
    } else {
        $stmt = $pdo->query(
            'SELECT sq.id AS queue_id, sq.job_id, sq.business_id, b.website
             FROM scan_queue sq JOIN businesses b ON b.id = sq.business_id
             ORDER BY sq.id ASC LIMIT ' . (int) $limit
        );
    }
    $rows = $stmt->fetchAll();

    $processed = 0;
    $jobIdsTouched = [];
    foreach ($rows as $row) {
        try {
            $result = scan_and_store($pdo, (int) $row['business_id'], $row['website']);
            $isLead = in_array($result['priority'], ['high', 'medium'], true) ? 1 : 0;
        } catch (Throwable $e) {
            $isLead = 0;
        }

        $pdo->prepare('DELETE FROM scan_queue WHERE id = ?')->execute([$row['queue_id']]);
        $pdo->prepare(
            'UPDATE scan_jobs SET processed = processed + 1, found_leads = found_leads + ? WHERE id = ?'
        )->execute([$isLead, $row['job_id']]);

        $jobIdsTouched[$row['job_id']] = true;
        $processed++;
    }

    foreach (array_keys($jobIdsTouched) as $touchedJobId) {
        $remaining = $pdo->prepare('SELECT COUNT(*) FROM scan_queue WHERE job_id = ?');
        $remaining->execute([$touchedJobId]);
        if ((int) $remaining->fetchColumn() === 0) {
            $job = $pdo->prepare('SELECT total, processed, found_leads FROM scan_jobs WHERE id = ?');
            $job->execute([$touchedJobId]);
            $j = $job->fetch();
            $message = $j
                ? "Klaar: {$j['processed']}/{$j['total']} sites gescand, {$j['found_leads']} kansrijke leads."
                : 'Klaar.';
            $pdo->prepare("UPDATE scan_jobs SET status = 'done', message = ? WHERE id = ?")
                ->execute([$message, $touchedJobId]);
        }
    }

    return $processed;
}
