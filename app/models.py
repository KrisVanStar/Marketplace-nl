import datetime

from sqlalchemy import (
    Column,
    DateTime,
    Float,
    ForeignKey,
    Integer,
    String,
    Text,
    UniqueConstraint,
)
from sqlalchemy.orm import relationship

from app.db import Base


class Business(Base):
    __tablename__ = "businesses"

    id = Column(Integer, primary_key=True)
    name = Column(String, nullable=False)
    website = Column(String, nullable=False)
    city = Column(String, index=True)
    category = Column(String, index=True)
    address = Column(String)
    phone = Column(String)
    source = Column(String, default="osm")
    source_id = Column(String)
    status = Column(String, default="new", index=True)  # new/contacted/won/ignored
    created_at = Column(DateTime, default=datetime.datetime.utcnow)

    scans = relationship("Scan", back_populates="business", order_by="Scan.scanned_at.desc()")

    __table_args__ = (UniqueConstraint("website", name="uq_business_website"),)


class Scan(Base):
    __tablename__ = "scans"

    id = Column(Integer, primary_key=True)
    business_id = Column(Integer, ForeignKey("businesses.id"), nullable=False)
    score = Column(Integer, nullable=False)
    priority = Column(String)  # high/medium/low
    reasons_json = Column(Text)  # JSON list of human-readable reasons
    signals_json = Column(Text)  # JSON dict of raw signals
    is_https = Column(Integer)  # 0/1
    has_viewport = Column(Integer)  # 0/1
    status_code = Column(Integer)
    final_url = Column(String)
    response_time_ms = Column(Integer)
    error = Column(String)
    scanned_at = Column(DateTime, default=datetime.datetime.utcnow)

    business = relationship("Business", back_populates="scans")


class ScanJob(Base):
    __tablename__ = "scan_jobs"

    id = Column(String, primary_key=True)
    status = Column(String, default="pending")  # pending/running/done/error
    city = Column(String)
    category = Column(String)
    total = Column(Integer, default=0)
    processed = Column(Integer, default=0)
    found_leads = Column(Integer, default=0)
    message = Column(String)
    created_at = Column(DateTime, default=datetime.datetime.utcnow)
