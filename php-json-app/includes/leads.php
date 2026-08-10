<?php
declare(strict_types=1);

/**
 * Fetches businesses joined with their most recent scan, with optional
 * filters. $filters keys: city, category, status, min_score, priority,
 * only_without_website, sort, limit.
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
    $priority = $filters['priority'] ?? '';
    $minScore = $filters['min_score'] ?? null;
    $onlyNoWebsite = !empty($filters['only_without_website']);

    $rows = [];
    foreach ($data['businesses'] as $b) {
        $b = array_merge(business_defaults(), $b);
        if ($city !== '' && stripos((string) $b['city'], $city) === false) {
            continue;
        }
        if ($category !== '' && $b['category'] !== $category) {
            continue;
        }
        if ($status !== '' && $b['status'] !== $status) {
            continue;
        }
        if ($onlyNoWebsite && trim((string) $b['website']) !== '') {
            continue;
        }
        $scan = $latestByBusiness[(int) $b['id']] ?? null;
        if (!empty($minScore) && (!$scan || $scan['score'] < (int) $minScore)) {
            continue;
        }
        if ($priority !== '' && (!$scan || $scan['priority'] !== $priority)) {
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
            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        }
        if ($sort === 'name') {
            return strcasecmp((string) $a['name'], (string) $b['name']);
        }
        return ($b['latest_score'] ?? -1) <=> ($a['latest_score'] ?? -1);
    });

    $limit = max(1, min((int) ($filters['limit'] ?? 200), 1000));
    return array_slice($rows, 0, $limit);
}

function lead_row_to_array(array $business, ?array $scan): array
{
    $business = array_merge(business_defaults(), $business);
    return [
        'id' => (int) $business['id'],
        'name' => $business['name'],
        'website' => $business['website'],
        'has_website' => trim((string) $business['website']) !== '',
        'city' => $business['city'],
        'category' => $business['category'],
        'business_type' => $business['business_type'],
        'address' => $business['address'],
        'postcode' => $business['postcode'],
        'phone' => $business['phone'],
        'email' => $business['email'],
        'opening_hours' => $business['opening_hours'],
        'facebook' => $business['facebook'],
        'instagram' => $business['instagram'],
        'lat' => $business['lat'],
        'lon' => $business['lon'],
        'source' => $business['source'],
        'source_id' => $business['source_id'],
        'status' => $business['status'],
        'notes' => $business['notes'] ?? '',
        'created_at' => $business['created_at'],
        'latest_score' => $scan['score'] ?? null,
        'latest_priority' => $scan['priority'] ?? null,
        'latest_summary' => $scan['summary'] ?? null,
        'latest_reasons' => $scan['reasons'] ?? [],
        'scanned_at' => $scan['scanned_at'] ?? null,
    ];
}
