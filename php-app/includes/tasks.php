<?php
declare(strict_types=1);

/** Fields a business record carries, with their defaults. */
function business_defaults(): array
{
    return [
        'name' => '',
        'website' => '',
        'city' => '',
        'category' => '',
        'business_type' => '',
        'address' => '',
        'postcode' => '',
        'phone' => '',
        'email' => '',
        'opening_hours' => '',
        'facebook' => '',
        'instagram' => '',
        'lat' => null,
        'lon' => null,
        'source' => 'manual',
        'source_id' => null,
    ];
}

/**
 * Identity of a business: its website when it has one, otherwise its
 * name + address — so several businesses without a website don't all
 * collide on the same unique key.
 */
function business_identity_hash(array $b): string
{
    $website = trim((string) ($b['website'] ?? ''));
    if ($website !== '') {
        return sha1(strtolower(rtrim($website, '/')));
    }
    return sha1(strtolower(trim($b['name'] . '|' . ($b['address'] ?? '') . '|' . ($b['city'] ?? ''))));
}

function get_or_create_business(PDO $pdo, array $b): int
{
    $b = array_merge(business_defaults(), array_intersect_key($b, business_defaults()));
    $hash = business_identity_hash($b);

    $stmt = $pdo->prepare('SELECT id FROM businesses WHERE website_hash = ?');
    $stmt->execute([$hash]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int) $existing;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO businesses
            (name, website, website_hash, has_website, city, category, business_type, address,
             postcode, phone, email, opening_hours, facebook, instagram, lat, lon, source, source_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $b['name'],
        $b['website'],
        $hash,
        trim((string) $b['website']) !== '' ? 1 : 0,
        $b['city'],
        $b['category'],
        $b['business_type'],
        $b['address'],
        $b['postcode'],
        $b['phone'],
        $b['email'],
        $b['opening_hours'],
        $b['facebook'],
        $b['instagram'],
        $b['lat'],
        $b['lon'],
        $b['source'],
        $b['source_id'],
    ]);
    return (int) $pdo->lastInsertId();
}

function find_business(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM businesses WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function latest_scan_for(PDO $pdo, int $businessId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM scans WHERE business_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$businessId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Runs the right analysis for a business: a real scan when it has a
 * website, or the "no website at all" verdict when it doesn't.
 */
function evaluate_business(array $business): array
{
    $website = trim((string) ($business['website'] ?? ''));
    if ($website === '') {
        return [
            'signals' => ['no_website' => true, 'reachable' => false],
            'result' => score_no_website(),
        ];
    }
    $signals = analyze_website($website);
    return ['signals' => $signals, 'result' => score_site($signals)];
}

/** Scans one business's website and stores the resulting scan row. */
function scan_and_store(PDO $pdo, int $businessId, array $business): array
{
    ['signals' => $signals, 'result' => $result] = evaluate_business($business);

    $stmt = $pdo->prepare(
        'INSERT INTO scans (business_id, score, priority, summary, reasons_json, findings_json,
            positives_json, signals_json, status_code, final_url, response_time_ms, error)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $businessId,
        $result['score'],
        $result['priority'],
        mb_substr($result['summary'], 0, 500),
        json_encode($result['reasons'], JSON_UNESCAPED_UNICODE),
        json_encode($result['findings'], JSON_UNESCAPED_UNICODE),
        json_encode($result['positives'], JSON_UNESCAPED_UNICODE),
        json_encode($signals, JSON_UNESCAPED_UNICODE),
        $signals['status_code'] ?? null,
        ($signals['final_url'] ?? '') ?: null,
        $signals['response_time_ms'] ?? null,
        !empty($signals['error']) ? mb_substr($signals['error'], 0, 500) : null,
    ]);

    return $result;
}

/**
 * Processes up to $limit queued items for a job (or across all jobs when
 * $jobId is null). Entries are claimed under a transaction before any
 * scanning happens, so overlapping cron runs never scan the same item twice.
 */
function process_queue_batch(PDO $pdo, ?string $jobId = null, int $limit = 5): int
{
    $pdo->beginTransaction();
    try {
        $sql = 'SELECT sq.id AS queue_id, sq.job_id, sq.business_id
                FROM scan_queue sq' . ($jobId !== null ? ' WHERE sq.job_id = ?' : '') . '
                ORDER BY sq.id ASC LIMIT ' . (int) $limit . ' FOR UPDATE';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($jobId !== null ? [$jobId] : []);
        $claimed = $stmt->fetchAll();

        if (!empty($claimed)) {
            $ids = array_column($claimed, 'queue_id');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("DELETE FROM scan_queue WHERE id IN ({$placeholders})")->execute($ids);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    if (empty($claimed)) {
        return 0;
    }

    $deltaByJob = [];
    foreach ($claimed as $item) {
        $business = find_business($pdo, (int) $item['business_id']);
        $isLead = 0;
        if ($business !== null) {
            try {
                $result = scan_and_store($pdo, (int) $item['business_id'], $business);
                $isLead = in_array($result['priority'], ['high', 'medium', 'no_website'], true) ? 1 : 0;
            } catch (Throwable $e) {
                $isLead = 0;
            }
        }
        $jid = $item['job_id'];
        $deltaByJob[$jid] ??= [0, 0];
        $deltaByJob[$jid][0]++;
        $deltaByJob[$jid][1] += $isLead;
    }

    foreach ($deltaByJob as $jid => [$proc, $leads]) {
        $pdo->prepare('UPDATE scan_jobs SET processed = processed + ?, found_leads = found_leads + ? WHERE id = ?')
            ->execute([$proc, $leads, $jid]);

        $remaining = $pdo->prepare('SELECT COUNT(*) FROM scan_queue WHERE job_id = ?');
        $remaining->execute([$jid]);
        if ((int) $remaining->fetchColumn() === 0) {
            $job = $pdo->prepare('SELECT total, processed, found_leads FROM scan_jobs WHERE id = ?');
            $job->execute([$jid]);
            $j = $job->fetch();
            $message = $j
                ? "Klaar: {$j['processed']} van {$j['total']} bedrijven geanalyseerd, {$j['found_leads']} kansrijke leads."
                : 'Klaar.';
            $pdo->prepare("UPDATE scan_jobs SET status = 'done', message = ? WHERE id = ?")->execute([$message, $jid]);
        }
    }

    return count($claimed);
}
