"""Turns raw SiteSignals into a 0-100 "needs a new website" score plus a
human-readable list of reasons, so a person can quickly judge each lead
instead of trusting a black-box number.
"""
from __future__ import annotations

from dataclasses import dataclass

from app.analyzer import CURRENT_YEAR, SiteSignals


@dataclass
class ScoreResult:
    score: int
    priority: str  # high/medium/low
    reasons: list[str]


def score_site(signals: SiteSignals) -> ScoreResult:
    if not signals.reachable:
        reason = signals.error or "Website unreachable"
        return ScoreResult(score=0, priority="unreachable", reasons=[reason])

    points = 0
    reasons: list[str] = []

    if not signals.is_https:
        points += 25
        reasons.append("Geen HTTPS (onveilige verbinding)")

    if not signals.has_viewport_meta:
        points += 20
        reasons.append("Niet mobielvriendelijk (geen viewport/responsive meta-tag)")

    if signals.uses_flash:
        points += 20
        reasons.append("Gebruikt Adobe Flash (werkt niet meer in moderne browsers)")

    if signals.heavy_table_layout:
        points += 10
        reasons.append("Oude table-based layout in plaats van moderne CSS")

    if not signals.doctype_html5:
        points += 5
        reasons.append("Geen HTML5 doctype")

    if signals.outdated_wordpress:
        points += 15
        reasons.append(f"Verouderde WordPress-versie ({signals.generator})")

    if signals.outdated_jquery:
        points += 10
        reasons.append(f"Verouderde jQuery-versie ({signals.jquery_version})")

    if signals.copyright_year is not None:
        age = CURRENT_YEAR - signals.copyright_year
        if age >= 2:
            add = min(15, 5 * age)
            points += add
            reasons.append(f"Copyright-jaar in footer is {signals.copyright_year} ({age} jaar oud)")

    if signals.response_time_ms is not None and signals.response_time_ms > 3000:
        points += 10
        reasons.append(f"Trage laadtijd ({signals.response_time_ms} ms)")

    if signals.wayback_years_stale is not None and signals.wayback_years_stale >= 3:
        points += 10
        reasons.append(
            f"Laatst gearchiveerd door Wayback Machine {signals.wayback_years_stale} jaar geleden"
        )

    if signals.pagespeed_mobile_score is not None and signals.pagespeed_mobile_score < 50:
        points += 15
        reasons.append(f"Lage Google PageSpeed mobile-score ({signals.pagespeed_mobile_score}/100)")

    score = max(0, min(100, points))

    if score >= 55:
        priority = "high"
    elif score >= 30:
        priority = "medium"
    else:
        priority = "low"

    if not reasons:
        reasons.append("Geen duidelijke verouderingssignalen gevonden")

    return ScoreResult(score=score, priority=priority, reasons=reasons)
