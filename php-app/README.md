# Marketplace-NL — Lead Scanner (PHP + MySQL)

Vindt kleine bedrijven in Nederland met een verouderde website — of helemaal
geen website — en levert per bedrijf een volledig rapport: wie het bedrijf is,
wat er mis is met de site, en wat die site nodig heeft.

Deze versie draait op gewone ("shared") webhosting zoals TransIP WebHosting:
PHP 8, een MySQL-database en een cronjob. Wil je liever helemaal geen
database, gebruik dan `../php-json-app` — functioneel identiek, maar met een
JSON-bestand als opslag.

## Wat de app doet

1. **Zoeken.** Kies een plaats en een branche. De app zoekt bedrijven in
   OpenStreetMap. Bedrijven **zonder** website worden bewust meegenomen en
   bovenaan gezet — dat zijn de sterkste leads.
2. **Analyseren.** Elke website wordt opgehaald en doorgemeten op zo'n
   dertig punten: beveiliging, mobielvriendelijkheid, CMS en versie,
   snelheid, vindbaarheid (SEO), toegankelijkheid, conversie en actualiteit.
3. **Rapporteren.** Per bedrijf krijg je bedrijfsgegevens (adres, telefoon,
   e-mail, openingstijden, sociale media, kaartlink), technische
   websitegegevens, een lijst met **wat er mis is** (met ernst en uitleg),
   bij elk punt **wat er nodig is** als concrete aanbeveling, en een lijst
   met **wat al goed is**.

## Installatie op TransIP (of vergelijkbare shared hosting)

1. **Database aanmaken.** Maak in je hostingpaneel een MySQL-database aan
   met een gebruiker. Noteer host, databasenaam, gebruikersnaam en wachtwoord.
2. **Schema importeren.** Open phpMyAdmin voor die database en importeer
   `schema.sql` uit deze map.
   *Had je al een oudere versie draaien met data erin?* Importeer dan
   `migrate.sql` in plaats daarvan — dat voegt de nieuwe kolommen toe zonder
   je bestaande leads te wissen.
3. **Bestanden uploaden.** Upload de inhoud van deze map (`php-app/`) via
   FTP/SFTP naar de webroot van je domein (vaak `public_html/`). Een submap
   mag ook: de app gebruikt relatieve paden.
4. **Configureren.** Kopieer `config.sample.php` naar `config.php` en vul
   de databasegegevens in, plus een contact-e-mailadres en een willekeurig
   `cron_token` (`php -r "echo bin2hex(random_bytes(16));"`).
5. **Cronjob instellen.** Een taak die elke 1–5 minuten draait:
   - **CLI-cron:** `php /pad/naar/public_html/cron_worker.php`
   - **URL-cron:** `https://jouwdomein.nl/cron_worker.php?token=JOUW_CRON_TOKEN`
6. **Testen.** Open `https://jouwdomein.nl/` en start een zoekopdracht.

## Achtergrondtaken via cron

Shared hosting staat geen continu draaiend achtergrondproces toe. Openstaande
analyses gaan daarom in een wachtrij (`scan_queue`), die `cron_worker.php` in
kleine batches afwerkt. Bij het starten van een zoekopdracht wordt meteen een
eerste batch verwerkt zodat je direct resultaten ziet; de rest volgt zodra de
cron draait.

## Weinig of geen resultaten?

De bedrijvengegevens komen uit OpenStreetMap, en dat is vrijwilligerswerk:
niet elk bedrijf staat erin, en lang niet elk bedrijf is onder de juiste
branche vastgelegd. Kies dan de branche **"Alle bedrijven (breedste
zoekopdracht)"**, probeer een grotere plaats, of controleer de plaatsnaam.
Ambachtelijke branches (loodgieter, elektricien, schilder) zijn in OSM het
dunst gevuld; horeca en winkels het best.

## Hoe de score werkt

Elk gevonden probleem levert punten op; opgeteld geeft dat een score van
0 tot 100. Bedrijven zonder website krijgen 100 en de aparte prioriteit
"Geen website". Zwaarst wegen: geen HTTPS (25), niet mobielvriendelijk (20),
Flash (20), verouderd CMS (15) en een lage Google PageSpeed-score (15).
Vanaf 55 punten is het "hoge prioriteit", vanaf 30 "gemiddeld".

Optioneel: zet een gratis
[PageSpeed Insights API-key](https://developers.google.com/speed/docs/insights/v5/get-started)
in `config.php` voor een echte snelheidsscore van Google.

## Verantwoord gebruik

- De crawler respecteert `robots.txt`, gebruikt een herkenbare User-Agent
  met contactgegevens en bezoekt alleen de startpagina van elke site.
- Nominatim en Overpass zijn gratis vrijwilligersdiensten met een
  [fair-use-beleid](https://operations.osmfoundation.org/policies/nominatim/).
- De verzamelde gegevens zijn zakelijke, openbare gegevens bedoeld voor
  B2B-benadering. Houd je aan de AVG: vermeld wie je bent, bied een
  afmeldmogelijkheid, bewaar niet langer dan nodig.
- De score is een signaal, geen oordeel: controleer een site altijd zelf.

## Projectstructuur

```
index.html, app.js, style.css   Dashboard-frontend (geen build-stap nodig)
config.sample.php               Kopieer naar config.php en vul in (niet in git)
schema.sql                      MySQL-schema voor een nieuwe installatie
migrate.sql                     Kolommen toevoegen aan een bestaande installatie
cron_worker.php                 Verwerkt de analyse-wachtrij in batches
includes/
  bootstrap.php, helpers.php    Config/DB-verbinding, JSON-responses, cURL
  discovery.php                 OpenStreetMap-zoekopdracht + branches
  analyzer.php                  Meet een website door op ~30 signalen
  scoring.php                   Vertaalt signalen naar problemen + aanbevelingen
  tasks.php                     Wachtrijverwerking, gedeeld door API en cron
  leads.php                     Leads ophalen/filteren
api/                            JSON-endpoints voor de frontend
.htaccess                       Blokkeert toegang tot config.php en includes/
```
