<?php
require_once __DIR__ . '/bootstrap.php';
$conn = applicationDatabaseConnection();
include 'map_helpers.php';
startSecureSession();
requireLogin();
$map_csrf_token = generateCsrfToken();
releaseApplicationSessionLock();

$filters = normalizeEngagementMapFilters($_GET);
$status_labels = engagementMapStatuses();
$lifecycle_labels = engagementMapLifecycles();
$clauses = ['e.is_deleted = 0'];
$parameters = [];
$parameter_types = '';

if ($filters['lifecycle'] !== '') {
    $clauses[] = 'e.lifecycle_status = ?';
    $parameters[] = $filters['lifecycle'];
    $parameter_types .= 's';
}

if ($filters['status'] !== '') {
    $clauses[] = 'e.confirmation_status = ?';
    $parameters[] = $filters['status'];
    $parameter_types .= 's';
}
if ($filters['date_from'] !== '') {
    // Include events already in progress at the beginning of the window.
    $clauses[] = 'e.event_end_date >= ?';
    $parameters[] = $filters['date_from'];
    $parameter_types .= 's';
}
if ($filters['date_to'] !== '') {
    // Include events that begin before the end of the window.
    $clauses[] = 'e.event_start_date <= ?';
    $parameters[] = $filters['date_to'];
    $parameter_types .= 's';
}

$map_event_limit = applicationWorkflowSetting('map_max_events');
$usable_address_clause = "COALESCE(
    NULLIF(TRIM(e.event_address_line_1), ''),
    NULLIF(TRIM(e.event_address_line_2), ''),
    NULLIF(TRIM(e.event_city), ''),
    NULLIF(TRIM(e.event_state), ''),
    NULLIF(TRIM(e.event_zipcode), '')
) IS NOT NULL";
$location_filter = \Dnr\Http\RequestInput::enum($_GET, 'location', ['all', 'needs_address', 'with_address'], 'all');
if ($location_filter === 'needs_address') {
    $clauses[] = "NOT ({$usable_address_clause})";
} elseif ($location_filter === 'with_address') {
    $clauses[] = $usable_address_clause;
}
$map_count_stmt = $conn->prepare('SELECT COUNT(*) AS total FROM engagements e WHERE ' . implode(' AND ', $clauses));
if ($parameters !== []) {
    $map_count_stmt->bind_param($parameter_types, ...$parameters);
}
$map_count_stmt->execute();
$map_total = (int) $map_count_stmt->get_result()->fetch_assoc()['total'];
$map_count_stmt->close();
$map_pages = max(1, (int) ceil($map_total / $map_event_limit));
$map_page = min($map_pages, max(1, (int) filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT)));
$map_offset = ($map_page - 1) * $map_event_limit;
$map_context = array_intersect_key($filters, array_flip(['lifecycle', 'status', 'date_from', 'date_to']));
$map_context['location'] = $location_filter;
$map_context['page'] = $map_page;
$map_return = 'map.php?' . http_build_query($map_context);
$can_edit_map = in_array((string) ($_SESSION['role'] ?? ''), ['admin', 'editor'], true);
$engagement_sql = "SELECT
        e.id,
        e.event_title,
        e.event_start_date,
        e.event_end_date,
        e.confirmation_status,
        e.lifecycle_status,
        e.event_address_line_1,
        e.event_address_line_2,
        e.event_city,
        e.event_state,
        e.event_zipcode,
        e.event_country,
        o.organization_name
    FROM engagements e
    LEFT JOIN organizations o ON o.id = e.organization_id
    WHERE " . implode(' AND ', $clauses) . "
    ORDER BY e.event_start_date ASC, e.id ASC
    LIMIT " . ($map_event_limit + 1) . " OFFSET " . $map_offset;

$engagement_stmt = $conn->prepare($engagement_sql);
if (!$engagement_stmt) {
    applicationLog('error', 'Unable to prepare the engagement map', ['error' => $conn->error]);
    http_response_code(503);
    exit('The engagement map is temporarily unavailable.');
}
if ($parameters !== []) {
    $bind_arguments = [$parameter_types];
    foreach ($parameters as &$parameter) {
        $bind_arguments[] = &$parameter;
    }
    unset($parameter);
    $engagement_stmt->bind_param(...$bind_arguments);
}
if (!$engagement_stmt->execute()) {
    applicationLog('error', 'Unable to load map engagements', ['error' => $engagement_stmt->error]);
    http_response_code(500);
    exit('Unable to load engagement locations.');
}
$engagement_result = $engagement_stmt->get_result();
$engagement_rows = [];
$map_results_truncated = false;
while ($row = $engagement_result->fetch_assoc()) {
    if (count($engagement_rows) >= $map_event_limit) {
        $map_results_truncated = true;
        break;
    }
    $address = engagementMapAddress($row);
    $row['_map_address'] = $address;
    $engagement_rows[] = $row;
}
$engagement_stmt->close();

$location_states = [];
foreach (engagementMapLocationStatuses($conn, array_map(static fn ($row) => (int) $row['id'], $engagement_rows)) as $location) {
    $location_states[$location['id']] = $location;
}

$map_events = [];
$cached_pin_count = 0;
$pending_geocode_count = 0;
$not_found_count = 0;
$page_without_addresses = 0;
foreach ($engagement_rows as $row) {
    $location = $location_states[(int) $row['id']];
    $has_coordinates = $location['status'] === 'found';
    $has_address = $location['status'] !== 'no_address';
    $needs_geocoding = in_array($location['status'], ['pending', 'unqueued'], true);
    $location_state = !$has_address ? 'needs_address' : ($needs_geocoding ? 'pending' : $location['status']);

    if (!$has_address) {
        $page_without_addresses++;
    } elseif ($has_coordinates) {
        $cached_pin_count++;
    } elseif ($needs_geocoding) {
        $pending_geocode_count++;
    } else {
        $not_found_count++;
    }

    $organization_name = trim((string) ($row['organization_name'] ?? ''));
    $event_title = trim((string) ($row['event_title'] ?? ''));
    $map_events[] = [
        'id' => (int) $row['id'],
        'locationState' => $location_state,
        'title' => $event_title !== '' ? $event_title : ($organization_name !== '' ? $organization_name : 'Untitled engagement'),
        'organization' => $organization_name,
        'status' => (string) $row['confirmation_status'],
        'statusLabel' => $status_labels[$row['confirmation_status']] ?? 'Unknown',
        'lifecycle' => (string) $row['lifecycle_status'],
        'lifecycleLabel' => $lifecycle_labels[$row['lifecycle_status']] ?? 'Unknown',
        'dateLabel' => engagementMapDateLabel($row['event_start_date'], $row['event_end_date']),
        'address' => $row['_map_address'],
        'viewUrl' => 'view_engagement.php?' . http_build_query(['id' => (int) $row['id'], 'return_to' => $map_return]),
        'latitude' => $has_coordinates ? (float) $location['latitude'] : null,
        'longitude' => $has_coordinates ? (float) $location['longitude'] : null,
        'locationNote' => (string) ($location['locationNote'] ?? ''),
        'provider' => (string) ($location['provider'] ?? ''),
        'matchedAddress' => (string) ($location['matchedAddress'] ?? ''),
    ];
}

$all_locations_url = 'map.php?' . http_build_query(array_merge($map_context, ['location' => 'all', 'page' => 1]));
$empty_title = $map_events === [] ? ($location_filter === 'needs_address' ? 'No missing addresses' : 'No matching engagements') : 'No locations on the map yet';
$empty_description = $map_events === []
    ? ($location_filter === 'needs_address' ? 'Every engagement matching your other filters has an address entered. An entered address may still need a location lookup.' : 'Change your filters to see engagement locations.')
    : 'Check the locations below. Add missing addresses or retry unresolved lookups to place them on the map.';
$map_payload = [
    'events' => $map_events,
    'emptyTitle' => $empty_title,
    'emptyDescription' => $empty_description,
    'cachedPinCount' => $cached_pin_count,
    'pendingGeocodeCount' => $pending_geocode_count,
    'notFoundCount' => $not_found_count,
    'withoutAddressCount' => $page_without_addresses,
    'resultsTruncated' => $map_results_truncated,
    'mapProvider' => [
        'type' => 'openstreetmap',
        'rasterTileUrl' => deploymentConfig()->string('map.tile_url'),
        'attributionText' => deploymentConfig()->string('map.attribution_text'),
        'attributionUrl' => deploymentConfig()->string('map.attribution_url'),
        'maximumZoom' => deploymentConfig()->integer('map.maximum_zoom'),
    ],
    'locationLookup' => [
        'enqueueUrl' => 'map_geocode.php',
        'statusUrl' => 'map_geocode_status.php',
        'csrfToken' => $map_csrf_token,
        'pollIntervalMilliseconds' => 1500,
        'maximumPollIntervalMilliseconds' => 30000,
        'maximumPolls' => 20,
    ],
];
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Engagement Map'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css?rev=consistent-control-geometry-1',
    2 => 'assets/css/map.min.css?rev=maplibre-theme-surfaces-3',
  ),
)); ?>
<body class="map-body">
<?php include 'templates/header.php'; ?>
<main class="container map-page">
    <div class="page-heading map-heading">
        <div>
            <h1>Map</h1>
            <p class="page-intro">Explore engagement locations and review missing addresses or unresolved lookups. Pins show located engagements from this page.</p>
        </div>
    </div>

    <?php foreach ($filters['errors'] as $filter_error): ?>
        <p class="error"><?php echo htmlspecialchars($filter_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endforeach; ?>

    <form method="get" action="map.php" class="map-filters" aria-label="Map filters">
        <div class="map-filter-field map-lifecycle-filter">
            <label for="map-lifecycle">Lifecycle</label>
            <select name="lifecycle" id="map-lifecycle">
                <option value="">All lifecycle states</option>
                <?php foreach ($lifecycle_labels as $lifecycle_value => $lifecycle_label): ?>
                    <option value="<?php echo htmlspecialchars($lifecycle_value, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $filters['lifecycle'] === $lifecycle_value ? ' selected' : ''; ?>><?php echo htmlspecialchars($lifecycle_label, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="map-filter-field map-status-filter">
            <label for="map-status">Confirmation</label>
            <select name="status" id="map-status">
                <option value="">All statuses</option>
                <?php foreach ($status_labels as $status_value => $status_label): ?>
                    <option value="<?php echo htmlspecialchars($status_value, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $filters['status'] === $status_value ? ' selected' : ''; ?>><?php echo htmlspecialchars($status_label, ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <fieldset class="map-date-window">
            <legend>Date window</legend>
            <div class="map-filter-field">
                <label for="map-date-from">From</label>
                <input type="date" name="date_from" id="map-date-from" value="<?php echo htmlspecialchars($filters['date_from'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <span class="map-date-separator" aria-hidden="true">to</span>
            <div class="map-filter-field">
                <label for="map-date-to">Through</label>
                <input type="date" name="date_to" id="map-date-to" value="<?php echo htmlspecialchars($filters['date_to'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </fieldset>
        <div class="map-filter-field">
            <label for="map-location">Location</label>
            <select name="location" id="map-location"><option value="all"<?php echo $location_filter === 'all' ? ' selected' : ''; ?>>All locations</option><option value="needs_address"<?php echo $location_filter === 'needs_address' ? ' selected' : ''; ?>>Needs address</option><option value="with_address"<?php echo $location_filter === 'with_address' ? ' selected' : ''; ?>>Has an address</option></select>
        </div>
        <div class="map-filter-actions">
            <button type="submit" class="button-add">Apply Filters</button>
            <a href="map.php" class="button-secondary map-clear-button">Clear</a>
        </div>
    </form>

    <section class="map-shell" aria-labelledby="map-region-title">
        <div class="map-toolbar">
            <div>
                <h2 id="map-region-title">Engagement Locations</h2>
                <p id="map-feedback" class="map-feedback" role="status" aria-live="polite"><?php echo $cached_pin_count; ?> visible pins</p>
            </div>
            <button type="button" id="fit-map-pins" class="button-secondary"<?php echo $cached_pin_count === 0 ? ' disabled' : ''; ?>>Fit visible pins</button>
        </div>
        <div class="map-legend" aria-label="Pin colors show confirmation; pin outlines show lifecycle">
            <span><i class="map-legend-dot status-work-in-progress-pin" aria-hidden="true"></i>Work in progress</span>
            <span><i class="map-legend-dot status-under-review-pin" aria-hidden="true"></i>Under review</span>
            <span><i class="map-legend-dot status-confirmed-pin" aria-hidden="true"></i>Confirmed</span>
        </div>
        <p class="map-lifecycle-key">Pin color shows confirmation · Solid outline: active · Dashed: postponed · ×: canceled · ✓: completed</p>
        <div id="map-empty-state" class="map-empty-state"<?php echo $cached_pin_count > 0 ? ' hidden' : ''; ?>>
            <h3 id="map-empty-title"><?php echo htmlspecialchars($empty_title, ENT_QUOTES, 'UTF-8'); ?></h3>
            <p id="map-empty-description"><?php echo htmlspecialchars($empty_description, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php if ($location_filter !== 'all'): ?><a class="button-secondary" href="<?php echo htmlspecialchars($all_locations_url, ENT_QUOTES, 'UTF-8'); ?>">Show all locations</a><?php elseif ($map_events === []): ?><a class="button-secondary" href="map.php">Reset filters</a><?php endif; ?>
        </div>
        <div id="engagement-map" class="engagement-map"<?php echo $cached_pin_count === 0 ? ' hidden' : ''; ?> aria-label="Interactive engagement map. Use the controls to zoom and drag the map to pan"></div>
        <noscript><p class="map-unavailable">JavaScript is required to display and navigate the engagement map.</p></noscript>
        <p class="map-attribution-note">Map and location data © <a href="<?php echo htmlspecialchars(deploymentConfig()->string('map.attribution_url'), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener noreferrer"><?php echo htmlspecialchars(deploymentConfig()->string('map.attribution_text'), ENT_QUOTES, 'UTF-8'); ?></a>. New addresses are resolved by a background worker, cached, and added to the open map automatically.</p>
        <?php if (engagementMapGeocoderProvider() === 'geoapify' || in_array('geoapify', array_column($map_events, 'provider'), true)): ?><p class="map-attribution-note">Address lookup powered by <a href="https://www.geoapify.com/" target="_blank" rel="noopener noreferrer">Geoapify</a></p><?php endif; ?>
    </section>

    <section class="map-location-list" aria-labelledby="location-list-heading">
        <div class="map-list-heading"><div><h2 id="location-list-heading">Locations to Review</h2><p><?php echo $map_total; ?> matching engagements · Page <?php echo $map_page; ?> of <?php echo $map_pages; ?></p></div>
        <?php if ($location_filter === 'needs_address'): ?>
        <a class="button-secondary" href="<?php echo htmlspecialchars($all_locations_url, ENT_QUOTES, 'UTF-8'); ?>#location-list-heading">Show all locations</a>
        <?php else: ?>
        <a class="button-secondary" href="<?php echo htmlspecialchars('map.php?' . http_build_query(array_merge($map_context, ['location' => 'needs_address', 'page' => 1])), ENT_QUOTES, 'UTF-8'); ?>#location-list-heading">Show missing addresses</a>
        <?php endif; ?></div>
        <p class="map-list-help">Missing addresses need address details entered. Unresolved lookups already have an address; use Retry lookup to ask the mapping service again.</p>
        <nav class="map-list-filters" aria-label="Locations on this page">
            <button type="button" data-location-filter="all" aria-pressed="true">All on this page <span data-location-count="all"><?php echo count($map_events); ?></span></button>
            <?php foreach (['found' => 'On map', 'needs_address' => 'Needs address', 'pending' => 'Awaiting lookup', 'not_found' => 'Not located'] as $key => $label): ?>
            <button type="button" data-location-filter="<?php echo $key; ?>" aria-pressed="false"><?php echo $label; ?> <span data-location-count="<?php echo $key; ?>"><?php echo count(array_filter($map_events, static fn ($event) => $event['locationState'] === $key || ($key === 'not_found' && $event['locationState'] === 'failed'))); ?></span></button>
            <?php endforeach; ?>
        </nav>
        <ul class="map-location-rows">
            <?php foreach ($map_events as $event): ?>
            <li data-location-id="<?php echo $event['id']; ?>" data-location-state="<?php echo $event['locationState']; ?>">
                <div><a href="<?php echo htmlspecialchars($event['viewUrl'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($event['title'], ENT_QUOTES, 'UTF-8'); ?></a><span><?php echo htmlspecialchars($event['dateLabel'] . ' · ' . $event['lifecycleLabel'] . ' · ' . $event['statusLabel'], ENT_QUOTES, 'UTF-8'); ?></span><span><?php echo htmlspecialchars($event['address'] ?: 'No event address recorded', ENT_QUOTES, 'UTF-8'); ?></span></div>
                <div><strong data-location-label><?php echo ['found' => 'On map', 'needs_address' => 'Needs address', 'pending' => 'Awaiting lookup', 'not_found' => 'No matching location', 'failed' => 'Lookup unavailable'][$event['locationState']]; ?></strong>
                <span class="map-location-help" data-location-help><?php echo htmlspecialchars(['found' => $event['locationNote'], 'needs_address' => 'Enter an event address to add a pin.', 'pending' => 'The location lookup is queued or in progress.', 'not_found' => 'The mapping service could not confidently match this address. Retry or set the pin yourself.', 'failed' => 'The location service could not complete this lookup. Try again.'][$event['locationState']], ENT_QUOTES, 'UTF-8'); ?></span>
                <?php if ($can_edit_map): ?><button type="button" class="button-secondary" data-retry-location="<?php echo $event['id']; ?>"<?php echo in_array($event['locationState'], ['not_found', 'failed'], true) ? '' : ' hidden'; ?>>Retry lookup</button><?php endif; ?>
                <?php if ($can_edit_map && $event['address'] !== ''): ?><a class="button-secondary" href="<?php echo htmlspecialchars('map_pin.php?' . http_build_query(['id' => $event['id'], 'return_to' => $map_return]), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $event['provider'] === 'manual' ? 'Adjust confirmed pin' : 'Set map pin'; ?></a><?php endif; ?>
                <?php if ($can_edit_map): ?><a class="button-secondary" href="<?php echo htmlspecialchars('edit_engagement.php?' . http_build_query(['id' => $event['id'], 'return_to' => $map_return]), ENT_QUOTES, 'UTF-8'); ?>">Edit location</a><?php endif; ?></div>
            </li>
            <?php endforeach; ?>
        </ul>
        <p id="map-list-empty"<?php echo $map_events !== [] ? ' hidden' : ''; ?> role="status"><?php echo $map_events === [] ? htmlspecialchars($empty_description, ENT_QUOTES, 'UTF-8') : 'No engagements match this location view'; ?></p>
        <p id="map-retry-feedback" class="map-feedback" role="status" aria-live="polite"></p>
        <nav class="map-pagination" aria-label="Map pages">
        <?php if ($map_page > 1): ?><a class="button-secondary" href="<?php echo htmlspecialchars('map.php?' . http_build_query(array_merge($map_context, ['page' => $map_page - 1])), ENT_QUOTES, 'UTF-8'); ?>">Previous</a><?php endif; ?>
        <?php if ($map_page < $map_pages): ?><a class="button-secondary" href="<?php echo htmlspecialchars('map.php?' . http_build_query(array_merge($map_context, ['page' => $map_page + 1])), ENT_QUOTES, 'UTF-8'); ?>">Next</a><?php endif; ?>
        </nav>
    </section>
</main>

<script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>" type="application/json" id="engagement-map-data"><?php echo json_encode(
    $map_payload,
    JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
); ?></script>
<?php renderScript('assets/js/map-list.min.js'); ?>
<?php renderScript('assets/js/map.min.js'); ?>
<?php include 'templates/footer.php'; ?>
</body>
</html>
