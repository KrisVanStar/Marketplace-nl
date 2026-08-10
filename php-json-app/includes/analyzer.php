<?php
declare(strict_types=1);

/**
 * Fetches a business website and extracts signals that indicate it is
 * outdated / due for a redesign: missing HTTPS, no responsive/mobile
 * viewport, old CMS or jQuery versions, Flash content, table-based
 * layouts, a stale copyright year, slow response time, and (if archive.org
 * has data) a long gap since the site was last crawled.
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
    $res = http_get('https://www.googleapis.com/pagespeedonline/v5/runPagespeed?' . $params, 30);
    if (!$res['ok'] || $res['status'] !== 200) {
        return null;
    }
    $data = json_decode($res['body'], true);
    $score = $data['lighthouseResult']['categories']['performance']['score'] ?? null;
    return $score === null ? null : (int) round($score * 100);
}

/**
 * Returns an associative array of signals for the given URL. Shape mirrors
 * the field names used by scoring.php.
 */
function analyze_website(string $url): array
{
    $signals = [
        'url' => $url,
        'final_url' => '',
        'reachable' => false,
        'error' => '',
        'status_code' => null,
        'response_time_ms' => null,
        'is_https' => false,
        'has_viewport_meta' => false,
        'doctype_html5' => false,
        'generator' => '',
        'outdated_wordpress' => false,
        'jquery_version' => '',
        'outdated_jquery' => false,
        'uses_flash' => false,
        'heavy_table_layout' => false,
        'copyright_year' => null,
        'wayback_last_snapshot' => null,
        'wayback_years_stale' => null,
        'pagespeed_mobile_score' => null,
        'robots_disallowed' => false,
    ];

    if (!robots_allows($url)) {
        $signals['robots_disallowed'] = true;
        $signals['error'] = 'Disallowed by robots.txt';
        return $signals;
    }

    $res = http_get($url, 10);
    $signals['response_time_ms'] = $res['elapsed_ms'];

    if (!$res['ok']) {
        $signals['error'] = $res['error'] ?: 'Request failed';
        return $signals;
    }

    $signals['reachable'] = true;
    $signals['status_code'] = $res['status'];
    $signals['final_url'] = $res['final_url'];
    $signals['is_https'] = str_starts_with(strtolower($res['final_url']), 'https://');

    if ($res['status'] >= 400 || $res['body'] === '') {
        $signals['error'] = "HTTP {$res['status']}";
        return $signals;
    }

    $html = $res['body'];

    // --- HTML parsing (DOM, with regex fallbacks for malformed markup) ---
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
    libxml_clear_errors();
    $xpath = new DOMXPath($doc);

    $viewportNodes = $xpath->query('//meta[translate(@name,"VIEWPORT","viewport")="viewport"]');
    $signals['has_viewport_meta'] = $viewportNodes !== false && $viewportNodes->length > 0;

    $signals['doctype_html5'] = (bool) preg_match('/^\s*<!doctype\s+html>/i', $html);

    $generatorNodes = $xpath->query('//meta[translate(@name,"GENERATOR","generator")="generator"]/@content');
    if ($generatorNodes !== false && $generatorNodes->length > 0) {
        $generator = trim($generatorNodes->item(0)->nodeValue);
        $signals['generator'] = $generator;
        if (preg_match('/WordPress\s+([\d.]+)/i', $generator, $m)) {
            $major = (int) explode('.', $m[1])[0];
            $signals['outdated_wordpress'] = $major < 6;
        }
    }

    if (preg_match('#jquery[/-](\d+)\.(\d+)\.(\d+)#i', $html, $m)) {
        $signals['jquery_version'] = "{$m[1]}.{$m[2]}.{$m[3]}";
        $signals['outdated_jquery'] = ((int) $m[1] < 3) || ((int) $m[1] === 3 && (int) $m[2] < 6);
    }

    $hasFlashEmbed = $xpath->query('//embed[contains(translate(@type,"SHOCKWAVE","shockwave"),"shockwave")]');
    $signals['uses_flash'] = (bool) preg_match('/d27cdb6e/i', $html) || ($hasFlashEmbed !== false && $hasFlashEmbed->length > 0);

    $tableCount = $xpath->query('//table')->length;
    $nestedTableCount = $xpath->query('//table//table')->length;
    $signals['heavy_table_layout'] = $tableCount >= 2 && $nestedTableCount >= 1;

    if (preg_match_all('/(?:©|&copy;|\bcopyright\b)[^0-9]{0,12}((?:19|20)\d{2})/i', $html, $matches)) {
        $years = array_map('intval', $matches[1]);
        $years = array_filter($years, fn($y) => $y <= current_year());
        if (!empty($years)) {
            $signals['copyright_year'] = max($years);
        }
    }

    [$signals['wayback_last_snapshot'], $signals['wayback_years_stale']] = check_wayback($res['final_url']);
    $signals['pagespeed_mobile_score'] = check_pagespeed($res['final_url']);

    return $signals;
}
