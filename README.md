# Marketplace-NL — Lead Scanner

Vindt kleine bedrijven in Nederland waarvan de website verouderd is, zodat
je ze gericht kunt benaderen met een aanbod voor een nieuwe website.

De app doet twee dingen:

1. **Ontdekken**: zoekt bedrijven in een Nederlandse plaats via
   [OpenStreetMap](https://www.openstreetmap.org) (Nominatim voor geocoding,
   Overpass API voor de bedrijvenzoekopdracht) — gratis, geen API-key nodig.
2. **Analyseren & scoren**: bezoekt elke website en beoordeelt signalen van
   veroudering, en geeft een score 0–100 met uitleg.

Je kunt ook je eigen lijst met bedrijven importeren via CSV, als je al
weet welke bedrijven je wilt checken.

## Hoe de score werkt

Elke website krijgt punten voor signalen die duiden op een site die aan
vervanging toe is:

| Signaal | Punten |
|---|---|
| Geen HTTPS | 25 |
| Geen mobiele/responsive viewport | 20 |
| Gebruikt Adobe Flash | 20 |
| Verouderde WordPress-versie (< 6.x) | 15 |
| Lage Google PageSpeed mobile-score (optioneel, zie hieronder) | 15 |
| Oude table-based layout | 10 |
| Verouderde jQuery-versie (< 3.6) | 10 |
| Trage laadtijd (> 3s) | 10 |
| Lang niet gearchiveerd door de Wayback Machine (≥ 3 jaar) | 10 |
| Geen HTML5 doctype | 5 |
| Oud copyright-jaar in de footer | tot 15, schaalt met leeftijd |

Score ≥ 55 = hoge prioriteit, 30–54 = gemiddeld, < 30 = laag. Elke lead
toont de exacte redenen, zodat de score nooit een black box is.

Optioneel: zet `PAGESPEED_API_KEY` in `.env` (gratis via de [Google
PageSpeed Insights API](https://developers.google.com/speed/docs/insights/v5/get-started))
voor een echte performance-score naast de heuristieken. Zonder key werkt
de app gewoon verder, alleen zonder dat ene signaal.

## Installeren

```bash
python3 -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env   # optioneel: vul PAGESPEED_API_KEY en CRAWLER_CONTACT_EMAIL in
```

## Starten

```bash
uvicorn app.main:app --reload
```

Open <http://localhost:8000>. De SQLite-database (`marketplace.db`) wordt
automatisch aangemaakt.

## Gebruik

- **Scan starten**: kies een stad (bijv. "Haarlem") en een categorie
  (kapper, restaurant, loodgieter, advocaat, ...), en start de scan. De
  bedrijven worden gezocht via OpenStreetMap en hun websites automatisch
  geanalyseerd; de voortgang is live te volgen.
- **CSV importeren**: heb je al een lijst met bedrijven? Upload een CSV met
  kolommen `name, website, city, category, address, phone` (alleen `name`
  en `website` zijn verplicht) om ze direct te laten scannen.
- **Leads-tabel**: filter op stad, categorie, status en minimale score.
  Klik op een rij voor de volledige uitleg achter de score, en om de status
  bij te werken (nieuw / benaderd / gewonnen / genegeerd) of opnieuw te
  scannen.
- **Exporteren**: exporteer de (gefilterde) lijst als CSV voor gebruik in
  je CRM of mailtool.

## Belangrijk: verantwoord gebruik

- **Wees een goede buur op het web.** De crawler respecteert `robots.txt`,
  gebruikt een duidelijke User-Agent met contactinfo (via
  `CRAWLER_CONTACT_EMAIL`), en scant met een beperkt aantal gelijktijdige
  requests. Verhoog `MAX_CONCURRENT_SCANS` niet agressief en scan geen site
  herhaaldelijk in korte tijd.
- **Nominatim/Overpass fair use.** Dit zijn gratis, door vrijwilligers
  gedraaide diensten met eigen [gebruiksvoorwaarden]
  (https://operations.osmfoundation.org/policies/nominatim/). De app doet
  al maximaal 1 discovery-scan per keer met een korte pauze tussen calls;
  gebruik dit niet voor grootschalig scrapen.
- **B2B-outreach en AVG/GDPR.** De gevonden gegevens (bedrijfsnaam,
  website, publiek telefoonnummer/adres) zijn zakelijke, publiek
  beschikbare gegevens, bedoeld voor B2B-marketing. Zorg dat je
  benaderingen voldoen aan de Nederlandse/EU-regels: vermeld duidelijk wie
  je bent, bied altijd een opt-out, en bewaar geen gegevens langer dan
  nodig. Stuur geen berichten naar persoonlijke (niet-zakelijke)
  contactgegevens.
- Deze tool signaleert **kandidaten**, geen garanties: controleer altijd
  handmatig voordat je een bedrijf benadert.

## Projectstructuur

```
app/
  main.py        FastAPI app en alle API-endpoints
  discovery.py   OpenStreetMap-gebaseerde bedrijvenzoekopdracht
  analyzer.py    Haalt een website op en extraheert veroudering-signalen
  scoring.py     Zet signalen om in score + leesbare redenen
  tasks.py       Achtergrondtaak die scan-jobs uitvoert
  models.py      SQLAlchemy-modellen (Business, Scan, ScanJob)
  db.py          Database-setup (SQLite)
  config.py      Instellingen via omgevingsvariabelen
static/
  index.html, app.js, style.css   Dashboard-frontend (geen build-stap nodig)
```
