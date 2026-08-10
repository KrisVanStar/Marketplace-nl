import os

PAGESPEED_API_KEY = os.environ.get("PAGESPEED_API_KEY", "").strip()
CRAWLER_CONTACT_EMAIL = os.environ.get("CRAWLER_CONTACT_EMAIL", "you@example.com").strip()
DATABASE_URL = os.environ.get("DATABASE_URL", "sqlite:///./marketplace.db")

USER_AGENT = (
    f"MarketplaceNLLeadScanner/1.0 (+contact: {CRAWLER_CONTACT_EMAIL}; "
    f"purpose: identifying small NL businesses whose website may benefit "
    f"from a redesign; respects robots.txt; low request rate)"
)

REQUEST_TIMEOUT_SECONDS = 10
MAX_CONCURRENT_SCANS = 5
NOMINATIM_URL = "https://nominatim.openstreetmap.org/search"
OVERPASS_URL = "https://overpass-api.de/api/interpreter"
WAYBACK_AVAILABLE_URL = "https://archive.org/wayback/available"
