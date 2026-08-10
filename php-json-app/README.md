# Marketplace-NL — Lead Scanner (PHP, geen database nodig)

Vindt kleine bedrijven in Nederland met een verouderde website — of helemaal
geen website — en levert per bedrijf een volledig rapport: wie het bedrijf is,
wat er mis is met de site, en wat die site nodig heeft.

Dit is dezelfde app als `../php-app`, maar zonder MySQL: alle data staat in
één JSON-bestand (`data/db.json`) dat de app zelf beheert, beveiligd met
bestandslocking zodat gelijktijdige requests (bijvoorbeeld een paginabezoek
en de cron-taak tegelijk) elkaars schrijfacties niet overschrijven.

## Wat de app doet

1. **Zoeken.** Kies een plaats en een branche. De app zoekt bedrijven in
   OpenStreetMap. Bedrijven **zonder** website worden bewust meegenomen en
   bovenaan gezet — dat zijn de sterkste leads.
2. **Analyseren.** Elke website wordt opgehaald en doorgemeten op zo'n
   dertig punten: beveiliging, mobielvriendelijkheid, CMS en versie,
   snelheid, vindbaarheid (SEO), toegankelijkheid, conversie en actualiteit.
3. **Rapporteren.** Per bedrijf krijg je:
   - **Bedrijfsgegevens**: adres, postcode, telefoon, e-mail, openingstijden,
     sociale media, en een link naar de kaart en de OSM-vermelding.
   - **Websitegegevens**: HTTP-status, laadtijd, paginagrootte, HTTPS,
     mobielvriendelijkheid, CMS + versie, paginatitel, zoekomschrijving,
     aantal afbeeldingen zonder alt-tekst, statistiektools, contactformulier,
     copyright-jaar en laatste archiefwijziging.
   - **Wat er mis is**: elk probleem met ernst (kritiek/hoog/gemiddeld/laag)
     en een uitleg in gewone taal waaróm het een probleem is.
   - **Wat er nodig is**: bij elk probleem een concrete aanbeveling — precies
     de tekst die je in een offerte of verkoopgesprek kunt gebruiken.
   - **Wat al goed is**, zodat je niet iets aanbiedt dat er al staat.

## Wat je nodig hebt

Alleen PHP 8.x met de standaard `curl`- en `dom`-extensies (die vrijwel
elke hosting standaard aan heeft staan) en een cronjob. Geen database, geen
phpMyAdmin, geen extra configuratie daarvoor.

## Installatie op TransIP (of vergelijkbare shared hosting)

1. **Bestanden uploaden.** Upload de hele inhoud van deze map
   (`php-json-app/`) via FTP/SFTP naar de webroot van je domein (vaak
   `public_html/` of `htdocs/`). Een submap mag ook: de app gebruikt
   relatieve paden en werkt dus ook op `jouwdomein.nl/leadscanner/`.
2. **Configureren.** Kopieer `config.sample.php` naar `config.php` op de
   server en vul in: een contact-e-mailadres (komt in de User-Agent van de
   crawler) en een willekeurig `cron_token` (genereer er een met
   `php -r "echo bin2hex(random_bytes(16));"`).
3. **Schrijfrechten controleren.** De app maakt zelf de map `data/` en
   het bestand `data/db.json` aan bij de eerste scan. Als dat mislukt
   (foutmelding "Controleer schrijfrechten"), zet dan handmatig een map
   `data/` klaar met schrijfrechten voor de webserver (probeer 755 eerst,
   daarna 775). **Belangrijk:** `data/db.json` bevat alle verzamelde
   bedrijfsgegevens — controleer na het uploaden dat
   `https://jouwdomein.nl/data/db.json` een foutmelding geeft en geen JSON
   (de meegeleverde `.htaccess` regelt dit op Apache-hosting).
4. **Cronjob instellen.** In je hostingpaneel (zoek naar "Cron jobs" /
   "Geplande taken") zet je een taak die elke 1–5 minuten draait:
   - **CLI-cron:** `php /pad/naar/public_html/cron_worker.php`
   - **URL-cron:** `https://jouwdomein.nl/cron_worker.php?token=JOUW_CRON_TOKEN`
5. **Controleren.** Open `https://jouwdomein.nl/check.php`. Die pagina test
   de hele installatie — PHP-versie, extensies, `config.php`, schrijfrechten
   en de verbinding met OpenStreetMap — en zet bij elk probleem meteen de
   oplossing. Alles groen? Dan is de app klaar voor gebruik.
6. **Aan de slag.** Open `https://jouwdomein.nl/` (ook prima vanaf je
   telefoon) en start een zoekopdracht.

## Er gaat iets mis

Open eerst **`check.php`** — daar staat in negen van de tien gevallen direct
wat er aan de hand is. Veelvoorkomende meldingen:

**"config.php is niet geldig: het bestand geeft geen instellingen terug"**
Het bestand mist de afsluitende `];`, of de eerste regel `<?php` is
verdwenen. Dat laatste gebeurt als een FTP-programma het bestand in
"binair" in plaats van "tekst" overzet, of als een editor het bestand
herschrijft. Oplossing: kopieer `config.sample.php` opnieuw naar
`config.php` en pas alléén de waarden tussen de aanhalingstekens aan —
laat `return [` bovenaan en `];` onderaan staan.

**"Controleer schrijfrechten op de data map"**
Geef de map `data/` schrijfrechten via je FTP-programma (rechtermuisknop →
rechten/permissions): probeer 755, en als dat niet helpt 775.

**"Kon de branches niet laden"** (leeg keuzemenu op de hoofdpagina)
De bestanden staan niet compleet op de server, of `config.php` ontbreekt.
`check.php` wijst aan welk bestand mist.

**"Alle OpenStreetMap-servers gaven een fout"**
Meestal tijdelijk; de app probeert automatisch drie servers. Blijft het
misgaan, dan blokkeert je hosting mogelijk uitgaand internetverkeer —
`check.php` laat dat zien en dan kun je het bij je hostingpartij navragen.

## Weinig of geen resultaten?

De bedrijvengegevens komen uit OpenStreetMap, en dat is vrijwilligerswerk:
niet elk bedrijf staat erin, en lang niet elk bedrijf is onder de juiste
branche vastgelegd. Als een zoekopdracht weinig oplevert:

- Kies de branche **"Alle bedrijven (breedste zoekopdracht)"** — die zoekt
  op alle winkels, ambachten, kantoren en horeca tegelijk.
- Probeer een grotere plaats, of de officiële plaatsnaam.
- Ambachtelijke branches (loodgieter, elektricien, schilder) zijn in OSM
  het dunst gevuld; horeca en winkels het best.

Krijg je een foutmelding over de OpenStreetMap-servers, dan is dat vrijwel
altijd tijdelijk — de app probeert automatisch drie verschillende servers.

## Hoe de score werkt

Elk gevonden probleem levert punten op; opgeteld geeft dat een score van
0 tot 100. Bedrijven zonder website krijgen 100 en de aparte prioriteit
"Geen website". Zwaarst wegen: geen HTTPS (25), niet mobielvriendelijk (20),
Flash (20), verouderd CMS (15) en een lage Google PageSpeed-score (15).
Daarnaast tellen tientallen kleinere punten mee (SEO, toegankelijkheid,
contactformulier, actualiteit). Vanaf 55 punten is het "hoge prioriteit",
vanaf 30 "gemiddeld".

Optioneel: zet een gratis
[PageSpeed Insights API-key](https://developers.google.com/speed/docs/insights/v5/get-started)
in `config.php` voor een echte snelheidsscore van Google. Zonder key werkt
alles gewoon, alleen dat ene signaal ontbreekt dan.

## Verantwoord gebruik

- De crawler respecteert `robots.txt`, gebruikt een herkenbare User-Agent
  met contactgegevens en bezoekt alleen de startpagina van elke site.
- Nominatim en Overpass zijn gratis diensten van vrijwilligers met een
  [fair-use-beleid](https://operations.osmfoundation.org/policies/nominatim/).
  Gebruik dit niet voor grootschalig scrapen.
- De verzamelde gegevens zijn zakelijke, openbare gegevens bedoeld voor
  B2B-benadering. Houd je bij het benaderen aan de AVG: vermeld wie je bent,
  bied een afmeldmogelijkheid, en bewaar niet langer dan nodig.
- De score is een signaal, geen oordeel: controleer een site altijd zelf
  voordat je een bedrijf benadert.

## Projectstructuur

```
index.html, app.js, style.css   Dashboard-frontend (geen build-stap nodig)
config.sample.php               Kopieer naar config.php en vul in (niet in git)
cron_worker.php                 Verwerkt de analyse-wachtrij in batches
data/                           data/db.json wordt hier automatisch aangemaakt
includes/
  bootstrap.php, helpers.php    Config, JSON-responses, cURL-wrapper
  jsondb.php                    Bestandsopslag met flock()-locking
  discovery.php                 OpenStreetMap-zoekopdracht + branches
  analyzer.php                  Meet een website door op ~30 signalen
  scoring.php                   Vertaalt signalen naar problemen + aanbevelingen
  tasks.php                     Wachtrijverwerking, gedeeld door API en cron
  leads.php                     Leads ophalen/filteren
api/                            JSON-endpoints voor de frontend
.htaccess                       Blokkeert toegang tot config.php/includes/data
```

## Lokaal testen (optioneel)

```bash
cp config.sample.php config.php   # vul cron_token in
php -S localhost:8000
```

De `data/`-map wordt automatisch aangemaakt. Roep `php cron_worker.php` op
de CLI aan (werkt zonder token) in plaats van een echte cronjob.
