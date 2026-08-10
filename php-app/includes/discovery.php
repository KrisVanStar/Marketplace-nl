<?php
declare(strict_types=1);

/**
 * Finds small Dutch businesses that list a website, via OpenStreetMap.
 * Nominatim geocodes a city name to a bounding box; Overpass API then
 * queries OSM for businesses inside that box with a `website` tag. Both
 * are free, keyless, community-run services with strict rate limits, so
 * only one request is made at a time.
 */

const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
const OVERPASS_URL = 'https://overpass-api.de/api/interpreter';

// category label -> list of [osm_key, osm_value] tag filters. Chosen to
// skew towards small/independent local businesses rather than big chains.
function categories(): array
{
    return [
        'restaurant' => [['amenity', 'restaurant']],
        'cafe' => [['amenity', 'cafe']],
        'hairdresser' => [['shop', 'hairdresser']],
        'beauty_salon' => [['shop', 'beauty']],
        'bakery' => [['shop', 'bakery']],
        'butcher' => [['shop', 'butcher']],
        'florist' => [['shop', 'florist']],
        'garage_car_repair' => [['shop', 'car_repair']],
        'plumber' => [['craft', 'plumber']],
        'electrician' => [['craft', 'electrician']],
        'carpenter' => [['craft', 'carpenter']],
        'dentist' => [['amenity', 'dentist']],
        'physiotherapist' => [['healthcare', 'physiotherapist']],
        'law_office' => [['office', 'lawyer']],
        'accountant' => [['office', 'accountant']],
        'real_estate' => [['office', 'estate_agent']],
        'architect' => [['office', 'architect']],
        'gym' => [['leisure', 'fitness_centre']],
        'clothing_store' => [['shop', 'clothes']],
        'furniture_store' => [['shop', 'furniture']],
    ];
}

/** Returns [south, west, north, east] bounding box for a NL place name. */
function geocode_city(string $city): array
{
    $params = http_build_query([
        'q' => "{$city}, Netherlands",
        'format' => 'json',
        'limit' => 1,
        'countrycodes' => 'nl',
    ]);
    $res = http_get(NOMINATIM_URL . '?' . $params, 15);
    if (!$res['ok'] || $res['status'] !== 200) {
        throw new RuntimeException('Kon geen verbinding maken met Nominatim (geocoding): ' . ($res['error'] ?? "HTTP {$res['status']}"));
    }
    $results = json_decode($res['body'], true);
    if (!is_array($results) || count($results) === 0) {
        throw new RuntimeException("Kon plaats '{$city}' niet vinden in Nederland");
    }
    $bbox = $results[0]['boundingbox']; // [south, north, west, east] as strings
    return [(float) $bbox[0], (float) $bbox[2], (float) $bbox[1], (float) $bbox[3]];
}

function build_overpass_query(array $bbox, array $tags): string
{
    [$south, $west, $north, $east] = $bbox;
    $bboxStr = "{$south},{$west},{$north},{$east}";
    $clauses = [];
    foreach ($tags as [$key, $value]) {
        $clauses[] = "node[\"{$key}\"=\"{$value}\"][\"website\"]({$bboxStr});";
        $clauses[] = "way[\"{$key}\"=\"{$value}\"][\"website\"]({$bboxStr});";
        $clauses[] = "node[\"{$key}\"=\"{$value}\"][\"contact:website\"]({$bboxStr});";
        $clauses[] = "way[\"{$key}\"=\"{$value}\"][\"contact:website\"]({$bboxStr});";
    }
    $body = implode("\n  ", $clauses);
    return "[out:json][timeout:60];\n(\n  {$body}\n);\nout center 100;";
}

function address_from_tags(array $tags): string
{
    $street = trim(($tags['addr:street'] ?? '') . ' ' . ($tags['addr:housenumber'] ?? ''));
    $line2 = trim(($tags['addr:postcode'] ?? '') . ' ' . ($tags['addr:city'] ?? ''));
    return implode(', ', array_filter([$street, $line2]));
}

/**
 * Returns a list of ['name','website','city','category','address','phone','source','source_id'].
 */
function discover_businesses(string $city, string $category, int $limit = 25): array
{
    $cats = categories();
    if (!isset($cats[$category])) {
        throw new InvalidArgumentException("Onbekende categorie '{$category}'");
    }

    $bbox = geocode_city($city);
    usleep(1_000_000); // be polite to Nominatim before hitting Overpass

    $query = build_overpass_query($bbox, $cats[$category]);
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => OVERPASS_URL,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['data' => $query]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_USERAGENT => crawler_user_agent(),
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status !== 200) {
        throw new RuntimeException('Overpass-query mislukt: ' . ($curlError ?: "HTTP {$status}"));
    }

    $data = json_decode($body, true);
    $elements = $data['elements'] ?? [];

    $results = [];
    $seen = [];
    foreach ($elements as $el) {
        $tags = $el['tags'] ?? [];
        $website = trim($tags['website'] ?? ($tags['contact:website'] ?? ''));
        $name = trim($tags['name'] ?? '');
        if ($website === '' || $name === '') {
            continue;
        }
        if (!preg_match('#^https?://#i', $website)) {
            $website = 'https://' . $website;
        }
        $normalized = strtolower(rtrim($website, '/'));
        if (isset($seen[$normalized])) {
            continue;
        }
        $seen[$normalized] = true;

        $results[] = [
            'name' => $name,
            'website' => $website,
            'city' => $city,
            'category' => $category,
            'address' => address_from_tags($tags),
            'phone' => $tags['phone'] ?? ($tags['contact:phone'] ?? ''),
            'source' => 'osm',
            'source_id' => ($el['type'] ?? '') . '/' . ($el['id'] ?? ''),
        ];
        if (count($results) >= $limit) {
            break;
        }
    }

    return $results;
}
