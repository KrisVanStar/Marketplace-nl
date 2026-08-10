<?php
declare(strict_types=1);

/**
 * Turns raw signals (see analyzer.php) into a 0-100 "needs a new website"
 * score plus, for every problem found, a plain-language explanation of
 * what is wrong AND a concrete recommendation of what the site needs.
 *
 * Returns:
 *   score      int 0-100
 *   priority   high|medium|low|unreachable|no_website
 *   reasons    string[]  short labels (table + CSV)
 *   findings   array of ['code','title','severity','points','detail','recommendation']
 *   positives  string[]  what is already in order
 *   summary    string    one-sentence verdict
 */

function finding(string $code, string $title, string $severity, int $points, string $detail, string $recommendation): array
{
    return [
        'code' => $code,
        'title' => $title,
        'severity' => $severity,
        'points' => $points,
        'detail' => $detail,
        'recommendation' => $recommendation,
    ];
}

/** A business with no website at all: the strongest possible lead. */
function score_no_website(): array
{
    $findings = [
        finding(
            'no_website',
            'Dit bedrijf heeft helemaal geen website',
            'kritiek',
            100,
            'In OpenStreetMap staat geen website bij dit bedrijf. Klanten die online zoeken vinden '
            . 'wel de naam en locatie, maar geen informatie over diensten, prijzen of openingstijden.',
            'Een complete nieuwe website: één pagina met diensten, contactgegevens, openingstijden en '
            . 'een routebeschrijving is al genoeg om online vindbaar te worden. Combineer dit met een '
            . 'Google Bedrijfsprofiel voor lokale vindbaarheid.'
        ),
    ];

    return [
        'score' => 100,
        'priority' => 'no_website',
        'reasons' => ['Geen website gevonden — bedrijf is online onzichtbaar'],
        'findings' => $findings,
        'positives' => [],
        'summary' => 'Dit bedrijf heeft geen eigen website. Dit is de meest kansrijke lead: er is nog niets, dus alles kan worden opgebouwd.',
    ];
}

function score_site(array $signals): array
{
    if (!empty($signals['robots_disallowed'])) {
        return [
            'score' => 0,
            'priority' => 'unreachable',
            'reasons' => [$signals['error'] ?: 'Analyse geblokkeerd door robots.txt'],
            'findings' => [],
            'positives' => [],
            'summary' => 'De website verbiedt geautomatiseerde analyse. Beoordeel deze site handmatig.',
        ];
    }

    if (empty($signals['reachable']) || !empty($signals['error'])) {
        $error = $signals['error'] ?: 'Website niet bereikbaar';
        $findings = [
            finding(
                'unreachable',
                'De website is niet bereikbaar',
                'kritiek',
                0,
                "Bij het opvragen van de website ging het mis: {$error}",
                'Controleer handmatig of het adres nog klopt. Een domein dat niet meer werkt betekent '
                . 'meestal dat het bedrijf online onvindbaar is — een sterk verkoopargument voor een nieuwe site.'
            ),
        ];
        return [
            'score' => 0,
            'priority' => 'unreachable',
            'reasons' => [$error],
            'findings' => $findings,
            'positives' => [],
            'summary' => 'De website kon niet worden geladen. Vaak betekent dit een verlopen domein of een kapotte server — handmatig controleren.',
        ];
    }

    $findings = [];
    $positives = [];
    $year = current_year();

    // ---------- Veiligheid ------------------------------------------------
    if (empty($signals['is_https'])) {
        $findings[] = finding(
            'no_https',
            'Geen HTTPS: de verbinding is niet beveiligd',
            'kritiek',
            25,
            'De site draait nog op http://'
            . (!empty($signals['https_unavailable'])
                ? ' — een beveiligde https-versie is geprobeerd, maar die werkt niet.'
                : '.')
            . ' Browsers tonen bezoekers hierdoor de waarschuwing '
            . '"Niet veilig", en gegevens uit contactformulieren worden onversleuteld verstuurd.',
            'Installeer een SSL-certificaat (bij vrijwel elke hosting gratis via Let\'s Encrypt) en '
            . 'stel een automatische doorverwijzing van http naar https in.'
        );
    } else {
        $positives[] = 'Beveiligde verbinding (HTTPS) is actief';
        if (!empty($signals['mixed_content'])) {
            $findings[] = finding(
                'mixed_content',
                'Onveilige onderdelen op een beveiligde pagina',
                'gemiddeld',
                8,
                'De pagina wordt via https geladen, maar haalt afbeeldingen of scripts nog via http op. '
                . 'Browsers blokkeren die onderdelen of tonen alsnog een waarschuwing.',
                'Pas alle interne verwijzingen aan naar https:// zodat het slotje overal klopt.'
            );
        }
    }

    // ---------- Mobiel ----------------------------------------------------
    if (empty($signals['has_viewport_meta'])) {
        $findings[] = finding(
            'not_mobile_friendly',
            'Niet gemaakt voor mobiele telefoons',
            'kritiek',
            20,
            'Er ontbreekt een viewport-instelling, waardoor de site op een telefoon wordt weergegeven '
            . 'als een verkleinde desktopversie: bezoekers moeten in- en uitzoomen om iets te kunnen lezen. '
            . 'Ruim de helft van het lokale zoekverkeer komt van mobiel, en Google beoordeelt sites primair mobiel.',
            'Een responsive herbouw van de website, zodat de opmaak zich automatisch aanpast aan telefoon, '
            . 'tablet en desktop. Dit is doorgaans de belangrijkste en meest zichtbare verbetering.'
        );
    } else {
        $positives[] = 'Site is ingesteld voor mobiele weergave (responsive)';
    }

    // ---------- Verouderde techniek ---------------------------------------
    if (!empty($signals['uses_flash'])) {
        $findings[] = finding(
            'flash',
            'Gebruikt Adobe Flash',
            'kritiek',
            20,
            'De site bevat Flash-onderdelen. Flash wordt sinds eind 2020 door geen enkele browser meer '
            . 'ondersteund, dus deze onderdelen zijn voor iedere bezoeker onzichtbaar of kapot.',
            'Vervang Flash-onderdelen door moderne HTML5-alternatieven (video, animatie of gewone afbeeldingen).'
        );
    }

    if (!empty($signals['heavy_table_layout'])) {
        $findings[] = finding(
            'table_layout',
            'Opmaak met geneste tabellen (techniek uit de jaren 90/00)',
            'hoog',
            10,
            'De pagina-indeling is opgebouwd met in elkaar geschoven tabellen in plaats van moderne CSS. '
            . 'Dat maakt de site traag, slecht leesbaar op mobiel en lastig aan te passen.',
            'Herbouw de opmaak met een moderne CSS-indeling (flexbox/grid).'
        );
    }

    if (!empty($signals['deprecated_tags'])) {
        $tags = implode(', ', array_map(fn($t) => "<{$t}>", $signals['deprecated_tags']));
        $findings[] = finding(
            'deprecated_tags',
            'Verouderde HTML-elementen in gebruik',
            'gemiddeld',
            5,
            "De pagina gebruikt elementen die niet meer tot de webstandaard behoren: {$tags}.",
            'Vervang deze elementen tijdens een herbouw door moderne HTML en CSS.'
        );
    }

    if (empty($signals['doctype_html5'])) {
        $findings[] = finding(
            'no_html5',
            'Geen HTML5-doctype',
            'laag',
            5,
            'De pagina begint niet met het moderne <!doctype html>. Dit duidt meestal op een site die '
            . 'al meer dan tien jaar niet technisch is bijgewerkt.',
            'Zet de site om naar HTML5 (gebeurt automatisch bij een herbouw).'
        );
    }

    if (!empty($signals['outdated_platform'])) {
        $platform = $signals['platform'];
        $version = $signals['platform_version'];
        $findings[] = finding(
            'outdated_cms',
            "Verouderde {$platform}-versie ({$version})",
            'kritiek',
            15,
            "De site draait op {$platform} {$version}. Verouderde versies krijgen geen beveiligingsupdates "
            . 'meer en zijn een bekend doelwit voor automatische hacks (spam, malware, gijzeling van de site).',
            "Werk {$platform} bij naar de huidige versie, inclusief thema en plug-ins, en zet automatische "
            . 'beveiligingsupdates en back-ups aan. Bij een erg oude installatie is een schone herbouw vaak goedkoper dan migreren.'
        );
    } elseif (!empty($signals['platform'])) {
        $positives[] = "Draait op {$signals['platform']}" . ($signals['platform_version'] ? " {$signals['platform_version']}" : '');
    }

    if (!empty($signals['outdated_jquery'])) {
        $findings[] = finding(
            'outdated_jquery',
            "Verouderde jQuery-versie ({$signals['jquery_version']})",
            'gemiddeld',
            10,
            "De site laadt jQuery {$signals['jquery_version']}. In oudere versies zitten bekende "
            . 'beveiligingslekken (XSS) die via de browser van de bezoeker misbruikt kunnen worden.',
            'Werk jQuery bij naar versie 3.6 of hoger, of verwijder de afhankelijkheid tijdens een herbouw.'
        );
    }

    // ---------- Actualiteit ------------------------------------------------
    if ($signals['copyright_year'] !== null) {
        $age = $year - (int) $signals['copyright_year'];
        if ($age >= 2) {
            $findings[] = finding(
                'stale_copyright',
                "Copyright in de voettekst staat nog op {$signals['copyright_year']}",
                $age >= 5 ? 'hoog' : 'gemiddeld',
                min(15, 5 * $age),
                "De voettekst noemt {$signals['copyright_year']}, dus {$age} jaar geleden. Bezoekers lezen "
                . 'dit als "hier wordt niets meer bijgehouden" en twijfelen of het bedrijf nog actief is.',
                'Zet het jaartal automatisch bij (dan klopt het altijd) en loop tegelijk de inhoud na op '
                . 'verouderde prijzen, diensten en teamleden.'
            );
        }
    }

    if ($signals['wayback_years_stale'] !== null && $signals['wayback_years_stale'] >= 3) {
        $findings[] = finding(
            'stale_content',
            "Al {$signals['wayback_years_stale']} jaar geen zichtbare wijziging",
            'hoog',
            10,
            "Volgens het internetarchief is de site voor het laatst gewijzigd rond "
            . "{$signals['wayback_last_snapshot']}. De inhoud is dus jaren niet aangeraakt.",
            'Naast een technische opfrisbeurt is een inhoudelijke update nodig: actuele diensten, '
            . 'foto\'s, tarieven en teksten. Overweeg een CMS waarmee de ondernemer zelf kan bijwerken.'
        );
    }

    // ---------- Snelheid ---------------------------------------------------
    if ($signals['response_time_ms'] !== null && $signals['response_time_ms'] > 3000) {
        $findings[] = finding(
            'slow',
            "Trage laadtijd ({$signals['response_time_ms']} ms)",
            'hoog',
            10,
            "De server deed er {$signals['response_time_ms']} milliseconden over. Boven de 3 seconden "
            . 'haakt een groot deel van de mobiele bezoekers af voordat de pagina in beeld staat.',
            'Onderzoek de oorzaak: te zware afbeeldingen, trage hosting of te veel plug-ins. '
            . 'Vaak levert betere hosting plus geoptimaliseerde afbeeldingen direct winst op.'
        );
    } elseif ($signals['response_time_ms'] !== null && $signals['response_time_ms'] < 1000) {
        $positives[] = "Snelle reactietijd van de server ({$signals['response_time_ms']} ms)";
    }

    if ($signals['page_size_kb'] !== null && $signals['page_size_kb'] > 2000) {
        $findings[] = finding(
            'heavy_page',
            "Zware pagina ({$signals['page_size_kb']} kB)",
            'gemiddeld',
            5,
            "De startpagina is {$signals['page_size_kb']} kB groot. Op een mobiele verbinding kost dat "
            . 'onnodig veel laadtijd en databundel.',
            'Comprimeer afbeeldingen, gebruik moderne formaten (WebP) en laad afbeeldingen pas als ze in beeld komen.'
        );
    }

    if ($signals['pagespeed_mobile_score'] !== null && $signals['pagespeed_mobile_score'] < 50) {
        $findings[] = finding(
            'low_pagespeed',
            "Lage Google PageSpeed-score ({$signals['pagespeed_mobile_score']}/100 op mobiel)",
            'hoog',
            15,
            "Google beoordeelt de mobiele snelheid met {$signals['pagespeed_mobile_score']} van de 100. "
            . 'Snelheid telt mee in de zoekresultaten.',
            'Voer de aanbevelingen van PageSpeed Insights door: afbeeldingen optimaliseren, '
            . 'ongebruikte scripts verwijderen en caching inschakelen.'
        );
    }

    // ---------- Vindbaarheid (SEO) ------------------------------------------
    if ($signals['title'] === '') {
        $findings[] = finding(
            'no_title',
            'Geen paginatitel',
            'hoog',
            8,
            'De pagina heeft geen <title>. Dat is precies de blauwe, klikbare regel in Google — '
            . 'zonder titel is de site vrijwel onvindbaar.',
            'Geef elke pagina een titel met bedrijfsnaam, dienst en plaats, bijvoorbeeld '
            . '"Kapsalon Jansen — dameskapper in Utrecht".'
        );
    } elseif (mb_strlen($signals['title']) < 15) {
        $findings[] = finding(
            'short_title',
            'Erg korte paginatitel',
            'laag',
            3,
            "De titel is \"{$signals['title']}\" — te kort om in Google op lokale zoekopdrachten te scoren.",
            'Breid de titel uit met de belangrijkste dienst en de plaatsnaam.'
        );
    } else {
        $positives[] = 'Pagina heeft een titel voor de zoekresultaten';
    }

    if ($signals['meta_description'] === '') {
        $findings[] = finding(
            'no_meta_description',
            'Geen omschrijving voor de zoekresultaten',
            'gemiddeld',
            6,
            'Er is geen meta-description. Google verzint dan zelf een stukje tekst onder de zoekresultaat-link, '
            . 'wat vaak rommelig overkomt en minder klikken oplevert.',
            'Schrijf per pagina een wervende omschrijving van ongeveer 150 tekens met dienst, plaats en een reden om te klikken.'
        );
    } else {
        $positives[] = 'Omschrijving voor zoekresultaten aanwezig';
    }

    if ((int) $signals['h1_count'] === 0) {
        $findings[] = finding(
            'no_h1',
            'Geen duidelijke hoofdkop op de pagina',
            'laag',
            4,
            'De pagina bevat geen <h1>. Zoekmachines gebruiken die kop om te bepalen waar de pagina over gaat.',
            'Geef elke pagina één duidelijke hoofdkop met de belangrijkste dienst en plaats.'
        );
    }

    if (empty($signals['has_structured_data'])) {
        $findings[] = finding(
            'no_structured_data',
            'Geen gestructureerde bedrijfsgegevens',
            'laag',
            4,
            'Er staat geen schema.org-informatie in de code. Daarmee kunnen adres, openingstijden en '
            . 'beoordelingen direct in de zoekresultaten worden getoond.',
            'Voeg LocalBusiness-opmaak toe met naam, adres, telefoonnummer en openingstijden.'
        );
    }

    if (empty($signals['has_open_graph'])) {
        $findings[] = finding(
            'no_open_graph',
            'Deelt slecht op sociale media',
            'laag',
            3,
            'Zonder Open Graph-gegevens toont een gedeelde link op Facebook, WhatsApp of LinkedIn '
            . 'geen afbeelding of nette omschrijving.',
            'Voeg Open Graph-gegevens toe (titel, omschrijving en een deelafbeelding).'
        );
    }

    if ($signals['image_count'] > 0 && $signals['images_without_alt'] >= max(3, (int) ($signals['image_count'] * 0.5))) {
        $findings[] = finding(
            'images_without_alt',
            "Afbeeldingen zonder alt-tekst ({$signals['images_without_alt']} van {$signals['image_count']})",
            'laag',
            4,
            'De meeste afbeeldingen hebben geen alternatieve tekst. Slechtzienden met een schermlezer '
            . 'missen die informatie en Google kan de afbeeldingen niet plaatsen.',
            'Geef elke inhoudelijke afbeelding een korte, beschrijvende alt-tekst. '
            . 'Dit is ook een vereiste vanuit digitale toegankelijkheid.'
        );
    }

    if (empty($signals['has_favicon'])) {
        $findings[] = finding(
            'no_favicon',
            'Geen favicon',
            'laag',
            2,
            'De site heeft geen klein pictogram voor het browsertabblad, waardoor hij tussen open '
            . 'tabbladen en bladwijzers naamloos oogt.',
            'Voeg een favicon toe op basis van het logo.'
        );
    }

    // ---------- Ondernemersvoordeel -----------------------------------------
    if (empty($signals['has_contact_form'])) {
        $findings[] = finding(
            'no_contact_form',
            'Geen contactformulier',
            'gemiddeld',
            5,
            'Er staat geen formulier op de startpagina. Bezoekers moeten zelf bellen of een mail opstellen, '
            . 'wat aantoonbaar minder aanvragen oplevert.',
            'Plaats een kort contact- of offerteformulier (naam, e-mail, vraag) op een goed zichtbare plek.'
        );
    } else {
        $positives[] = 'Bezoekers kunnen via een formulier contact opnemen';
    }

    if (empty($signals['has_analytics'])) {
        $findings[] = finding(
            'no_analytics',
            'Geen bezoekersstatistieken',
            'laag',
            3,
            'Er is geen statistiekprogramma actief. De ondernemer weet dus niet hoeveel bezoekers er komen, '
            . 'waar ze vandaan komen en wat ze doen.',
            'Installeer een privacyvriendelijke statistiektool en koppel die aan de aanvragen, '
            . 'zodat het rendement van de site aantoonbaar wordt.'
        );
    } else {
        $positives[] = 'Bezoekersstatistieken actief (' . implode(', ', $signals['analytics_tools']) . ')';
    }

    if (empty($signals['has_map_embed'])) {
        $findings[] = finding(
            'no_map',
            'Geen kaart of routebeschrijving',
            'laag',
            2,
            'Er staat geen kaart op de startpagina, terwijl klanten bij een lokaal bedrijf vaak juist '
            . 'zoeken naar de locatie en route.',
            'Voeg een kaart met routebeschrijving toe, plus adres en openingstijden in tekstvorm.'
        );
    }

    if (empty($signals['social_links'])) {
        $findings[] = finding(
            'no_socials',
            'Geen koppeling met sociale media',
            'laag',
            2,
            'Er staan geen verwijzingen naar sociale media op de site, terwijl veel lokale bedrijven daar wel actief zijn.',
            'Verwijs naar de actieve sociale kanalen en toon eventueel recente berichten op de site.'
        );
    } else {
        $positives[] = 'Gekoppeld aan sociale media (' . implode(', ', array_keys($signals['social_links'])) . ')';
    }

    // ---------- Totaal --------------------------------------------------------
    $points = array_sum(array_column($findings, 'points'));
    $score = max(0, min(100, $points));

    $severityRank = ['kritiek' => 0, 'hoog' => 1, 'gemiddeld' => 2, 'laag' => 3];
    usort($findings, function ($a, $b) use ($severityRank) {
        $cmp = ($severityRank[$a['severity']] ?? 9) <=> ($severityRank[$b['severity']] ?? 9);
        return $cmp !== 0 ? $cmp : ($b['points'] <=> $a['points']);
    });

    if ($score >= 55) {
        $priority = 'high';
    } elseif ($score >= 30) {
        $priority = 'medium';
    } else {
        $priority = 'low';
    }

    $critical = count(array_filter($findings, fn($f) => $f['severity'] === 'kritiek'));
    if (empty($findings)) {
        $summary = 'Deze website is technisch in orde. Weinig kans op een opdracht voor een nieuwe site.';
    } elseif ($priority === 'high') {
        $summary = "Sterke kandidaat: " . count($findings) . ' verbeterpunten gevonden'
            . ($critical > 0 ? ", waarvan {$critical} kritiek" : '') . '.';
    } elseif ($priority === 'medium') {
        $summary = 'Redelijke kandidaat: de site werkt, maar loopt op ' . count($findings)
            . ' punten achter op wat vandaag gebruikelijk is.';
    } else {
        $summary = 'De site is grotendeels op orde; alleen kleine verbeterpunten gevonden.';
    }

    return [
        'score' => $score,
        'priority' => $priority,
        'reasons' => array_column($findings, 'title'),
        'findings' => $findings,
        'positives' => $positives,
        'summary' => $summary,
    ];
}
