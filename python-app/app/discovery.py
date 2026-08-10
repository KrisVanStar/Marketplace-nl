"""Finds small Dutch businesses that list a website, via OpenStreetMap.

Two public, keyless services are used:
- Nominatim: geocodes a city name to a bounding box.
- Overpass API: queries OSM for businesses (shops, crafts, small offices,
  local amenities) inside that box that have a `website` tag.

Both are free community services with strict rate limits, so requests are
kept to one at a time and results are capped.
"""
from __future__ import annotations

import time
from dataclasses import dataclass

import requests

from app.config import NOMINATIM_URL, OVERPASS_URL, REQUEST_TIMEOUT_SECONDS, USER_AGENT

# category label -> list of OSM (key, value) tag filters. These are chosen
# to skew towards small/independent local businesses rather than big chains
# (though chain filtering ultimately depends on OSM data quality).
CATEGORIES: dict[str, list[tuple[str, str]]] = {
    "restaurant": [("amenity", "restaurant")],
    "cafe": [("amenity", "cafe")],
    "hairdresser": [("shop", "hairdresser")],
    "beauty_salon": [("shop", "beauty")],
    "bakery": [("shop", "bakery")],
    "butcher": [("shop", "butcher")],
    "florist": [("shop", "florist")],
    "garage_car_repair": [("shop", "car_repair")],
    "plumber": [("craft", "plumber")],
    "electrician": [("craft", "electrician")],
    "carpenter": [("craft", "carpenter")],
    "dentist": [("amenity", "dentist")],
    "physiotherapist": [("healthcare", "physiotherapist")],
    "law_office": [("office", "lawyer")],
    "accountant": [("office", "accountant")],
    "real_estate": [("office", "estate_agent")],
    "architect": [("office", "architect")],
    "gym": [("leisure", "fitness_centre")],
    "clothing_store": [("shop", "clothes")],
    "furniture_store": [("shop", "furniture")],
}


@dataclass
class DiscoveredBusiness:
    name: str
    website: str
    city: str
    category: str
    address: str
    phone: str
    source: str
    source_id: str


def geocode_city(city: str) -> tuple[float, float, float, float]:
    """Return (south, west, north, east) bounding box for a NL place name."""
    resp = requests.get(
        NOMINATIM_URL,
        params={
            "q": f"{city}, Netherlands",
            "format": "json",
            "limit": 1,
            "countrycodes": "nl",
        },
        headers={"User-Agent": USER_AGENT},
        timeout=REQUEST_TIMEOUT_SECONDS,
    )
    resp.raise_for_status()
    results = resp.json()
    if not results:
        raise ValueError(f"Could not geocode city '{city}' in the Netherlands")
    bbox = results[0]["boundingbox"]  # [south, north, west, east] as strings
    south, north, west, east = (float(v) for v in bbox)
    return south, west, north, east


def _build_overpass_query(bbox: tuple[float, float, float, float], tags: list[tuple[str, str]]) -> str:
    south, west, north, east = bbox
    bbox_str = f"{south},{west},{north},{east}"
    clauses = []
    for key, value in tags:
        clauses.append(f'node["{key}"="{value}"]["website"]({bbox_str});')
        clauses.append(f'way["{key}"="{value}"]["website"]({bbox_str});')
        clauses.append(f'node["{key}"="{value}"]["contact:website"]({bbox_str});')
        clauses.append(f'way["{key}"="{value}"]["contact:website"]({bbox_str});')
    body = "\n  ".join(clauses)
    return f"""
[out:json][timeout:60];
(
  {body}
);
out center 100;
""".strip()


def _address_from_tags(tags: dict) -> str:
    parts = [
        tags.get("addr:street", ""),
        tags.get("addr:housenumber", ""),
    ]
    street = " ".join(p for p in parts if p).strip()
    city_part = tags.get("addr:city", "")
    postcode = tags.get("addr:postcode", "")
    line2 = " ".join(p for p in [postcode, city_part] if p).strip()
    return ", ".join(p for p in [street, line2] if p)


def discover_businesses(city: str, category: str, limit: int = 25) -> list[DiscoveredBusiness]:
    if category not in CATEGORIES:
        raise ValueError(f"Unknown category '{category}'")

    bbox = geocode_city(city)
    time.sleep(1)  # be polite to Nominatim before hitting Overpass

    query = _build_overpass_query(bbox, CATEGORIES[category])
    resp = requests.post(
        OVERPASS_URL,
        data={"data": query},
        headers={"User-Agent": USER_AGENT},
        timeout=60,
    )
    resp.raise_for_status()
    elements = resp.json().get("elements", [])

    results: list[DiscoveredBusiness] = []
    seen_websites: set[str] = set()
    for el in elements:
        tags = el.get("tags", {})
        website = (tags.get("website") or tags.get("contact:website") or "").strip()
        name = (tags.get("name") or "").strip()
        if not website or not name:
            continue
        if not website.startswith("http"):
            website = "https://" + website
        normalized = website.rstrip("/").lower()
        if normalized in seen_websites:
            continue
        seen_websites.add(normalized)

        results.append(
            DiscoveredBusiness(
                name=name,
                website=website,
                city=city,
                category=category,
                address=_address_from_tags(tags),
                phone=(tags.get("phone") or tags.get("contact:phone") or ""),
                source="osm",
                source_id=f"{el.get('type')}/{el.get('id')}",
            )
        )
        if len(results) >= limit:
            break

    return results
