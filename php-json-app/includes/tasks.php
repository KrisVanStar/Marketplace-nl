<?php
declare(strict_types=1);

function get_or_create_business(array $b): int
{
    $id = null;
    jsondb_transaction(function (array $data) use ($b, &$id) {
        $normalized = strtolower(rtrim($b['website'], '/'));
        foreach ($data['businesses'] as $existing) {
            if (strtolower(rtrim($existing['website'], '/')) === $normalized) {
                $id = (int) $existing['id'];
                return $data;
            }
        }
        $id = jsondb_next_id($data['businesses']);
        $data['businesses'][] = [
            'id' => $id,
            'name' => $b['name'],
            'website' => $b['website'],
            'city' => $b['city'] ?? '',
            'category' => $b['category'] ?? '',
            'address' => $b['address'] ?? '',
            'phone' => $b['phone'] ?? '',
            'source' => $b['source'] ?? 'manual',
            'source_id' => $b['source_id'] ?? null,
            'status' => 'new',
            'created_at' => date('c'),
        ];
        return $data;
    });
    return $id;
}

function find_business(int $id): ?array
{
    foreach (jsondb_read()['businesses'] as $b) {
        if ((int) $b['id'] === $id) {
            return $b;
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

/** Scans one business's website (slow network call, done outside any lock) and stores the resulting scan. */
function scan_and_store(int $businessId, string $website): array
{
    $signals = analyze_website($website);
    $result = score_site($signals);

    jsondb_transaction(function (array $data) use ($businessId, $signals, $result) {
        $data['scans'][] = [
            'id' => jsondb_next_id($data['scans']),
            'business_id' => $businessId,
            'score' => $result['score'],
            'priority' => $result['priority'],
            'reasons' => $result['reasons'],
            'signals' => $signals,
            'is_https' => !empty($signals['is_https']),
            'has_viewport' => !empty($signals['has_viewport_meta']),
            'status_code' => $signals['status_code'],
            'final_url' => $signals['final_url'] ?: null,
            'response_time_ms' => $signals['response_time_ms'],
            'error' => $signals['error'] !== '' ? $signals['error'] : null,
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
        $businessesById[(int) $b['id']] = $b;
    }

    $deltaByJob = []; // job_id => [processed, found_leads]
    foreach ($claimed as $item) {
        $business = $businessesById[(int) $item['business_id']] ?? null;
        $isLead = 0;
        if ($business !== null) {
            try {
                $result = scan_and_store((int) $item['business_id'], $business['website']);
                $isLead = in_array($result['priority'], ['high', 'medium'], true) ? 1 : 0;
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
                $job['message'] = "Klaar: {$job['processed']}/{$job['total']} sites gescand, {$job['found_leads']} kansrijke leads.";
            }
        }
        unset($job);
        return $data;
    });

    return count($claimed);
}
