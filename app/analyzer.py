"""Fetches a business website and extracts signals that indicate it is
outdated / due for a redesign: missing HTTPS, no responsive/mobile viewport,
old CMS or jQuery versions, Flash content, table-based layouts, a stale
copyright year, slow response time, and (if archive.org has data) a long
gap since the site was last crawled.
"""
from __future__ import annotations

import datetime
import re
import time
import urllib.robotparser
from dataclasses import dataclass, field
from urllib.parse import urlparse

import requests
from bs4 import BeautifulSoup

from app.config import (
    PAGESPEED_API_KEY,
    REQUEST_TIMEOUT_SECONDS,
    USER_AGENT,
    WAYBACK_AVAILABLE_URL,
)

CURRENT_YEAR = datetime.date.today().year

OLD_JQUERY_RE = re.compile(r"jquery[/-](\d+)\.(\d+)\.(\d+)", re.IGNORECASE)
GENERATOR_WP_RE = re.compile(r"WordPress\s+([\d.]+)", re.IGNORECASE)
COPYRIGHT_YEAR_RE = re.compile(r"(?:©|&copy;|\bcopyright\b)[^0-9]{0,12}((?:19|20)\d{2})", re.IGNORECASE)
FLASH_CLASSID_RE = re.compile(r"d27cdb6e", re.IGNORECASE)


@dataclass
class SiteSignals:
    url: str
    final_url: str = ""
    reachable: bool = False
    error: str = ""
    status_code: int | None = None
    response_time_ms: int | None = None
    is_https: bool = False
    has_viewport_meta: bool = False
    doctype_html5: bool = False
    generator: str = ""
    outdated_wordpress: bool = False
    jquery_version: str = ""
    outdated_jquery: bool = False
    uses_flash: bool = False
    heavy_table_layout: bool = False
    copyright_year: int | None = None
    wayback_last_snapshot: str | None = None
    wayback_years_stale: float | None = None
    pagespeed_mobile_score: int | None = None
    robots_disallowed: bool = False


def _robots_allows(url: str) -> bool:
    parsed = urlparse(url)
    robots_url = f"{parsed.scheme}://{parsed.netloc}/robots.txt"
    rp = urllib.robotparser.RobotFileParser()
    rp.set_url(robots_url)
    try:
        rp.read()
    except Exception:
        # No readable robots.txt -> assume allowed.
        return True
    try:
        return rp.can_fetch(USER_AGENT, url)
    except Exception:
        return True


def _check_wayback(url: str) -> tuple[str | None, float | None]:
    try:
        resp = requests.get(
            WAYBACK_AVAILABLE_URL,
            params={"url": url},
            headers={"User-Agent": USER_AGENT},
            timeout=REQUEST_TIMEOUT_SECONDS,
        )
        resp.raise_for_status()
        snapshot = resp.json().get("archived_snapshots", {}).get("closest")
        if not snapshot or not snapshot.get("available"):
            return None, None
        ts = snapshot["timestamp"]  # e.g. 20230114120000
        snap_date = datetime.datetime.strptime(ts[:8], "%Y%m%d").date()
        years_stale = (datetime.date.today() - snap_date).days / 365.25
        return snap_date.isoformat(), round(years_stale, 1)
    except Exception:
        return None, None


def _check_pagespeed(url: str) -> int | None:
    if not PAGESPEED_API_KEY:
        return None
    try:
        resp = requests.get(
            "https://www.googleapis.com/pagespeedonline/v5/runPagespeed",
            params={
                "url": url,
                "strategy": "mobile",
                "category": "performance",
                "key": PAGESPEED_API_KEY,
            },
            timeout=30,
        )
        resp.raise_for_status()
        data = resp.json()
        score = data["lighthouseResult"]["categories"]["performance"]["score"]
        return round(score * 100)
    except Exception:
        return None


def analyze_website(url: str) -> SiteSignals:
    signals = SiteSignals(url=url)

    if not _robots_allows(url):
        signals.robots_disallowed = True
        signals.error = "Disallowed by robots.txt"
        return signals

    start = time.monotonic()
    try:
        resp = requests.get(
            url,
            headers={"User-Agent": USER_AGENT},
            timeout=REQUEST_TIMEOUT_SECONDS,
            allow_redirects=True,
        )
        elapsed_ms = int((time.monotonic() - start) * 1000)
    except requests.exceptions.SSLError as exc:
        signals.error = f"SSL error: {exc}"
        signals.is_https = False
        return signals
    except requests.exceptions.RequestException as exc:
        signals.error = str(exc)
        return signals

    signals.reachable = True
    signals.status_code = resp.status_code
    signals.final_url = resp.url
    signals.response_time_ms = elapsed_ms
    signals.is_https = urlparse(resp.url).scheme == "https"

    if resp.status_code >= 400 or not resp.text:
        signals.error = f"HTTP {resp.status_code}"
        return signals

    html = resp.text
    soup = BeautifulSoup(html, "lxml")

    signals.has_viewport_meta = soup.find("meta", attrs={"name": "viewport"}) is not None

    doctype_match = re.match(r"\s*<!doctype\s+html>", html, re.IGNORECASE)
    signals.doctype_html5 = bool(doctype_match)

    generator_tag = soup.find("meta", attrs={"name": "generator"})
    if generator_tag and generator_tag.get("content"):
        signals.generator = generator_tag["content"]
        wp_match = GENERATOR_WP_RE.search(signals.generator)
        if wp_match:
            major = int(wp_match.group(1).split(".")[0])
            signals.outdated_wordpress = major < 6

    jquery_match = OLD_JQUERY_RE.search(html)
    if jquery_match:
        version = ".".join(jquery_match.groups())
        signals.jquery_version = version
        major, minor = int(jquery_match.group(1)), int(jquery_match.group(2))
        signals.outdated_jquery = (major, minor) < (3, 6)

    if FLASH_CLASSID_RE.search(html) or soup.find(
        "embed", attrs={"type": re.compile("shockwave", re.IGNORECASE)}
    ):
        signals.uses_flash = True

    table_count = len(soup.find_all("table"))
    nested_tables = len(soup.select("table table"))
    signals.heavy_table_layout = table_count >= 2 and nested_tables >= 1

    year_matches = [int(y) for y in COPYRIGHT_YEAR_RE.findall(html)]
    if year_matches:
        signals.copyright_year = max(y for y in year_matches if y <= CURRENT_YEAR)

    signals.wayback_last_snapshot, signals.wayback_years_stale = _check_wayback(resp.url)
    signals.pagespeed_mobile_score = _check_pagespeed(resp.url)

    return signals
