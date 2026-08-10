# Marketplace-NL — Lead Scanner (PHP / MySQL, voor gewone webhosting)

Dit is de PHP + MySQL-versie van de app, gebouwd om te draaien op gewone
("shared") webhosting zoals TransIP WebHosting — dus géén VPS, géén SSH
nodig, géén Python. Werkt met alleen wat vrijwel elke PHP-hosting standaard
biedt: PHP 8.x, een MySQL/MariaDB-database, en cronjobs.

Functioneel is dit dezelfde app als de Python-versie in `../python-app`:
zoekt bedrijven in een Nederlandse plaats via OpenStreetMap, analyseert hun
website op verouderingssignalen, en toont een gefilterde/sorteerbare
leads-lijst met score en uitleg.

## Belangrijk verschil met de Python-versie: achtergrondtaken via cron

Shared hosting staat geen continu draaiend achtergrondproces toe (zoals de
Python-versie met threads doet). In plaats daarvan zet deze versie
openstaande scans in een wachtrij (`scan_queue`-tabel in de database), en
verwerkt een `cron_worker.php`-script die wachtrij in kleine batches — die
moet je zelf als cronjob inplannen (zie hieronder). Bij het starten van een
scan wordt meteen een eerste kleine batch synchroon verwerkt, zodat je
direct wat resultaten ziet; de rest komt binnen zodra de cron draait
(meestal binnen een paar minuten, afhankelijk van hoe vaak je 'm laat
draaien).

## Installatie op TransIP (of vergelijkbare shared hosting)

1. **Database aanmaken.** Maak in je hostingpaneel een MySQL-database aan
   (en een gebruiker met rechten daarop). Noteer host, databasenaam,
   gebruikersnaam en wachtwoord.
   TransIP's paneel noemt dit onderdeel doorgaans "MySQL databases" — als
   je het niet direct terugvindt, check de TransIP-documentatie/support,
   want de precieze indeling verschilt per hostingpakket.
2. **Schema importeren.** Open phpMyAdmin (of het databasebeheer dat je
   hostingpakket meelevert) voor die database en importeer `schema.sql`
   uit deze map.
3. **Bestanden uploaden.** Upload de hele inhoud van deze map (`php-app/`)
   via FTP/SFTP naar de webroot van je domein (vaak `public_html/` of
   `htdocs/`). Upload **niet** de map `python-app/` — die is niet nodig.
4. **Configureren.** Kopieer `config.sample.php` naar `config.php` op de
   server en vul in: databasegegevens, een contact-e-mailadres (wordt in
   de User-Agent van de crawler gezet), en een willekeurig `cron_token`
   (genereer er een met `php -r "echo bin2hex(random_bytes(16));"` of een
   willekeurige lange string).
5. **Cronjob instellen.** In je hostingpaneel (zoek naar "Cron jobs" /
   "Geplande taken") zet je een taak die elke 1–5 minuten draait. Twee
   opties, afhankelijk van wat je hosting ondersteunt:
   - **Als CLI-cron beschikbaar is** (commando-gebaseerd):
     `php /pad/naar/public_html/cron_worker.php`
   - **Als alleen URL-gebaseerde cron beschikbaar is:**
     `https://jouwdomein.nl/cron_worker.php?token=JOUW_CRON_TOKEN`
     (gebruik hier exact de waarde die je in `config.php` bij
     `cron_token` hebt gezet — zonder geldig token weigert het script).
6. **Testen.** Open `https://jouwdomein.nl/` in je browser (ook prima
   vanaf je telefoon) en start een scan.

## Gebruik

Zelfde als de Python-versie:
- **Scan starten**: kies stad + categorie (kapper, restaurant, loodgieter,
  advocaat, ...), de app zoekt bedrijven via OpenStreetMap en analyseert
  hun websites.
- **CSV importeren**: eigen lijst met bedrijven uploaden (`name, website`
  verplicht; `city, category, address, phone` optioneel).
- **Leads-tabel**: filteren op stad/categorie/status/score, status
  bijwerken, opnieuw scannen, exporteren naar CSV.

Zie ook de sectie "Hoe de score werkt" en "Belangrijk: verantwoord
gebruik" in `../python-app/README.md` — die scoringlogica en de
ethische/AVG-overwegingen zijn identiek in deze versie.

## Projectstructuur

```
index.html, app.js, style.css   Dashboard-frontend (geen build-stap nodig)
config.sample.php               Kopieer naar config.php en vul in (niet in git)
schema.sql                      MySQL-schema, eenmalig importeren
cron_worker.php                 Verwerkt de scan-wachtrij in batches
includes/
  bootstrap.php, helpers.php    Config/DB-verbinding, JSON-helpers, cURL-wrapper
  discovery.php                 OpenStreetMap-bedrijvenzoekopdracht
  analyzer.php                  Haalt een website op en extraheert veroudering-signalen
  scoring.php                   Zet signalen om in score + leesbare redenen
  tasks.php                     Wachtrijverwerking, gedeeld door API en cron
  leads.php                     Leads ophalen/filteren
api/
  categories.php, scan_discover.php, scan_status.php, leads.php,
  lead_detail.php, lead_update.php, lead_rescan.php, leads_import.php,
  export_csv.php                REST-achtige JSON-endpoints
.htaccess                       Blokkeert directe toegang tot config.php/includes/
```

## Lokaal testen (optioneel)

Met PHP's ingebouwde server en een lokale MySQL-server:

```bash
php -S localhost:8000
```

Zorg dat `config.php` naar een lokale database wijst waarin je
`schema.sql` hebt geïmporteerd. Voor de wachtrij kun je `cron_worker.php`
handmatig herhaaldelijk aanroepen (`php cron_worker.php` op de CLI, dat
werkt zonder token) in plaats van een echte cronjob in te stellen.
