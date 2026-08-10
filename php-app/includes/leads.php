<?php
declare(strict_types=1);

/**
 * Fetches businesses joined with their most recent scan, with optional
 * filters. $filters keys: city, category, status, min_score, sort, limit.
 */
function fetch_leads(PDO $pdo, array $filters = []): array
{
    $sql = "SELECT b.*, s.score AS latest_score, s.priority AS latest_priority,
                   s.reasons_json AS latest_reasons_json, s.scanned_at AS scanned_at
            FROM businesses b
            LEFT JOIN (
                SELECT s1.* FROM scans s1
                INNER JOIN (SELECT business_id, MAX(id) AS max_id FROM scans GROUP BY business_id) t
                    ON s1.business_id = t.business_id AND s1.id = t.max_id
            ) s ON s.business_id = b.id
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
    if (!empty($filters['min_score'])) {
        $sql .= ' AND s.score >= ?';
        $params[] = (int) $filters['min_score'];
    }

    $sort = $filters['sort'] ?? 'score_desc';
    if ($sort === 'score_asc') {
        $sql .= ' ORDER BY s.score ASC';
    } elseif ($sort === 'newest') {
        $sql .= ' ORDER BY b.created_at DESC';
    } else {
        $sql .= ' ORDER BY s.score DESC';
    }

    $limit = (int) ($filters['limit'] ?? 200);
    $sql .= ' LIMIT ' . max(1, min($limit, 1000));

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return array_map('lead_row_to_array', $stmt->fetchAll());
}

function lead_row_to_array(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'name' => $row['name'],
        'website' => $row['website'],
        'city' => $row['city'],
        'category' => $row['category'],
        'address' => $row['address'],
        'phone' => $row['phone'],
        'status' => $row['status'],
        'created_at' => $row['created_at'],
        'latest_score' => $row['latest_score'] !== null ? (int) $row['latest_score'] : null,
        'latest_priority' => $row['latest_priority'],
        'latest_reasons' => $row['latest_reasons_json'] ? json_decode($row['latest_reasons_json'], true) : [],
        'scanned_at' => $row['scanned_at'],
    ];
}
