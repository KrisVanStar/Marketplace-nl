from __future__ import annotations

import csv
import io
import json
import uuid

from fastapi import BackgroundTasks, Depends, FastAPI, File, HTTPException, UploadFile
from fastapi.responses import StreamingResponse
from fastapi.staticfiles import StaticFiles
from sqlalchemy import desc, func
from sqlalchemy.orm import Session

from app.db import get_db, init_db
from app.discovery import CATEGORIES
from app.models import Business, Scan, ScanJob
from app.schemas import DiscoverRequest, JobOut, LeadDetailOut, LeadOut, StatusUpdate
from app.tasks import _get_or_create_business, _scan_and_store, run_discovery_job

app = FastAPI(title="Marketplace-NL Lead Scanner")


@app.on_event("startup")
def on_startup():
    init_db()


def _latest_scan(db: Session, business_id: int) -> Scan | None:
    return (
        db.query(Scan)
        .filter(Scan.business_id == business_id)
        .order_by(desc(Scan.scanned_at))
        .first()
    )


def _lead_out(business: Business, scan: Scan | None) -> LeadOut:
    return LeadOut(
        id=business.id,
        name=business.name,
        website=business.website,
        city=business.city,
        category=business.category,
        address=business.address,
        phone=business.phone,
        status=business.status,
        created_at=business.created_at,
        latest_score=scan.score if scan else None,
        latest_priority=scan.priority if scan else None,
        latest_reasons=json.loads(scan.reasons_json) if scan and scan.reasons_json else [],
        scanned_at=scan.scanned_at if scan else None,
    )


@app.get("/api/categories")
def list_categories():
    return sorted(CATEGORIES.keys())


@app.post("/api/scan/discover", response_model=JobOut)
def start_discover(req: DiscoverRequest, background_tasks: BackgroundTasks, db: Session = Depends(get_db)):
    if req.category not in CATEGORIES:
        raise HTTPException(400, f"Unknown category '{req.category}'. See /api/categories.")
    if not req.city.strip():
        raise HTTPException(400, "City is required")
    limit = max(1, min(req.limit, 50))

    job = ScanJob(id=str(uuid.uuid4()), status="pending", city=req.city, category=req.category, total=0)
    db.add(job)
    db.commit()
    db.refresh(job)

    background_tasks.add_task(run_discovery_job, job.id, req.city, req.category, limit)
    return job


@app.get("/api/scan/status/{job_id}", response_model=JobOut)
def get_job_status(job_id: str, db: Session = Depends(get_db)):
    job = db.get(ScanJob, job_id)
    if not job:
        raise HTTPException(404, "Job not found")
    return job


@app.get("/api/leads", response_model=list[LeadOut])
def list_leads(
    min_score: int = 0,
    city: str | None = None,
    category: str | None = None,
    status: str | None = None,
    sort: str = "score_desc",
    limit: int = 200,
    db: Session = Depends(get_db),
):
    latest_scan_ids = (
        db.query(Scan.business_id, func.max(Scan.id).label("max_id"))
        .group_by(Scan.business_id)
        .subquery()
    )

    q = (
        db.query(Business, Scan)
        .outerjoin(latest_scan_ids, Business.id == latest_scan_ids.c.business_id)
        .outerjoin(Scan, Scan.id == latest_scan_ids.c.max_id)
    )

    if city:
        q = q.filter(Business.city.ilike(f"%{city}%"))
    if category:
        q = q.filter(Business.category == category)
    if status:
        q = q.filter(Business.status == status)
    if min_score:
        q = q.filter(Scan.score >= min_score)

    if sort == "score_desc":
        q = q.order_by(desc(Scan.score))
    elif sort == "score_asc":
        q = q.order_by(Scan.score)
    elif sort == "newest":
        q = q.order_by(desc(Business.created_at))

    rows = q.limit(limit).all()
    return [_lead_out(b, s) for b, s in rows]


@app.get("/api/leads/{lead_id}", response_model=LeadDetailOut)
def get_lead(lead_id: int, db: Session = Depends(get_db)):
    business = db.get(Business, lead_id)
    if not business:
        raise HTTPException(404, "Lead not found")
    scan = _latest_scan(db, lead_id)
    out = _lead_out(business, scan)
    signals = json.loads(scan.signals_json) if scan and scan.signals_json else {}
    return LeadDetailOut(**out.model_dump(), signals=signals)


@app.patch("/api/leads/{lead_id}", response_model=LeadOut)
def update_lead_status(lead_id: int, payload: StatusUpdate, db: Session = Depends(get_db)):
    if payload.status not in {"new", "contacted", "won", "ignored"}:
        raise HTTPException(400, "Invalid status")
    business = db.get(Business, lead_id)
    if not business:
        raise HTTPException(404, "Lead not found")
    business.status = payload.status
    db.commit()
    return _lead_out(business, _latest_scan(db, lead_id))


@app.post("/api/leads/{lead_id}/rescan", response_model=LeadOut)
def rescan_lead(lead_id: int, db: Session = Depends(get_db)):
    business = db.get(Business, lead_id)
    if not business:
        raise HTTPException(404, "Lead not found")
    _scan_and_store(db, business)
    return _lead_out(business, _latest_scan(db, lead_id))


@app.post("/api/leads/import")
async def import_leads(file: UploadFile = File(...), db: Session = Depends(get_db)):
    """CSV columns: name, website, city, category, address, phone (only name+website required)."""
    content = (await file.read()).decode("utf-8-sig")
    reader = csv.DictReader(io.StringIO(content))
    created = []
    for row in reader:
        name = (row.get("name") or "").strip()
        website = (row.get("website") or "").strip()
        if not name or not website:
            continue
        if not website.startswith("http"):
            website = "https://" + website
        business = _get_or_create_business(
            db,
            name=name,
            website=website,
            city=(row.get("city") or "").strip(),
            category=(row.get("category") or "manual").strip(),
            address=(row.get("address") or "").strip(),
            phone=(row.get("phone") or "").strip(),
            source="csv",
            source_id=None,
        )
        _scan_and_store(db, business)
        created.append(business.id)
    return {"imported": len(created), "business_ids": created}


@app.get("/api/export.csv")
def export_csv(
    min_score: int = 0,
    city: str | None = None,
    category: str | None = None,
    status: str | None = None,
    db: Session = Depends(get_db),
):
    leads = list_leads(min_score=min_score, city=city, category=category, status=status, sort="score_desc", limit=1000, db=db)

    buffer = io.StringIO()
    writer = csv.writer(buffer)
    writer.writerow(["name", "website", "city", "category", "address", "phone", "status", "score", "priority", "reasons"])
    for lead in leads:
        writer.writerow(
            [
                lead.name,
                lead.website,
                lead.city,
                lead.category,
                lead.address,
                lead.phone,
                lead.status,
                lead.latest_score,
                lead.latest_priority,
                "; ".join(lead.latest_reasons),
            ]
        )
    buffer.seek(0)
    return StreamingResponse(
        buffer,
        media_type="text/csv",
        headers={"Content-Disposition": "attachment; filename=leads.csv"},
    )


app.mount("/", StaticFiles(directory="static", html=True), name="static")
