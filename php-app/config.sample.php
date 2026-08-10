<?php
// Copy this file to config.php and fill in your hosting details.
// config.php itself should NOT be committed to git (it's in .gitignore).

return [
    // MySQL database credentials from your hosting control panel.
    'db_host' => 'localhost',
    'db_name' => 'CHANGE_ME',
    'db_user' => 'CHANGE_ME',
    'db_pass' => 'CHANGE_ME',

    // Optional: Google PageSpeed Insights API key for a real mobile
    // performance score. Leave empty to skip that signal.
    'pagespeed_api_key' => '',

    // Shown in the crawler's User-Agent so site owners can see who is
    // visiting their site and why, and can ask to be excluded.
    'crawler_contact_email' => 'you@example.com',

    // Random secret used to protect cron_worker.php from being triggered
    // by random visitors when your host only supports URL-based cron jobs.
    // Generate one with: php -r "echo bin2hex(random_bytes(16));"
    'cron_token' => 'CHANGE_ME_TO_A_RANDOM_STRING',

    // --- Advanced (optional) ------------------------------------------
    // Overpass mirrors, tried in order until one answers. The defaults
    // below are used when this is left out. Override only if the public
    // servers are unreliable for you or you run your own instance.
    // 'overpass_endpoints' => [
    //     'https://overpass-api.de/api/interpreter',
    //     'https://overpass.kumi.systems/api/interpreter',
    //     'https://overpass.osm.ch/api/interpreter',
    // ],

    // Geocoding endpoint (place name -> map area).
    // 'nominatim_url' => 'https://nominatim.openstreetmap.org/search',
];
