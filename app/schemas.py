from __future__ import annotations

import datetime

from pydantic import BaseModel


class LeadOut(BaseModel):
    id: int
    name: str
    website: str
    city: str | None
    category: str | None
    address: str | None
    phone: str | None
    status: str
    created_at: datetime.datetime
    latest_score: int | None = None
    latest_priority: str | None = None
    latest_reasons: list[str] = []
    scanned_at: datetime.datetime | None = None

    class Config:
        from_attributes = True


class LeadDetailOut(LeadOut):
    signals: dict = {}


class StatusUpdate(BaseModel):
    status: str


class DiscoverRequest(BaseModel):
    city: str
    category: str
    limit: int = 20


class JobOut(BaseModel):
    id: str
    status: str
    city: str | None
    category: str | None
    total: int
    processed: int
    found_leads: int
    message: str | None

    class Config:
        from_attributes = True
