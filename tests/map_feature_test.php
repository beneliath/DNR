<?php

function expectMapFeature($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Map feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static function ($path) use ($root) {
    $contents = file_get_contents($root . '/' . $path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$path}");
    }
    return $contents;
};

$header = $read('src/templates/header.php');
$map_page = $read('src/map.php');
$map_script = $read('src/assets/js/map.js');
$map_styles = $read('src/assets/css/map.css');
$modern_styles = $read('src/assets/css/modern.css');
$geocoder = $read('src/map_geocode.php');
$geocoder_worker = $read('scripts/process_geocode_queue.php');
$map_helpers = $read('src/map_helpers.php');
$migration = $read('migrations/20260817_add_engagement_map.sql');
$hardening_migration = $read('migrations/20260821_security_performance_hardening.sql');
$apache = $read('docker/apache-security.conf');
$security_headers = $read('src/functions.php');

expectMapFeature(
    str_contains($header, "'map' => ['map.php']")
        && str_contains($header, 'href="map.php"')
        && str_contains($header, '<span>Map</span>'),
    'the application sidebar should expose an active Map destination.'
);
expectMapFeature(
    str_contains($map_page, "e.confirmation_status = ?")
        && str_contains($map_page, "e.lifecycle_status = ?")
        && str_contains($map_page, 'e.event_end_date >= ?')
        && str_contains($map_page, 'e.event_start_date <= ?')
        && str_contains($map_page, 'All statuses')
        && str_contains($map_page, 'All lifecycle states')
        && str_contains($map_page, '<fieldset class="map-date-window">')
        && str_contains($map_page, '<legend>Date window</legend>'),
    'the Map page should filter statuses and events that overlap the selected date window.'
);
expectMapFeature(
    str_contains($map_script, 'scrollWheelZoom: true')
        && str_contains($map_script, 'dragging: true')
        && str_contains($map_script, 'touchZoom: true')
        && str_contains($map_script, 'zoomControl: false')
        && str_contains($map_script, "L.control({position: 'topleft'})")
        && str_contains($map_script, "L.DomUtil.create('button', 'map-zoom-button button-secondary'")
        && str_contains($map_script, 'fitMapToPins')
        && str_contains($map_script, 'L.marker'),
    'the graphical map should provide colored pins, zoom, pan, touch zoom, and fit-to-pins.'
);
expectMapFeature(
    str_contains($map_styles, '.status-work-in-progress-pin')
        && str_contains($map_styles, '.status-under-review-pin')
        && str_contains($map_styles, '.status-confirmed-pin'),
    'each engagement status should have its own pin color.'
);
expectMapFeature(
    str_contains($map_styles, '.map-zoom-controls')
        && str_contains($map_styles, '.map-zoom-button')
        && str_contains($map_styles, 'gap: 8px;')
        && str_contains($map_styles, 'background: transparent !important;')
        && str_contains($map_styles, 'background-color: transparent !important;')
        && str_contains($map_styles, 'border-color: var(--control-hover-border) !important;'),
    'zoom controls should be spaced apart and use a transparent surface with the project hover treatment.'
);
expectMapFeature(
    preg_match('/html body main\.container,[^{]*\{[^}]*background-color:\s*transparent\s*!important;/s', $modern_styles) === 1,
    'the Map page root should reveal the shared app background instead of the legacy black container surface.'
);
expectMapFeature(
    preg_match('/\\.map-shell\\s*\\{[^}]*background:\\s*transparent\\s*!important;[^}]*background-color:\\s*transparent\\s*!important;/s', $map_styles) === 1
        && preg_match('/\\.map-toolbar\\s*\\{[^}]*background:\\s*transparent\\s*!important;/s', $map_styles) === 1
        && preg_match('/\\.map-toolbar > div\\s*\\{[^}]*background:\\s*transparent\\s*!important;[^}]*background-color:\\s*transparent\\s*!important;/s', $map_styles) === 1
        && preg_match('/\\.map-legend\\s*\\{[^}]*background:\\s*transparent\\s*!important;/s', $map_styles) === 1
        && preg_match('/\\.map-attribution-note,[^{]*\\{[^}]*background:\\s*transparent\\s*!important;/s', $map_styles) === 1,
    'the map header, legend, and attribution footer should reveal the surrounding page background.'
);
expectMapFeature(
    str_contains($map_styles, 'html.dark-mode .map-shell .leaflet-control-container,')
        && str_contains($map_styles, 'html.dark-mode .map-shell .leaflet-top,')
        && str_contains($map_styles, 'html.dark-mode .map-shell .map-zoom-controls {')
        && str_contains($map_styles, 'background-color: transparent !important;'),
    'dark-theme Leaflet control containers should not paint a panel over the map tiles.'
);
expectMapFeature(
    str_contains($map_page, 'assets/css/modern.min.css?rev=consistent-control-geometry-1')
        && str_contains($map_page, 'assets/css/map.min.css?rev=dark-controls-layout-11'),
    'the Map page should invalidate previously immutable shared and map styles for control geometry fixes.'
);
expectMapFeature(
    preg_match('/\.map-filter-field select,\s*\.map-filter-field input\s*\{[^}]*min-height:\s*42px;[^}]*margin:\s*0;/s', $map_styles) === 1
        && !str_contains($modern_styles, 'html.dark-mode input[type="date"],')
        && !str_contains($modern_styles, 'html.dark-mode select,')
        && !str_contains($map_styles, 'html.dark-mode .map-filter-actions'),
    'light and dark map controls should share the same geometry cascade and alignment.'
);
expectMapFeature(
    preg_match('/\.map-filter-actions \.button-secondary[^{]*\{[^}]*text-decoration:\s*none(?:\s*!important)?;/s', $map_styles) === 1,
    'the Clear filter action should look like a button without link underlining.'
);
expectMapFeature(
    str_contains($map_styles, '.engagement-map .leaflet-tile-pane')
        && str_contains($map_styles, 'saturate(0.62)')
        && str_contains($map_styles, 'opacity: 0.82;'),
    'the basemap should use a lighter, lower-saturation treatment that matches the application theme.'
);
expectMapFeature(
    preg_match('/@media \(max-width: 620px\).*?\.map-filters\s*\{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\);/s', $map_styles) === 1
        && preg_match('/@media \(max-width: 620px\).*?\.map-date-window\s*\{[^}]*display:\s*block;[^}]*width:\s*100%\s*!important;[^}]*max-width:\s*100%\s*!important;[^}]*inline-size:\s*100%\s*!important;[^}]*max-inline-size:\s*100%\s*!important;/s', $map_styles) === 1
        && preg_match('/@media \(max-width: 620px\).*?\.map-date-window > \.map-filter-field\s*\{[^}]*width:\s*100%\s*!important;[^}]*max-width:\s*100%\s*!important;[^}]*inline-size:\s*100%\s*!important;[^}]*max-inline-size:\s*100%\s*!important;/s', $map_styles) === 1
        && preg_match('/@media \(max-width: 620px\).*?\.map-filter-field input\[type="date"\]\s*\{[^}]*width:\s*100%\s*!important;[^}]*max-width:\s*100%\s*!important;[^}]*inline-size:\s*100%\s*!important;[^}]*max-inline-size:\s*100%\s*!important;[^}]*appearance:\s*none;[^}]*-webkit-appearance:\s*none;/s', $map_styles) === 1,
    'phone filters should stay compact and date controls should remain inside the viewport.'
);
expectMapFeature(
    str_contains($geocoder, 'requireValidCsrfToken')
        && str_contains($geocoder, 'queueEngagementMapAddress')
        && !str_contains($geocoder, 'file_get_contents($geocoder_url')
        && str_contains($geocoder_worker, 'usleep(1100000)')
        && str_contains($geocoder_worker, 'FOR UPDATE SKIP LOCKED')
        && str_contains($geocoder_worker, 'processing_started_at')
        && str_contains($geocoder_worker, "? 'failed' : 'retry'")
        && str_contains($geocoder_worker, 'WHERE attempts < {$maximum_attempts}')
        && str_contains($geocoder_worker, 'Maximum geocoding attempts reached')
        && str_contains($map_helpers, 'validatedGeocoderBaseUrl')
        && str_contains($map_helpers, 'DNR_GEOCODER_ALLOWED_HOSTS')
        && str_contains($map_helpers, 'http_get_last_response_headers()')
        && !str_contains($map_helpers, '$http_response_header')
        && str_contains($map_helpers, 'completeEngagementMapGeocodeJob')
        && str_contains($migration, 'engagement_map_geocodes')
        && str_contains($hardening_migration, 'engagement_map_geocode_queue'),
    'web requests should enqueue lookups while the worker reclaims stale jobs, dead-letters exhausted work, rate-limits, and caches outbound geocoding.'
);
expectMapFeature(
    str_contains($security_headers, "https://tile.openstreetmap.org")
        && str_contains($apache, 'Referrer-Policy "strict-origin-when-cross-origin"'),
    'the security policy should allow the tile host and send only the site origin as its required Referer.'
);

echo "Map feature tests passed.\n";
