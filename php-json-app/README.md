# Marketplace-NL — Lead Scanner (PHP, geen database nodig)

Dit is dezelfde app als `../php-app`, maar zonder MySQL: alle data staat in
één JSON-bestand (`data/db.json`) dat de app zelf beheert, beveiligd met
bestandslocking zodat gelijktijdige requests (bijvoorbeeld een paginabezoek
en de cron-taak tegelijk) elkaars schrijfacties niet overschrijven.

Kies deze versie als je hosting geen MySQL-database biedt (of je geen zin
hebt er een aan te maken) en `../php-app` als je liever een echte database
gebruikt — bijvoorbeeld omdat je uiteindelijk honderden/duizenden leads
verwacht. Alle functionaliteit, scoringlogica en frontend zijn identiek;
alleen de opslag verschilt.

## Wat je nodig hebt

Alleen PHP 8.x met de standaard `curl`- en `dom`-extensies (die vrijwel
elke hosting standaard aan heeft staan) en een cronjob. Geen database, geen
phpMyAdmin, geen extra configuratie daarvoor.

## Installatie op TransIP (of vergelijkbare shared hosting)

1. **Bestanden uploaden.** Upload de hele inhoud van deze map
   (`php-json-app/`) via FTP/SFTP naar de webroot van je domein (vaak
   `public_html/` of `htdocs/`).
2. **Configureren.** Kopieer `config.sample.php` naar `config.php` op de
   server en vul in: een contact-e-mailadres (komt in de User-Agent van de
   crawler) en een willekeurig `cron_token` (genereer er een met
   `php -r "echo bin2hex(random_bytes(16));"`).
3. **Schrijfrechten controleren.** De app maakt zelf de map `data/` en
   het bestand `data/db.json` aan bij de eerste scan. Als dat mislukt
   (foutmelding "Controleer schrijfrechten"), zet dan handmatig een map
   `data/` klaar met schrijfrechten voor de webserver (via FTP-client:
   rechten meestal 755 of 775 — probeer 755 eerst, en ga naar 775 als dat
   niet werkt). **Belangrijk:** `data/db.json` bevat alle verzamelde
   bedrijfsgegevens — zorg dat het niet direct via de browser opvraagbaar
   is (de meegeleverde `.htaccess` blokkeert dit al op Apache-hosting;
   controleer dit na het uploaden door `https://jouwdomein.nl/data/db.json`
   te openen — dat moet een foutmelding geven, geen JSON).
4. **Cronjob instellen.** In je hostingpaneel (zoek naar "Cron jobs" /
   "Geplande taken") zet je een taak die elke 1–5 minuten draait:
   - **Als CLI-cron beschikbaar is:**
     `php /pad/naar/public_html/cron_worker.php`
   - **Als alleen URL-gebaseerde cron beschikbaar is:**
     `https://jouwdomein.nl/cron_worker.php?token=JOUW_CRON_TOKEN`
5. **Testen.** Open `https://jouwdomein.nl/` in je browser (ook prima
   vanaf je telefoon) en start een scan.

## Waarom niet gewoon SQLite?

PHP's SQLite-ondersteuning (PDO_SQLITE) is niet op elke shared hosting
standaard ingeschakeld, en vereist net als MySQL dat de hostingomgeving
die extensie aanbiedt. Een los JSON-bestand met bestandslocking werkt
overal waar PHP zelf werkt, zonder afhankelijkheden — vandaar deze keuze
voor de meest universeel inzetbare variant.

## Beperkingen tegenover de MySQL-versie

- Elke lees-/schrijfactie laadt het hele JSON-bestand; prima tot een paar
  honderd/duizend leads, maar merkbaar trager dan een echte database bij
  veel data.
- Geen gelijktijdige schrijftransacties: bij drukte wachten requests kort
  op elkaars bestandslock. Voor het gebruikspatroon van deze app (één
  gebruiker die af en toe een scan start) is dat in de praktijk geen
  probleem.

Zie `../python-app/README.md` voor de volledige uitleg van de scoringlogica
("Hoe de score werkt") en de sectie "Belangrijk: verantwoord gebruik"
(robots.txt, fair use van Nominatim/Overpass, AVG/GDPR bij B2B-outreach) —
die is voor deze versie identiek.

## Projectstructuur

```
index.html, app.js, style.css   Dashboard-frontend (geen build-stap nodig)
config.sample.php               Kopieer naar config.php en vul in (niet in git)
cron_worker.php                 Verwerkt de scan-wachtrij in batches
data/                           data/db.json wordt hier automatisch aangemaakt
includes/
  bootstrap.php, helpers.php    Config, JSON-response helpers, cURL-wrapper
  jsondb.php                    Bestandsgebaseerde opslag met flock()-locking
  discovery.php                 OpenStreetMap-bedrijvenzoekopdracht
  analyzer.php                  Haalt een website op en extraheert veroudering-signalen
  scoring.php                   Zet signalen om in score + leesbare redenen
  tasks.php                     Wachtrijverwerking, gedeeld door API en cron
  leads.php                     Leads ophalen/filteren
api/                             Zelfde REST-achtige JSON-endpoints als php-app/
.htaccess                       Blokkeert directe toegang tot config.php/includes/data
```

## Lokaal testen (optioneel)

```bash
php -S localhost:8000
```

`config.php` moet aanwezig zijn (kopie van `config.sample.php`); de
`data/`-map wordt automatisch aangemaakt. Voor de wachtrij kun je
`cron_worker.php` handmatig herhaaldelijk aanroepen (`php cron_worker.php`
op de CLI, dat werkt zonder token) in plaats van een echte cronjob in te
stellen.
