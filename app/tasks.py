from __future__ import annotations

import json
import logging
from concurrent.futures import ThreadPoolExecutor, as_completed

from app.analyzer import analyze_website
from app.config import MAX_CONCURRENT_SCANS
from app.db import SessionLocal
from app.discovery import discover_businesses
from app.models import Business, Scan, ScanJob
from app.scoring import score_site

logger = logging.getLogger("marketplace.tasks")


def _get_or_create_business(db, *, name, website, city, category, address, phone, source, source_id) -> Business:
    business = db.query(Business).filter(Business.website == website).one_or_none()
    if business:
        return business
    business = Business(
        name=name,
        website=website,
        city=city,
        category=category,
        address=address,
        phone=phone,
        source=source,
        source_id=source_id,
    )
    db.add(business)
    db.commit()
    db.refresh(business)
    return business


def _scan_and_store(db, business: Business) -> Scan:
    signals = analyze_website(business.website)
    result = score_site(signals)

    scan = Scan(
        business_id=business.id,
        score=result.score,
        priority=result.priority,
        reasons_json=json.dumps(result.reasons, ensure_ascii=False),
        signals_json=json.dumps(signals.__dict__, ensure_ascii=False, default=str),
        is_https=int(signals.is_https),
        has_viewport=int(signals.has_viewport_meta),
        status_code=signals.status_code,
        final_url=signals.final_url,
        response_time_ms=signals.response_time_ms,
        error=signals.error or None,
    )
    db.add(scan)
    db.commit()
    return scan


def run_discovery_job(job_id: str, city: str, category: str, limit: int) -> None:
    db = SessionLocal()
    try:
        job = db.get(ScanJob, job_id)
        job.status = "running"
        db.commit()

        try:
            candidates = discover_businesses(city, category, limit=limit)
        except Exception as exc:
            job.status = "error"
            job.message = f"Discovery failed: {exc}"
            db.commit()
            return

        job.total = len(candidates)
        job.message = f"{len(candidates)} bedrijven gevonden, websites worden gescand..."
        db.commit()

        if not candidates:
            job.status = "done"
            job.message = "Geen bedrijven met website gevonden voor deze zoekopdracht."
            db.commit()
            return

        businesses = [
            _get_or_create_business(
                db,
                name=c.name,
                website=c.website,
                city=c.city,
                category=c.category,
                address=c.address,
                phone=c.phone,
                source=c.source,
                source_id=c.source_id,
            )
            for c in candidates
        ]

        found_leads = 0
        with ThreadPoolExecutor(max_workers=MAX_CONCURRENT_SCANS) as pool:
            futures = {pool.submit(analyze_website, b.website): b for b in businesses}
            for future in as_completed(futures):
                business = futures[future]
                try:
                    signals = future.result()
                except Exception as exc:
                    logger.warning("Scan failed for %s: %s", business.website, exc)
                    job.processed += 1
                    db.commit()
                    continue

                result = score_site(signals)
                scan = Scan(
                    business_id=business.id,
                    score=result.score,
                    priority=result.priority,
                    reasons_json=json.dumps(result.reasons, ensure_ascii=False),
                    signals_json=json.dumps(signals.__dict__, ensure_ascii=False, default=str),
                    is_https=int(signals.is_https),
                    has_viewport=int(signals.has_viewport_meta),
                    status_code=signals.status_code,
                    final_url=signals.final_url,
                    response_time_ms=signals.response_time_ms,
                    error=signals.error or None,
                )
                db.add(scan)
                if result.priority in ("high", "medium"):
                    found_leads += 1
                job.processed += 1
                job.found_leads = found_leads
                db.commit()

        job.status = "done"
        job.message = f"Klaar: {job.processed}/{job.total} sites gescand, {found_leads} kansrijke leads."
        db.commit()
    except Exception as exc:  # safety net so a job never hangs in "running"
        logger.exception("Job %s crashed", job_id)
        job = db.get(ScanJob, job_id)
        if job:
            job.status = "error"
            job.message = str(exc)
            db.commit()
    finally:
        db.close()
