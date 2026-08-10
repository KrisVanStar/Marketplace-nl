# Marketplace-NL — Lead Scanner

Vindt kleine bedrijven in Nederland met een verouderde website — of helemaal
geen website — en levert per bedrijf een verkoopklaar rapport:

- **wie het bedrijf is** (adres, telefoon, e-mail, openingstijden, sociale media),
- **hoe de website ervoor staat** (beveiliging, mobiel, CMS, snelheid, SEO),
- **wat er mis is**, met ernst en uitleg in gewone taal,
- **wat die site nodig heeft**, als concrete aanbeveling per probleem.

Bedrijven zonder website worden meegenomen en bovenaan gezet: dat zijn de
sterkste leads.

De app staat in **[`php-json-app/`](php-json-app/)** en draait op gewone
webhosting (zoals TransIP): alleen PHP 8 en een cronjob nodig, geen database —
alle gegevens staan in één JSON-bestand dat de app zelf beheert.

## Aan de slag

Zie **[php-json-app/README.md](php-json-app/README.md)** voor de volledige
installatie-instructies. In het kort:

1. Upload de inhoud van `php-json-app/` naar je webruimte.
2. Kopieer `config.sample.php` naar `config.php` en vul je gegevens in.
3. Open `check.php` in je browser — die controleert de hele installatie en
   zegt per punt wat er nog moet gebeuren.
4. Stel de cronjob in en begin met zoeken via `index.html`.

## Werkt er iets niet?

Open **`check.php`** op je server (bijvoorbeeld
`https://jouwdomein.nl/check.php`). Die controleert PHP-versie en extensies,
of `config.php` klopt, of de map `data/` beschrijfbaar is, en of je hosting
OpenStreetMap kan bereiken — met per probleem de oplossing erbij.
