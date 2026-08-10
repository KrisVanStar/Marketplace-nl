<?php
declare(strict_types=1);

/**
 * Finds small Dutch businesses via OpenStreetMap.
 * Nominatim geocodes a city name to a bounding box; Overpass API then
 * queries OSM for businesses inside that box.
 *
 * Note: we deliberately do NOT require a `website` tag. Most small
 * businesses in OSM have no website tag at all, and a business with no
 * website is the single best lead there is — so those are kept and
 * flagged instead of filtered away. (Requiring the tag was why searches
 * so often came back empty.)
 */

const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';

/** Public Overpass instances, tried in order until one answers. */
function overpass_endpoints(): array
{
    $configured = app_config()['overpass_endpoints'] ?? [];
    if (!empty($configured) && is_array($configured)) {
        return $configured;
    }
    return [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.kumi.systems/api/interpreter',
        'https://overpass.osm.ch/api/interpreter',
    ];
}

function nominatim_url(): string
{
    return app_config()['nominatim_url'] ?? NOMINATIM_URL;
}

/**
 * category slug => ['label' => Dutch label, 'filters' => [Overpass tag selectors]]
 * Multiple selectors are OR-ed together. Selectors are raw Overpass
 * filters so a category can match a whole key (e.g. any ["craft"]).
 */
function categories(): array
{
    return [
        'all' => [
            'label' => 'Alle bedrijven (breedste zoekopdracht)',
            'filters' => [
                '["shop"]',
                '["craft"]',
                '["office"]',
                '["healthcare"]',
                '["amenity"~"^(restaurant|cafe|bar|pub|fast_food|dentist|doctors|veterinary|pharmacy|driving_school|childcare)$"]',
                '["leisure"~"^(fitness_centre|sports_centre|dance)$"]',
                '["tourism"~"^(hotel|guest_house|bed_and_breakfast|apartment)$"]',
            ],
        ],
        'restaurant' => ['label' => 'Restaurant', 'filters' => ['["amenity"="restaurant"]']],
        'cafe' => ['label' => 'Café / lunchroom', 'filters' => ['["amenity"="cafe"]']],
        'bar_pub' => ['label' => 'Bar / café (kroeg)', 'filters' => ['["amenity"="bar"]', '["amenity"="pub"]']],
        'fast_food' => ['label' => 'Snackbar / afhaal', 'filters' => ['["amenity"="fast_food"]']],
        'hotel_bnb' => [
            'label' => 'Hotel / B&B',
            'filters' => ['["tourism"~"^(hotel|guest_house|bed_and_breakfast|apartment)$"]'],
        ],
        'hairdresser' => ['label' => 'Kapper', 'filters' => ['["shop"="hairdresser"]']],
        'beauty_salon' => [
            'label' => 'Schoonheidssalon / nagelstudio',
            'filters' => ['["shop"="beauty"]', '["shop"="nails"]', '["shop"="massage"]'],
        ],
        'bakery' => ['label' => 'Bakkerij', 'filters' => ['["shop"="bakery"]', '["shop"="pastry"]']],
        'butcher' => ['label' => 'Slagerij', 'filters' => ['["shop"="butcher"]']],
        'florist' => ['label' => 'Bloemist', 'filters' => ['["shop"="florist"]']],
        'garage_car_repair' => [
            'label' => 'Autogarage / autobedrijf',
            'filters' => ['["shop"="car_repair"]', '["shop"="car"]', '["shop"="tyres"]'],
        ],
        'bicycle_shop' => ['label' => 'Fietsenmaker', 'filters' => ['["shop"="bicycle"]']],
        'plumber' => ['label' => 'Loodgieter / installateur', 'filters' => ['["craft"="plumber"]', '["craft"="hvac"]']],
        'electrician' => ['label' => 'Elektricien', 'filters' => ['["craft"="electrician"]']],
        'carpenter' => [
            'label' => 'Timmerman / aannemer',
            'filters' => ['["craft"="carpenter"]', '["craft"="builder"]', '["craft"="joiner"]'],
        ],
        'painter' => ['label' => 'Schilder / stukadoor', 'filters' => ['["craft"="painter"]', '["craft"="plasterer"]']],
        'gardener' => ['label' => 'Hovenier', 'filters' => ['["craft"="gardener"]', '["shop"="garden_centre"]']],
        'cleaning' => ['label' => 'Schoonmaakbedrijf', 'filters' => ['["craft"="cleaning"]', '["shop"="laundry"]', '["shop"="dry_cleaning"]']],
        'dentist' => ['label' => 'Tandarts', 'filters' => ['["amenity"="dentist"]', '["healthcare"="dentist"]']],
        'doctor' => ['label' => 'Huisarts', 'filters' => ['["amenity"="doctors"]', '["healthcare"="doctor"]']],
        'physiotherapist' => ['label' => 'Fysiotherapeut', 'filters' => ['["healthcare"="physiotherapist"]']],
        'veterinary' => ['label' => 'Dierenarts', 'filters' => ['["amenity"="veterinary"]']],
        'law_office' => ['label' => 'Advocaat / notaris', 'filters' => ['["office"="lawyer"]', '["office"="notary"]']],
        'accountant' => [
            'label' => 'Accountant / administratiekantoor',
            'filters' => ['["office"="accountant"]', '["office"="tax_advisor"]', '["office"="financial"]'],
        ],
        'real_estate' => ['label' => 'Makelaar', 'filters' => ['["office"="estate_agent"]']],
        'architect' => ['label' => 'Architect', 'filters' => ['["office"="architect"]']],
        'insurance' => ['label' => 'Verzekeringen / financieel advies', 'filters' => ['["office"="insurance"]']],
        'gym' => ['label' => 'Sportschool', 'filters' => ['["leisure"="fitness_centre"]', '["leisure"="sports_centre"]']],
        'clothing_store' => ['label' => 'Kledingwinkel', 'filters' => ['["shop"="clothes"]', '["shop"="shoes"]']],
        'furniture_store' => ['label' => 'Meubelzaak / interieur', 'filters' => ['["shop"="furniture"]', '["shop"="interior_decoration"]']],
        'jewelry_optician' => ['label' => 'Juwelier / opticien', 'filters' => ['["shop"="jewelry"]', '["shop"="optician"]']],
        'pet_shop' => ['label' => 'Dierenwinkel', 'filters' => ['["shop"="pet"]', '["shop"="pet_grooming"]']],
        'driving_school' => ['label' => 'Rijschool', 'filters' => ['["amenity"="driving_school"]']],
        'childcare' => ['label' => 'Kinderopvang', 'filters' => ['["amenity"="childcare"]', '["amenity"="kindergarten"]']],
    ];
}

/** Categories in the shape the frontend dropdown wants. */
function categories_for_ui(): array
{
    $out = [];
    foreach (categories() as $slug => $cat) {
        $out[] = ['value' => $slug, 'label' => $cat['label']];
    }
    return $out;
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
    $res = http_get(nominatim_url() . '?' . $params, 15);
    if (!$res['ok'] || $res['status'] !== 200) {
        throw new RuntimeException(
            'Kon geen verbinding maken met de OpenStreetMap-zoekdienst (Nominatim): '
            . ($res['error'] ?: "HTTP {$res['status']}")
            . '. Controleer of je hosting uitgaande internetverbindingen toestaat.'
        );
    }
    $results = json_decode($res['body'], true);
    if (!is_array($results) || count($results) === 0) {
        throw new RuntimeException(
            "Plaats '{$city}' niet gevonden in Nederland. Controleer de spelling "
            . '(gebruik de officiële plaatsnaam, bijvoorbeeld "Den Bosch" of "s-Hertogenbosch").'
        );
    }
    $bbox = $results[0]['boundingbox']; // [south, north, west, east] as strings
    return [(float) $bbox[0], (float) $bbox[2], (float) $bbox[1], (float) $bbox[3]];
}

function build_overpass_query(array $bbox, array $filters, int $limit): string
{
    [$south, $west, $north, $east] = $bbox;
    $bboxStr = "{$south},{$west},{$north},{$east}";
    $clauses = [];
    foreach ($filters as $filter) {
        // nwr = node/way/relation in one go.
        $clauses[] = "nwr{$filter}({$bboxStr});";
    }
    $body = implode("\n  ", $clauses);
    $outLimit = max(50, $limit * 4); // over-fetch: many results get filtered out below
    return "[out:json][timeout:90];\n(\n  {$body}\n);\nout center {$outLimit};";
}

/** Queries Overpass, trying each mirror until one succeeds. */
function overpass_query(string $query): array
{
    $errors = [];
    foreach (overpass_endpoints() as $endpoint) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $endpoint,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['data' => $query]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => crawler_user_agent(),
        ]);
        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($body !== false && $status === 200) {
            $data = json_decode($body, true);
            if (is_array($data) && isset($data['elements'])) {
                return $data['elements'];
            }
            $errors[] = "{$endpoint}: ongeldig antwoord";
            continue;
        }
        $errors[] = "{$endpoint}: " . ($curlError ?: "HTTP {$status}");
    }

    throw new RuntimeException(
        'Alle OpenStreetMap-servers (Overpass) gaven een fout. Dit is meestal tijdelijk — '
        . 'probeer het over een paar minuten opnieuw. Details: ' . implode(' | ', $errors)
    );
}

/** Street + house number only; postcode and city are stored separately. */
function address_from_tags(array $tags): string
{
    return trim(($tags['addr:street'] ?? '') . ' ' . ($tags['addr:housenumber'] ?? ''));
}

function normalize_website(string $website): string
{
    $website = trim($website);
    if ($website === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $website)) {
        $website = 'https://' . ltrim($website, '/');
    }
    return $website;
}

/** Best human-readable description of what the OSM tags say this business is. */
function business_type_from_tags(array $tags): string
{
    foreach (['shop', 'craft', 'office', 'amenity', 'healthcare', 'leisure', 'tourism'] as $key) {
        if (!empty($tags[$key]) && $tags[$key] !== 'yes') {
            return str_replace('_', ' ', $tags[$key]);
        }
    }
    return '';
}

/**
 * Returns a list of business arrays ready for storage.
 * $onlyWithoutWebsite: when true, only businesses that have no website
 * at all are returned (the highest-value leads).
 */
function discover_businesses(string $city, string $category, int $limit = 25, bool $onlyWithoutWebsite = false): array
{
    $cats = categories();
    if (!isset($cats[$category])) {
        throw new InvalidArgumentException("Onbekende categorie '{$category}'");
    }

    $bbox = geocode_city($city);
    usleep(1_000_000); // be polite to Nominatim before hitting Overpass

    $elements = overpass_query(build_overpass_query($bbox, $cats[$category]['filters'], $limit));

    $results = [];
    $seenWebsites = [];
    $seenNames = [];
    foreach ($elements as $el) {
        $tags = $el['tags'] ?? [];
        $name = trim($tags['name'] ?? '');
        if ($name === '') {
            continue; // unnamed POIs are useless as leads
        }

        $website = normalize_website($tags['website'] ?? ($tags['contact:website'] ?? ($tags['url'] ?? '')));
        $hasWebsite = $website !== '';

        if ($onlyWithoutWebsite && $hasWebsite) {
            continue;
        }

        // De-duplicate: by website when there is one, otherwise by name+street.
        if ($hasWebsite) {
            $key = strtolower(rtrim($website, '/'));
            if (isset($seenWebsites[$key])) {
                continue;
            }
            $seenWebsites[$key] = true;
        } else {
            $key = strtolower($name . '|' . ($tags['addr:street'] ?? '') . ($tags['addr:housenumber'] ?? ''));
            if (isset($seenNames[$key])) {
                continue;
            }
            $seenNames[$key] = true;
        }

        $results[] = [
            'name' => $name,
            'website' => $website,
            'has_website' => $hasWebsite,
            'city' => trim($tags['addr:city'] ?? '') !== '' ? $tags['addr:city'] : $city,
            'category' => $category,
            'business_type' => business_type_from_tags($tags),
            'address' => address_from_tags($tags),
            'postcode' => $tags['addr:postcode'] ?? '',
            'phone' => $tags['phone'] ?? ($tags['contact:phone'] ?? ($tags['contact:mobile'] ?? '')),
            'email' => $tags['email'] ?? ($tags['contact:email'] ?? ''),
            'opening_hours' => $tags['opening_hours'] ?? '',
            'facebook' => $tags['contact:facebook'] ?? ($tags['facebook'] ?? ''),
            'instagram' => $tags['contact:instagram'] ?? ($tags['instagram'] ?? ''),
            'lat' => $el['lat'] ?? ($el['center']['lat'] ?? null),
            'lon' => $el['lon'] ?? ($el['center']['lon'] ?? null),
            'source' => 'osm',
            'source_id' => ($el['type'] ?? '') . '/' . ($el['id'] ?? ''),
        ];

        if (count($results) >= $limit) {
            break;
        }
    }

    // Businesses without a website first — they are the strongest leads.
    usort($results, fn($a, $b) => ($a['has_website'] ? 1 : 0) <=> ($b['has_website'] ? 1 : 0));

    return $results;
}
