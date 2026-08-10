# Marketplace-NL — Lead Scanner

Vindt kleine bedrijven in Nederland waarvan de website verouderd is, zodat
je ze gericht kunt benaderen met een aanbod voor een nieuwe website. Zoekt
bedrijven via OpenStreetMap en scoort hun website op verouderingssignalen
(HTTPS, mobielvriendelijkheid, oude CMS/jQuery-versies, Flash, laadtijd,
enz.), met een dashboard om leads te filteren, beoordelen en exporteren.

Er zijn drie functioneel identieke implementaties — kies op basis van waar
je de app wilt draaien:

- **[`python-app/`](python-app/)** — FastAPI + SQLite. Voor een VPS, eigen
  server, of cloudhosting (Render, Railway, Fly.io, ...) waar je een
  continu draaiend Python-proces kunt starten.
- **[`php-app/`](php-app/)** — PHP + MySQL. Voor gewone ("shared")
  webhosting zoals TransIP WebHosting, waar alleen PHP-scripts en
  cronjobs beschikbaar zijn, geen persistent achtergrondproces.
- **[`php-json-app/`](php-json-app/)** — PHP zonder database. Zelfde als
  hierboven, maar met een JSON-bestand als opslag in plaats van MySQL —
  handig als je hosting geen database biedt of je die stap wilt overslaan.

Alle drie bevatten hun eigen README met installatie-instructies. De
scoringlogica en de overwegingen rond verantwoord/AVG-conform gebruik zijn
in alle versies identiek.
