<?php
declare(strict_types=1);

/** Fields a business record carries, with their defaults. */
function business_defaults(): array
{
    return [
        'name' => '',
        'website' => '',
        'has_website' => false,
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
        'notes' => '',
    ];
}

function get_or_create_business(array $b): int
{
    $b = array_merge(business_defaults(), array_intersect_key($b, business_defaults()));
    $b['has_website'] = trim((string) $b['website']) !== '';

    $id = null;
    jsondb_transaction(function (array $data) use ($b, &$id) {
        // Match on website when there is one, otherwise on name + address.
        foreach ($data['businesses'] as $existing) {
            $sameSite = $b['has_website']
                && !empty($existing['website'])
                && strtolower(rtrim($existing['website'], '/')) === strtolower(rtrim($b['website'], '/'));
            $sameNameAddress = !$b['has_website']
                && empty($existing['website'])
                && strcasecmp($existing['name'], $b['name']) === 0
                && strcasecmp((string) ($existing['address'] ?? ''), (string) $b['address']) === 0;
            if ($sameSite || $sameNameAddress) {
                $id = (int) $existing['id'];
                return $data;
            }
        }
        $id = jsondb_next_id($data['businesses']);
        $data['businesses'][] = array_merge($b, [
            'id' => $id,
            'status' => 'new',
            'created_at' => date('c'),
        ]);
        return $data;
    });
    return $id;
}

function find_business(int $id): ?array
{
    foreach (jsondb_read()['businesses'] as $b) {
        if ((int) $b['id'] === $id) {
            return array_merge(business_defaults(), $b);
        }
    }
    return null;
}

function latest_scan_for(int $businessId): ?array
{
    $best = null;
    foreach (jsondb_read()['scans'] as $scan) {
        if ((int) $scan['business_id'] !== $businessId) {
            continue;
        }
        if ($best === null || $scan['id'] > $best['id']) {
            $best = $scan;
        }
    }
    return $best;
}

/**
 * Runs the right analysis for a business: a real scan when it has a
 * website, or the "no website at all" verdict when it doesn't.
 * Returns ['signals' => array, 'result' => array].
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

/** Scans one business (slow network calls happen outside any lock) and stores the scan. */
function scan_and_store(int $businessId, array $business): array
{
    ['signals' => $signals, 'result' => $result] = evaluate_business($business);

    jsondb_transaction(function (array $data) use ($businessId, $signals, $result) {
        $data['scans'][] = [
            'id' => jsondb_next_id($data['scans']),
            'business_id' => $businessId,
            'score' => $result['score'],
            'priority' => $result['priority'],
            'summary' => $result['summary'],
            'reasons' => $result['reasons'],
            'findings' => $result['findings'],
            'positives' => $result['positives'],
            'signals' => $signals,
            'status_code' => $signals['status_code'] ?? null,
            'final_url' => ($signals['final_url'] ?? '') ?: null,
            'response_time_ms' => $signals['response_time_ms'] ?? null,
            'error' => !empty($signals['error']) ? $signals['error'] : null,
            'scanned_at' => date('c'),
        ];
        return $data;
    });

    return $result;
}

function get_job(string $jobId): ?array
{
    foreach (jsondb_read()['scan_jobs'] as $job) {
        if ($job['id'] === $jobId) {
            return $job;
        }
    }
    return null;
}

function create_job(string $jobId, string $city, string $category): void
{
    jsondb_transaction(function (array $data) use ($jobId, $city, $category) {
        $data['scan_jobs'][] = [
            'id' => $jobId,
            'status' => 'pending',
            'city' => $city,
            'category' => $category,
            'total' => 0,
            'processed' => 0,
            'found_leads' => 0,
            'message' => null,
            'created_at' => date('c'),
        ];
        return $data;
    });
}

function update_job(string $jobId, array $fields): void
{
    jsondb_transaction(function (array $data) use ($jobId, $fields) {
        foreach ($data['scan_jobs'] as &$job) {
            if ($job['id'] === $jobId) {
                $job = array_merge($job, $fields);
            }
        }
        unset($job);
        return $data;
    });
}

function enqueue_business(string $jobId, int $businessId): void
{
    jsondb_transaction(function (array $data) use ($jobId, $businessId) {
        $data['scan_queue'][] = [
            'id' => jsondb_next_id($data['scan_queue']),
            'job_id' => $jobId,
            'business_id' => $businessId,
            'created_at' => date('c'),
        ];
        return $data;
    });
}

/**
 * Processes up to $limit queued items (optionally scoped to one job).
 * Queue entries are "claimed" (removed from the queue) under a single
 * lock before any scanning happens, so two overlapping runs (e.g. a
 * cron tick that overlaps a page-triggered batch) never scan the same
 * item twice.
 */
function process_queue_batch(?string $jobId = null, int $limit = 5): int
{
    $claimed = [];
    jsondb_transaction(function (array $data) use ($jobId, $limit, &$claimed) {
        $queue = $data['scan_queue'];
        if ($jobId !== null) {
            $queue = array_values(array_filter($queue, fn($q) => $q['job_id'] === $jobId));
        }
        usort($queue, fn($a, $b) => $a['id'] <=> $b['id']);
        $claimed = array_slice($queue, 0, $limit);

        $claimedIds = array_column($claimed, 'id');
        $data['scan_queue'] = array_values(array_filter(
            $data['scan_queue'],
            fn($q) => !in_array($q['id'], $claimedIds, true)
        ));
        return $data;
    });

    if (empty($claimed)) {
        return 0;
    }

    $businessesById = [];
    foreach (jsondb_read()['businesses'] as $b) {
        $businessesById[(int) $b['id']] = array_merge(business_defaults(), $b);
    }

    $deltaByJob = []; // job_id => [processed, found_leads]
    foreach ($claimed as $item) {
        $business = $businessesById[(int) $item['business_id']] ?? null;
        $isLead = 0;
        if ($business !== null) {
            try {
                $result = scan_and_store((int) $item['business_id'], $business);
                $isLead = in_array($result['priority'], ['high', 'medium', 'no_website'], true) ? 1 : 0;
            } catch (Throwable $e) {
                $isLead = 0;
            }
        }
        $jid = $item['job_id'];
        if (!isset($deltaByJob[$jid])) {
            $deltaByJob[$jid] = [0, 0];
        }
        $deltaByJob[$jid][0]++;
        $deltaByJob[$jid][1] += $isLead;
    }

    jsondb_transaction(function (array $data) use ($deltaByJob) {
        foreach ($data['scan_jobs'] as &$job) {
            if (!isset($deltaByJob[$job['id']])) {
                continue;
            }
            [$proc, $leads] = $deltaByJob[$job['id']];
            $job['processed'] += $proc;
            $job['found_leads'] += $leads;

            $remaining = array_filter($data['scan_queue'], fn($q) => $q['job_id'] === $job['id']);
            if (empty($remaining) && $job['status'] !== 'done') {
                $job['status'] = 'done';
                $job['message'] = "Klaar: {$job['processed']} van {$job['total']} bedrijven geanalyseerd, {$job['found_leads']} kansrijke leads.";
            }
        }
        unset($job);
        return $data;
    });

    return count($claimed);
}
