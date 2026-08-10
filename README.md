# Marketplace-NL — Lead Scanner

Vindt kleine bedrijven in Nederland met een verouderde website — of helemaal
geen website — en levert per bedrijf een verkoopklaar rapport:

- **wie het bedrijf is** (adres, telefoon, e-mail, openingstijden, sociale media),
- **hoe de website ervoor staat** (beveiliging, mobiel, CMS, snelheid, SEO),
- **wat er mis is**, met ernst en uitleg in gewone taal,
- **wat die site nodig heeft**, als concrete aanbeveling per probleem.

Bedrijven zonder website worden meegenomen en bovenaan gezet: dat zijn de
sterkste leads.

## Welke versie moet ik hebben?

| Map | Techniek | Kies dit als... |
|---|---|---|
| [`php-json-app/`](php-json-app/) | PHP, opslag in een JSON-bestand | Je gewone webhosting hebt (TransIP e.d.) en geen database wilt aanmaken. **Eenvoudigste installatie.** |
| [`php-app/`](php-app/) | PHP + MySQL | Je gewone webhosting hebt met een MySQL-database, en veel leads verwacht. |
| [`python-app/`](python-app/) | FastAPI + SQLite | Je een VPS of cloudhosting hebt waar een Python-proces mag blijven draaien. |

De twee PHP-versies zijn functioneel identiek en delen dezelfde analyse- en
scoringlogica; alleen de opslaglaag verschilt. Elke map heeft een eigen
README met installatie-instructies.

> **Let op:** `python-app/` is de oorspronkelijke, eenvoudigere versie. Die
> heeft de uitgebreide analyse (aanbevelingen per probleem, bedrijven zonder
> website, ~30 signalen) nog niet — die zit alleen in de PHP-versies.

## Hoe het werkt

1. **Zoeken** — je kiest een plaats en een branche; de app zoekt bedrijven
   via OpenStreetMap (gratis, geen API-key nodig).
2. **Analyseren** — elke website wordt opgehaald en doorgemeten op zo'n
   dertig punten.
3. **Beoordelen** — de signalen worden vertaald naar een score van 0–100,
   een lijst problemen en een lijst aanbevelingen.
4. **Opvolgen** — filter op plaats, branche, prioriteit of status, zet leads
   op "benaderd"/"gewonnen", schrijf notities en exporteer alles naar CSV.

Zie de README in de map van je keuze voor installatie, de scoreberekening en
de richtlijnen voor verantwoord (AVG-conform) gebruik.
