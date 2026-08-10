<?php
declare(strict_types=1);

/**
 * Turns raw signals (see analyzer.php) into a 0-100 "needs a new website"
 * score plus a human-readable list of reasons, so a person can quickly
 * judge each lead instead of trusting a black-box number.
 *
 * Returns ['score' => int, 'priority' => string, 'reasons' => string[]].
 */
function score_site(array $signals): array
{
    if (empty($signals['reachable'])) {
        $reason = $signals['error'] !== '' ? $signals['error'] : 'Website unreachable';
        return ['score' => 0, 'priority' => 'unreachable', 'reasons' => [$reason]];
    }

    $points = 0;
    $reasons = [];

    if (empty($signals['is_https'])) {
        $points += 25;
        $reasons[] = 'Geen HTTPS (onveilige verbinding)';
    }

    if (empty($signals['has_viewport_meta'])) {
        $points += 20;
        $reasons[] = 'Niet mobielvriendelijk (geen viewport/responsive meta-tag)';
    }

    if (!empty($signals['uses_flash'])) {
        $points += 20;
        $reasons[] = 'Gebruikt Adobe Flash (werkt niet meer in moderne browsers)';
    }

    if (!empty($signals['heavy_table_layout'])) {
        $points += 10;
        $reasons[] = 'Oude table-based layout in plaats van moderne CSS';
    }

    if (empty($signals['doctype_html5'])) {
        $points += 5;
        $reasons[] = 'Geen HTML5 doctype';
    }

    if (!empty($signals['outdated_wordpress'])) {
        $points += 15;
        $reasons[] = "Verouderde WordPress-versie ({$signals['generator']})";
    }

    if (!empty($signals['outdated_jquery'])) {
        $points += 10;
        $reasons[] = "Verouderde jQuery-versie ({$signals['jquery_version']})";
    }

    if ($signals['copyright_year'] !== null) {
        $age = current_year() - (int) $signals['copyright_year'];
        if ($age >= 2) {
            $points += min(15, 5 * $age);
            $reasons[] = "Copyright-jaar in footer is {$signals['copyright_year']} ({$age} jaar oud)";
        }
    }

    if ($signals['response_time_ms'] !== null && $signals['response_time_ms'] > 3000) {
        $points += 10;
        $reasons[] = "Trage laadtijd ({$signals['response_time_ms']} ms)";
    }

    if ($signals['wayback_years_stale'] !== null && $signals['wayback_years_stale'] >= 3) {
        $points += 10;
        $reasons[] = "Laatst gearchiveerd door Wayback Machine {$signals['wayback_years_stale']} jaar geleden";
    }

    if ($signals['pagespeed_mobile_score'] !== null && $signals['pagespeed_mobile_score'] < 50) {
        $points += 15;
        $reasons[] = "Lage Google PageSpeed mobile-score ({$signals['pagespeed_mobile_score']}/100)";
    }

    $score = max(0, min(100, $points));

    if ($score >= 55) {
        $priority = 'high';
    } elseif ($score >= 30) {
        $priority = 'medium';
    } else {
        $priority = 'low';
    }

    if (empty($reasons)) {
        $reasons[] = 'Geen duidelijke verouderingssignalen gevonden';
    }

    return ['score' => $score, 'priority' => $priority, 'reasons' => $reasons];
}
