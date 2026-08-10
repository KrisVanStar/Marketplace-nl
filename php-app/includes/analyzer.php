<?php
declare(strict_types=1);

/**
 * Fetches a business website and extracts everything we can tell about
 * it from the homepage: security, mobile-friendliness, the CMS behind it,
 * SEO basics, contact details, social presence, technical age markers,
 * and how long ago the Wayback Machine last saw a change.
 *
 * Every individual extraction is defensive: a malformed page must never
 * abort a scan, it just leaves that one signal unknown.
 */

const WAYBACK_AVAILABLE_URL = 'https://archive.org/wayback/available';

function current_year(): int
{
    return (int) date('Y');
}

/** Very small robots.txt check: fetches robots.txt and looks for a
 *  Disallow rule under User-agent: * that matches the URL's path. Not a
 *  full RFC 9309 parser, but good enough to be a considerate crawler. */
function robots_allows(string $url): bool
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return true;
    }
    $robotsUrl = $parts['scheme'] . '://' . $parts['host'] . '/robots.txt';
    $res = http_get($robotsUrl, 8);
    if (!$res['ok'] || $res['status'] !== 200 || $res['body'] === '') {
        return true; // no readable robots.txt -> assume allowed
    }

    $path = $parts['path'] ?? '/';
    $lines = preg_split('/\r\n|\r|\n/', $res['body']);
    $applies = false;
    $disallowed = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) {
            $applies = trim($m[1]) === '*';
            continue;
        }
        if ($applies && preg_match('/^disallow:\s*(.*)$/i', $line, $m)) {
            $rule = trim($m[1]);
            if ($rule !== '') {
                $disallowed[] = $rule;
            }
        }
    }
    foreach ($disallowed as $rule) {
        if (str_starts_with($path, $rule)) {
            return false;
        }
    }
    return true;
}

function check_wayback(string $url): array
{
    $res = http_get(WAYBACK_AVAILABLE_URL . '?' . http_build_query(['url' => $url]), 10);
    if (!$res['ok'] || $res['status'] !== 200) {
        return [null, null];
    }
    $data = json_decode($res['body'], true);
    $snapshot = $data['archived_snapshots']['closest'] ?? null;
    if (!$snapshot || empty($snapshot['available'])) {
        return [null, null];
    }
    $ts = substr($snapshot['timestamp'], 0, 8);
    $snapDate = DateTime::createFromFormat('Ymd', $ts);
    if (!$snapDate) {
        return [null, null];
    }
    $years = (new DateTime())->diff($snapDate)->days / 365.25;
    return [$snapDate->format('Y-m-d'), round($years, 1)];
}

function check_pagespeed(string $url): ?int
{
    $key = app_config()['pagespeed_api_key'] ?? '';
    if ($key === '') {
        return null;
    }
    $params = http_build_query([
        'url' => $url,
        'strategy' => 'mobile',
        'category' => 'performance',
        'key' => $key,
    ]);
    $res = http_get('https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . $params, 45);
    if (!$res['ok'] || $res['status'] !== 200) {
        return null;
    }
    $data = json_decode($res['body'], true);
    $score = $data['lighthouseResult']['categories']['performance']['score'] ?? null;
    return $score === null ? null : (int) round($score * 100);
}

/** Which CMS / site builder is this? Returns [name, version|null]. */
function detect_platform(string $html, string $generator): array
{
    if (preg_match('/WordPress\s*([\d.]+)?/i', $generator, $m)) {
        return ['WordPress', $m[1] ?? null];
    }
    if (preg_match('/Joomla!?\s*([\d.]+)?/i', $generator, $m)) {
        return ['Joomla', $m[1] ?? null];
    }
    if (preg_match('/Drupal\s*([\d.]+)?/i', $generator, $m)) {
        return ['Drupal', $m[1] ?? null];
    }
    if (preg_match('/TYPO3\s*([\d.]+)?/i', $generator, $m)) {
        return ['TYPO3', $m[1] ?? null];
    }

    $patterns = [
        'WordPress' => '#/wp-content/|/wp-includes/#i',
        'Joomla' => '#/media/jui/|option=com_content#i',
        'Drupal' => '#/sites/default/files/|Drupal\.settings#i',
        'Wix' => '#static\.wixstatic\.com|wix\.com#i',
        'Squarespace' => '#squarespace\.com|static1\.squarespace#i',
        'Jimdo' => '#jimdo\.com|jimstatic\.com#i',
        'Shopify' => '#cdn\.shopify\.com#i',
        'Magento' => '#/skin/frontend/|Mage\.Cookies#i',
        'Weebly' => '#weebly\.com#i',
        'Webflow' => '#webflow\.com#i',
        'Google Sites' => '#sites\.google\.com#i',
    ];
    foreach ($patterns as $name => $pattern) {
        if (preg_match($pattern, $html)) {
            return [$name, null];
        }
    }
    return ['', null];
}

/** A WordPress/Joomla/Drupal major version that is clearly behind. */
function platform_is_outdated(string $platform, ?string $version): bool
{
    if ($version === null || $version === '') {
        return false;
    }
    $major = (int) explode('.', $version)[0];
    return match ($platform) {
        'WordPress' => $major < 6,
        'Joomla' => $major < 4,
        'Drupal' => $major < 10,
        'TYPO3' => $major < 11,
        default => false,
    };
}

function empty_signals(string $url): array
{
    return [
        'url' => $url,
        'final_url' => '',
        'reachable' => false,
        'error' => '',
        'status_code' => null,
        'response_time_ms' => null,
        'redirected' => false,
        'is_https' => false,
        'https_unavailable' => false,
        'mixed_content' => false,
        'has_viewport_meta' => false,
        'doctype_html5' => false,
        'lang_attribute' => '',
        'title' => '',
        'meta_description' => '',
        'h1_count' => 0,
        'generator' => '',
        'platform' => '',
        'platform_version' => null,
        'outdated_platform' => false,
        'jquery_version' => '',
        'outdated_jquery' => false,
        'uses_flash' => false,
        'heavy_table_layout' => false,
        'deprecated_tags' => [],
        'copyright_year' => null,
        'page_size_kb' => null,
        'image_count' => 0,
        'images_without_alt' => 0,
        'has_favicon' => false,
        'has_open_graph' => false,
        'has_structured_data' => false,
        'has_analytics' => false,
        'analytics_tools' => [],
        'has_contact_form' => false,
        'emails_found' => [],
        'phones_found' => [],
        'social_links' => [],
        'has_map_embed' => false,
        'wayback_last_snapshot' => null,
        'wayback_years_stale' => null,
        'pagespeed_mobile_score' => null,
        'robots_disallowed' => false,
    ];
}

/**
 * Returns an associative array of signals for the given URL.
 * Shape mirrors the field names used by scoring.php.
 */
function analyze_website(string $url): array
{
    $signals = empty_signals($url);

    if (!robots_allows($url)) {
        $signals['robots_disallowed'] = true;
        $signals['error'] = 'De website verbiedt geautomatiseerd bezoek via robots.txt; niet geanalyseerd.';
        return $signals;
    }

    $res = http_get($url, 15);

    // OSM website tags often have no scheme, so we guess https:// — but plenty
    // of small-business sites are still http-only. Rather than writing those
    // off as unreachable, retry over http before giving up.
    if (!$res['ok'] && stripos($url, 'https://') === 0) {
        $httpRes = http_get('http://' . substr($url, 8), 15);
        if ($httpRes['ok']) {
            $res = $httpRes;
            $signals['https_unavailable'] = true;
        }
    }

    $signals['response_time_ms'] = $res['elapsed_ms'];

    if (!$res['ok']) {
        $signals['error'] = $res['error'] ?: 'Website niet bereikbaar';
        return $signals;
    }

    $signals['reachable'] = true;
    $signals['status_code'] = $res['status'];
    $signals['final_url'] = $res['final_url'];
    $signals['redirected'] = rtrim($res['final_url'], '/') !== rtrim($url, '/');
    $signals['is_https'] = str_starts_with(strtolower($res['final_url']), 'https://');

    if ($res['status'] >= 400 || $res['body'] === '') {
        $signals['error'] = "De website gaf foutcode HTTP {$res['status']}";
        return $signals;
    }

    $html = $res['body'];
    $signals['page_size_kb'] = (int) round(strlen($html) / 1024);

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    $metaContent = function (string $name) use ($xpath): string {
        $nodes = $xpath->query(
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="' . $name . '"]/@content'
        );
        return ($nodes && $nodes->length > 0) ? trim($nodes->item(0)->nodeValue) : '';
    };

    // --- Mobile friendliness -------------------------------------------
    $signals['has_viewport_meta'] = $metaContent('viewport') !== '';

    // --- Standards / markup age ----------------------------------------
    $signals['doctype_html5'] = (bool) preg_match('/^\s*<!doctype\s+html>/i', $html);

    $langNodes = $xpath->query('//html/@lang');
    $signals['lang_attribute'] = ($langNodes && $langNodes->length > 0) ? trim($langNodes->item(0)->nodeValue) : '';

    foreach (['font', 'center', 'marquee', 'blink', 'frameset', 'frame', 'applet'] as $tag) {
        if ($xpath->query("//{$tag}")->length > 0) {
            $signals['deprecated_tags'][] = $tag;
        }
    }

    $tableCount = $xpath->query('//table')->length;
    $nestedTableCount = $xpath->query('//table//table')->length;
    $signals['heavy_table_layout'] = $tableCount >= 2 && $nestedTableCount >= 1;

    // --- SEO basics ------------------------------------------------------
    $titleNodes = $xpath->query('//title');
    $signals['title'] = ($titleNodes && $titleNodes->length > 0) ? trim($titleNodes->item(0)->textContent) : '';
    $signals['meta_description'] = $metaContent('description');
    $signals['h1_count'] = $xpath->query('//h1')->length;
    $signals['has_open_graph'] = $xpath->query('//meta[starts-with(@property,"og:")]')->length > 0;
    $signals['has_structured_data'] = (bool) preg_match('#application/ld\+json|itemscope#i', $html);
    $signals['has_favicon'] = $xpath->query(
        '//link[contains(translate(@rel,"ICON","icon"),"icon")]'
    )->length > 0;

    // --- Platform / libraries -------------------------------------------
    $signals['generator'] = $metaContent('generator');
    [$platform, $version] = detect_platform($html, $signals['generator']);
    $signals['platform'] = $platform;
    $signals['platform_version'] = $version;
    $signals['outdated_platform'] = platform_is_outdated($platform, $version);

    if (preg_match('#jquery[/-](\d+)\.(\d+)\.(\d+)#i', $html, $m)) {
        $signals['jquery_version'] = "{$m[1]}.{$m[2]}.{$m[3]}";
        $signals['outdated_jquery'] = ((int) $m[1] < 3) || ((int) $m[1] === 3 && (int) $m[2] < 6);
    }

    $hasFlashEmbed = $xpath->query('//embed[contains(translate(@type,"SHOCKWAVE","shockwave"),"shockwave")]');
    $signals['uses_flash'] = (bool) preg_match('/d27cdb6e|\.swf\b/i', $html) || $hasFlashEmbed->length > 0;

    // --- Images / accessibility -----------------------------------------
    $images = $xpath->query('//img');
    $signals['image_count'] = $images->length;
    $withoutAlt = 0;
    foreach ($images as $img) {
        /** @var DOMElement $img */
        if (!$img->hasAttribute('alt') || trim($img->getAttribute('alt')) === '') {
            $withoutAlt++;
        }
    }
    $signals['images_without_alt'] = $withoutAlt;

    // --- Security --------------------------------------------------------
    if ($signals['is_https']) {
        $signals['mixed_content'] = (bool) preg_match('#(?:src|href)\s*=\s*["\']http://#i', $html);
    }

    // --- Marketing / conversion -----------------------------------------
    $analytics = [];
    if (preg_match('#googletagmanager\.com|gtag\(|google-analytics\.com|ga\.js#i', $html)) {
        $analytics[] = 'Google Analytics';
    }
    if (preg_match('#matomo|piwik#i', $html)) {
        $analytics[] = 'Matomo';
    }
    if (preg_match('#plausible\.io#i', $html)) {
        $analytics[] = 'Plausible';
    }
    if (preg_match('#hotjar#i', $html)) {
        $analytics[] = 'Hotjar';
    }
    $signals['analytics_tools'] = $analytics;
    $signals['has_analytics'] = !empty($analytics);

    $signals['has_contact_form'] = $xpath->query('//form')->length > 0
        || (bool) preg_match('#contactform|wpcf7|formulier#i', $html);

    $signals['has_map_embed'] = (bool) preg_match('#google\.com/maps|openstreetmap\.org/export|maps\.google#i', $html);

    // --- Contact details we can hand to the salesperson -------------------
    if (preg_match_all('/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/i', $html, $m)) {
        $emails = array_values(array_unique(array_filter(
            $m[0],
            fn($e) => !preg_match('#\.(png|jpg|jpeg|gif|webp|svg|css|js)$#i', $e)
        )));
        $signals['emails_found'] = array_slice($emails, 0, 5);
    }
    if (preg_match_all('#(?:\+31[\s-]?|0)(?:\d[\s-]?){8,10}\d#', strip_tags($html), $m)) {
        $phones = array_values(array_unique(array_map(fn($p) => trim($p), $m[0])));
        $signals['phones_found'] = array_slice($phones, 0, 5);
    }

    $socials = [];
    foreach ([
        'Facebook' => '#facebook\.com/[A-Za-z0-9._-]+#i',
        'Instagram' => '#instagram\.com/[A-Za-z0-9._-]+#i',
        'LinkedIn' => '#linkedin\.com/[A-Za-z0-9._/-]+#i',
        'X (Twitter)' => '#(?:twitter|x)\.com/[A-Za-z0-9._-]+#i',
        'YouTube' => '#youtube\.com/[A-Za-z0-9._@/-]+#i',
    ] as $network => $pattern) {
        if (preg_match($pattern, $html, $m)) {
            $socials[$network] = $m[0];
        }
    }
    $signals['social_links'] = $socials;

    // --- Age / freshness --------------------------------------------------
    if (preg_match_all('/(?:©|&copy;|\bcopyright\b)[^0-9]{0,12}((?:19|20)\d{2})/i', $html, $matches)) {
        $years = array_filter(array_map('intval', $matches[1]), fn($y) => $y <= current_year());
        if (!empty($years)) {
            $signals['copyright_year'] = max($years);
        }
    }

    [$signals['wayback_last_snapshot'], $signals['wayback_years_stale']] = check_wayback($res['final_url']);
    $signals['pagespeed_mobile_score'] = check_pagespeed($res['final_url']);

    return $signals;
}
