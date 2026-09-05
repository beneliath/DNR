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
$geocoder_status = $read('src/map_geocode_status.php');
$geocoder_worker = $read('scripts/process_geocode_queue.php');
$map_helpers = $read('src/map_helpers.php');
$new_engagement = $read('src/index.php');
$edit_engagement = $read('src/edit_engagement.php');
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
    str_contains($map_page, 'class="map-body"')
        && str_contains($map_page, '<main class="container map-page">')
        && str_contains($map_page, 'class="page-heading map-heading"')
        && preg_match('/\.map-page\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);[^}]*padding-inline:\s*var\(--app-content-padding\);/s', $map_styles) === 1
        && preg_match('/\.map-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $map_styles) === 1
        && preg_match('/\.map-body \.app-footer\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $map_styles) === 1,
    'the Map page should use the Dashboard canvas width, heading scale, and footer alignment.'
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
    str_contains($map_helpers, 'applicationBusinessDate($instant)')
        && str_contains($map_helpers, 'engagementMapDateAfterMonths($default_date_from, 3)')
        && !str_contains($map_helpers, "applicationWorkflowSetting('map_past_days')")
        && !str_contains($map_helpers, "applicationWorkflowSetting('map_future_days')"),
    'the initial Map window should run from today through three calendar months later.'
);
expectMapFeature(
    str_contains($map_script, 'Map as MapLibreMap')
        && str_contains($map_script, 'new MapLibreMap({')
        && str_contains($map_script, 'new NavigationControl({showCompass: false})')
        && str_contains($map_script, 'fitMapToPins')
        && str_contains($map_script, 'new Marker({element: markerElement'),
    'the graphical map should provide colored pins, zoom, pan, touch zoom, and fit-to-pins.'
);
expectMapFeature(
    str_contains($map_styles, '.status-work-in-progress-pin')
        && str_contains($map_styles, '.status-under-review-pin')
        && str_contains($map_styles, '.status-confirmed-pin'),
    'each engagement status should have its own pin color.'
);
expectMapFeature(
    str_contains($map_styles, '.maplibregl-ctrl-group')
        && str_contains($map_styles, 'display: flex;')
        && str_contains($map_styles, 'flex-direction: row-reverse;')
        && str_contains($map_styles, 'gap: 6px;')
        && str_contains($map_styles, 'box-shadow: none !important;')
        && str_contains($map_styles, '.maplibregl-ctrl-group button')
        && str_contains($map_styles, 'border: 0 !important;')
        && str_contains($map_styles, 'background: var(--control-hover-bg) !important;')
        && str_contains($map_styles, 'outline: 2px solid var(--control-hover-border);')
        && str_contains($map_styles, 'html.dark-mode .maplibregl-ctrl-group button .maplibregl-ctrl-icon,')
        && str_contains($map_styles, 'filter: invert(1);'),
    'MapLibre zoom controls should use the project surface, hover treatment, and visible dark-mode icons.'
);
expectMapFeature(
    str_contains($map_styles, '.maplibregl-popup-content')
        && preg_match('/\.maplibregl-popup,\s*\.maplibregl-popup-tip\s*\{[^}]*background:\s*transparent\s*!important;[^}]*background-color:\s*transparent\s*!important;/s', $map_styles) === 1
        && str_contains($map_styles, 'background: var(--surface) !important;')
        && str_contains($map_styles, 'color: var(--text) !important;')
        && str_contains($map_styles, '.maplibregl-popup-close-button')
        && preg_match('/\.map-popup\s*\{[^}]*background:\s*transparent\s*!important;[^}]*background-color:\s*transparent\s*!important;/s', $map_styles) === 1
        && str_contains($map_styles, '.map-popup-organization,')
        && str_contains($map_styles, 'color: var(--text-muted);'),
    'Map popups should use theme-aware surfaces, readable text, and a compact close control.'
);
expectMapFeature(
    str_contains($map_styles, '.maplibregl-ctrl-attrib {')
        && str_contains($map_styles, 'background: color-mix(in srgb, var(--surface) 92%, transparent) !important;')
        && str_contains($map_styles, '.maplibregl-ctrl-attrib a {')
        && str_contains($map_styles, 'color: var(--text-muted) !important;'),
    'Map attribution should use a compact theme-aware label instead of MapLibre defaults.'
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
    str_contains($map_styles, '.map-shell .maplibregl-control-container,')
        && str_contains($map_styles, '.map-shell .maplibregl-ctrl-top-left,')
        && str_contains($map_styles, 'background-color: transparent !important;'),
    'MapLibre control containers should not paint a panel over the map in either theme.'
);
expectMapFeature(
    str_contains($map_page, 'assets/css/modern.min.css?rev=consistent-control-geometry-1')
        && str_contains($map_page, 'assets/css/map.min.css?rev=maplibre-theme-surfaces-3'),
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
    str_contains($map_styles, '.engagement-map[data-map-provider="openstreetmap"] .maplibregl-canvas')
        && str_contains($map_styles, 'saturate(0.62)')
        && str_contains($map_styles, 'opacity: 0.82;'),
    'the basemap should use a lighter, lower-saturation treatment that matches the application theme.'
);
expectMapFeature(
    preg_match('/@media \(max-width: 760px\).*?\.map-filters\s*\{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\);/s', $map_styles) === 1
        && preg_match('/@media \(max-width: 760px\).*?\.map-date-window\s*\{[^}]*display:\s*block;[^}]*width:\s*100%\s*!important;[^}]*max-width:\s*100%\s*!important;[^}]*inline-size:\s*100%\s*!important;[^}]*max-inline-size:\s*100%\s*!important;/s', $map_styles) === 1
        && preg_match('/@media \(max-width: 760px\).*?\.map-date-window > \.map-filter-field\s*\{[^}]*width:\s*100%\s*!important;[^}]*max-width:\s*100%\s*!important;[^}]*inline-size:\s*100%\s*!important;[^}]*max-inline-size:\s*100%\s*!important;/s', $map_styles) === 1
        && preg_match('/@media \(max-width: 760px\).*?\.map-filter-field input\[type="date"\]\s*\{[^}]*width:\s*100%\s*!important;[^}]*max-width:\s*100%\s*!important;[^}]*inline-size:\s*100%\s*!important;[^}]*max-inline-size:\s*100%\s*!important;[^}]*appearance:\s*none;[^}]*-webkit-appearance:\s*none;/s', $map_styles) === 1,
    'phone filters should stay compact and date controls should remain inside the viewport.'
);
expectMapFeature(
    str_contains($geocoder, 'requireValidCsrfToken')
        && str_contains($geocoder, 'queueEngagementMapAddresses')
        && str_contains($geocoder, 'engagement_ids')
        && str_contains($geocoder_status, 'engagementMapLocationStatuses')
        && !str_contains($geocoder_status, 'queueEngagementMapAddress')
        && str_contains($geocoder_status, 'releaseApplicationSessionLock')
        && !str_contains($geocoder, 'file_get_contents($geocoder_url')
        && str_contains($geocoder_worker, 'usleep(1100000)')
        && str_contains($geocoder_worker, 'FOR UPDATE SKIP LOCKED')
        && str_contains($geocoder_worker, 'processing_started_at')
        && str_contains($geocoder_worker, "? 'failed' : 'retry'")
        && str_contains($geocoder_worker, 'WHERE attempts < {$maximum_attempts}')
        && str_contains($geocoder_worker, 'Maximum geocoding attempts reached')
        && str_contains($map_helpers, 'validatedGeocoderBaseUrl')
        && str_contains($map_helpers, 'DNR_GEOCODER_ALLOWED_HOSTS')
        && str_contains($map_helpers, 'CURLOPT_RESOLVE')
        && str_contains($map_helpers, 'CURLINFO_PRIMARY_IP')
        && str_contains($map_helpers, 'CURLOPT_PROTOCOLS => CURLPROTO_HTTPS')
        && str_contains($map_helpers, 'completeEngagementMapGeocodeJob')
        && str_contains($migration, 'engagement_map_geocodes')
        && str_contains($hardening_migration, 'engagement_map_geocode_queue'),
    'web requests should enqueue lookups while the worker reclaims stale jobs, dead-letters exhausted work, rate-limits, and caches outbound geocoding.'
);
expectMapFeature(
    str_contains($security_headers, 'deploymentConfig()->tileCspSource()')
        && str_contains($security_headers, "worker-src 'self' blob:")
        && str_contains($map_page, "'mapProvider' => [")
        && str_contains($map_script, 'const style = {')
        && str_contains($apache, 'Referrer-Policy "strict-origin-when-cross-origin"'),
    'the security policy should allow the configured OpenStreetMap tile host, MapLibre worker, and origin-only Referer.'
);
expectMapFeature(
    str_contains($map_page, "'locationLookup' => [")
        && str_contains($map_page, "'enqueueUrl' => 'map_geocode.php'")
        && str_contains($map_page, "'statusUrl' => 'map_geocode_status.php'")
        && str_contains($map_page, "'csrfToken' => \$map_csrf_token")
        && str_contains($map_script, 'pollPendingLocations')
        && str_contains($map_script, 'requestLocationBatch')
        && str_contains($map_script, 'engagement_ids: Array.from(pendingEvents.keys()).join')
        && str_contains($map_script, 'Math.random()')
        && str_contains($map_script, 'maximumPollIntervalMilliseconds')
        && !str_contains($map_script, 'Promise.all(lookups)')
        && str_contains($map_script, 'pendingEvents.delete(eventId)')
        && str_contains($map_script, 'fitMapToPins();'),
    'an open Map page should use one bulk enqueue plus batched, backed-off status polls.'
);

expectMapFeature(
    str_contains($map_helpers, 'queueEngagementMapAddresses')
        && str_contains($map_helpers, 'bool $retry_failed = false')
        && str_contains($map_helpers, "IF(status = 'failed', 0, attempts)")
        && strpos($map_helpers, "IF(status = 'failed', 0, attempts)")
            > strpos($map_helpers, 'if ($retry_failed)'),
    'normal map refreshes should preserve retry and failed state unless a retry is explicit.'
);

expectMapFeature(
    str_contains($new_engagement, 'queueEngagementMapAddress($conn, $map_address, true)')
        && str_contains($edit_engagement, 'queueEngagementMapAddress($conn, $map_address, true)'),
    'an explicit create or edit submission should revive a terminal geocoding failure.'
);

echo "Map feature tests passed.\n";
