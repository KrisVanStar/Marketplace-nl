<?php
declare(strict_types=1);

/**
 * Fetches businesses joined with their most recent scan, with optional
 * filters. $filters keys: city, category, status, priority, min_score,
 * only_without_website, sort, limit.
 */
function fetch_leads(PDO $pdo, array $filters = []): array
{
    $sql = "SELECT b.*, s.score AS latest_score, s.priority AS latest_priority,
                   s.summary AS latest_summary, s.reasons_json AS latest_reasons_json,
                   s.scanned_at AS scanned_at
            FROM businesses b
            LEFT JOIN scans s ON s.id = (
                SELECT id FROM scans WHERE business_id = b.id ORDER BY id DESC LIMIT 1
            )
            WHERE 1=1";
    $params = [];

    if (!empty($filters['city'])) {
        $sql .= ' AND b.city LIKE ?';
        $params[] = '%' . $filters['city'] . '%';
    }
    if (!empty($filters['category'])) {
        $sql .= ' AND b.category = ?';
        $params[] = $filters['category'];
    }
    if (!empty($filters['status'])) {
        $sql .= ' AND b.status = ?';
        $params[] = $filters['status'];
    }
    if (!empty($filters['priority'])) {
        $sql .= ' AND s.priority = ?';
        $params[] = $filters['priority'];
    }
    if (!empty($filters['min_score'])) {
        $sql .= ' AND s.score >= ?';
        $params[] = (int) $filters['min_score'];
    }
    if (!empty($filters['only_without_website'])) {
        $sql .= " AND (b.website IS NULL OR b.website = '')";
    }

    $sql .= match ($filters['sort'] ?? 'score_desc') {
        'score_asc' => ' ORDER BY s.score ASC',
        'newest' => ' ORDER BY b.created_at DESC',
        'name' => ' ORDER BY b.name ASC',
        default => ' ORDER BY s.score DESC',
    };

    $limit = max(1, min((int) ($filters['limit'] ?? 200), 1000));
    $sql .= ' LIMIT ' . $limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('lead_row_to_array', $stmt->fetchAll());
}

function lead_row_to_array(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'website' => $row['website'] ?? '',
        'has_website' => trim((string) ($row['website'] ?? '')) !== '',
        'city' => $row['city'],
        'category' => $row['category'],
        'business_type' => $row['business_type'] ?? '',
        'address' => $row['address'],
        'postcode' => $row['postcode'] ?? '',
        'phone' => $row['phone'],
        'email' => $row['email'] ?? '',
        'opening_hours' => $row['opening_hours'] ?? '',
        'facebook' => $row['facebook'] ?? '',
        'instagram' => $row['instagram'] ?? '',
        'lat' => $row['lat'] ?? null,
        'lon' => $row['lon'] ?? null,
        'source' => $row['source'] ?? '',
        'source_id' => $row['source_id'] ?? null,
        'status' => $row['status'],
        'notes' => $row['notes'] ?? '',
        'created_at' => $row['created_at'],
        'latest_score' => isset($row['latest_score']) && $row['latest_score'] !== null ? (int) $row['latest_score'] : null,
        'latest_priority' => $row['latest_priority'] ?? null,
        'latest_summary' => $row['latest_summary'] ?? null,
        'latest_reasons' => !empty($row['latest_reasons_json']) ? json_decode($row['latest_reasons_json'], true) : [],
        'scanned_at' => $row['scanned_at'] ?? null,
    ];
}
