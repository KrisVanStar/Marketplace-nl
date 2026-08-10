<?php
declare(strict_types=1);

/**
 * Fetches businesses joined with their most recent scan, with optional
 * filters. $filters keys: city, category, status, min_score, sort, limit.
 */
function fetch_leads(array $filters = []): array
{
    $data = jsondb_read();

    $latestByBusiness = [];
    foreach ($data['scans'] as $scan) {
        $bid = (int) $scan['business_id'];
        if (!isset($latestByBusiness[$bid]) || $scan['id'] > $latestByBusiness[$bid]['id']) {
            $latestByBusiness[$bid] = $scan;
        }
    }

    $city = $filters['city'] ?? '';
    $category = $filters['category'] ?? '';
    $status = $filters['status'] ?? '';
    $minScore = $filters['min_score'] ?? null;

    $rows = [];
    foreach ($data['businesses'] as $b) {
        if ($city !== '' && stripos($b['city'], $city) === false) {
            continue;
        }
        if ($category !== '' && $b['category'] !== $category) {
            continue;
        }
        if ($status !== '' && $b['status'] !== $status) {
            continue;
        }
        $scan = $latestByBusiness[(int) $b['id']] ?? null;
        if (!empty($minScore) && (!$scan || $scan['score'] < (int) $minScore)) {
            continue;
        }
        $rows[] = lead_row_to_array($b, $scan);
    }

    $sort = $filters['sort'] ?? 'score_desc';
    usort($rows, function ($a, $b) use ($sort) {
        if ($sort === 'score_asc') {
            return ($a['latest_score'] ?? -1) <=> ($b['latest_score'] ?? -1);
        }
        if ($sort === 'newest') {
            return strcmp($b['created_at'], $a['created_at']);
        }
        return ($b['latest_score'] ?? -1) <=> ($a['latest_score'] ?? -1);
    });

    $limit = max(1, min((int) ($filters['limit'] ?? 200), 1000));
    return array_slice($rows, 0, $limit);
}

function lead_row_to_array(array $business, ?array $scan): array
{
    return [
        'id' => (int) $business['id'],
        'name' => $business['name'],
        'website' => $business['website'],
        'city' => $business['city'],
        'category' => $business['category'],
        'address' => $business['address'],
        'phone' => $business['phone'],
        'status' => $business['status'],
        'created_at' => $business['created_at'],
        'latest_score' => $scan['score'] ?? null,
        'latest_priority' => $scan['priority'] ?? null,
        'latest_reasons' => $scan['reasons'] ?? [],
        'scanned_at' => $scan['scanned_at'] ?? null,
    ];
}
