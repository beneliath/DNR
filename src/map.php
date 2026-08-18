<?php
include 'config.php';
include 'functions.php';
include 'map_helpers.php';
startSecureSession();
requireLogin();

$filters = normalizeEngagementMapFilters($_GET);
$status_labels = engagementMapStatuses();
$clauses = ['e.is_deleted = 0'];
$parameters = [];
$parameter_types = '';

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

$engagement_sql = "SELECT
        e.id,
        e.event_title,
        e.event_start_date,
        e.event_end_date,
        e.confirmation_status,
        e.event_address_line_1,
        e.event_address_line_2,
        e.event_city,
        e.event_state,
        e.event_zipcode,
        e.event_country,
        o.organization_name
    FROM engagements e
    LEFT JOIN organizations o ON o.id = e.organization_id
    WHERE " . implode(' AND ', $clauses) . '
    ORDER BY e.event_start_date ASC, e.id ASC';

$engagement_stmt = $conn->prepare($engagement_sql);
if (!$engagement_stmt) {
    error_log('Unable to prepare the engagement map: ' . $conn->error);
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
    error_log('Unable to load map engagements: ' . $engagement_stmt->error);
    http_response_code(500);
    exit('Unable to load engagement locations.');
}
$engagement_result = $engagement_stmt->get_result();
$engagement_rows = [];
$address_hashes = [];
$events_without_addresses = 0;
while ($row = $engagement_result->fetch_assoc()) {
    $address = engagementMapAddress($row);
    if ($address === '') {
        $events_without_addresses++;
        continue;
    }
    $address_hash = engagementMapAddressHash($address);
    $row['_map_address'] = $address;
    $row['_map_address_hash'] = $address_hash;
    $engagement_rows[] = $row;
    $address_hashes[$address_hash] = true;
}
$engagement_stmt->close();

$geocodes = [];
if ($address_hashes !== []) {
    $hash_values = array_keys($address_hashes);
    $placeholders = implode(', ', array_fill(0, count($hash_values), '?'));
    $geocode_stmt = $conn->prepare(
        "SELECT address_hash, latitude, longitude, lookup_status
         FROM engagement_map_geocodes
         WHERE address_hash IN ({$placeholders})"
    );
    if (!$geocode_stmt) {
        error_log('The engagement map migration is required: ' . $conn->error);
        http_response_code(503);
        exit('The engagement map database migration is required before this page can be used.');
    }
    $hash_types = str_repeat('s', count($hash_values));
    $hash_bind_arguments = [$hash_types];
    foreach ($hash_values as &$hash_value) {
        $hash_bind_arguments[] = &$hash_value;
    }
    unset($hash_value);
    $geocode_stmt->bind_param(...$hash_bind_arguments);
    if (!$geocode_stmt->execute()) {
        error_log('Unable to load cached map locations: ' . $geocode_stmt->error);
        http_response_code(500);
        exit('Unable to load cached engagement locations.');
    }
    $geocode_result = $geocode_stmt->get_result();
    while ($geocode = $geocode_result->fetch_assoc()) {
        $geocodes[$geocode['address_hash']] = $geocode;
    }
    $geocode_stmt->close();
}

$map_events = [];
$cached_pin_count = 0;
$pending_geocode_count = 0;
$not_found_count = 0;
foreach ($engagement_rows as $row) {
    $hash = $row['_map_address_hash'];
    $geocode = $geocodes[$hash] ?? null;
    $has_coordinates = is_array($geocode)
        && $geocode['lookup_status'] === 'found'
        && engagementMapCoordinatesAreValid($geocode['latitude'], $geocode['longitude']);
    $needs_geocoding = $geocode === null
        || ($geocode['lookup_status'] === 'found' && !$has_coordinates);

    if ($has_coordinates) {
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
        'title' => $event_title !== '' ? $event_title : ($organization_name !== '' ? $organization_name : 'Untitled engagement'),
        'organization' => $organization_name,
        'status' => (string) $row['confirmation_status'],
        'statusLabel' => $status_labels[$row['confirmation_status']] ?? 'Unknown',
        'dateLabel' => engagementMapDateLabel($row['event_start_date'], $row['event_end_date']),
        'address' => $row['_map_address'],
        'viewUrl' => 'view_engagement.php?id=' . (int) $row['id'],
        'latitude' => $has_coordinates ? (float) $geocode['latitude'] : null,
        'longitude' => $has_coordinates ? (float) $geocode['longitude'] : null,
        'needsGeocoding' => $needs_geocoding,
    ];
}

$map_payload = [
    'events' => $map_events,
    'csrfToken' => generateCsrfToken(),
    'geocodeUrl' => 'map_geocode.php',
    'cachedPinCount' => $cached_pin_count,
    'pendingGeocodeCount' => $pending_geocode_count,
    'notFoundCount' => $not_found_count,
    'withoutAddressCount' => $events_without_addresses,
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Engagement Map - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
</head>
<body>
<?php include 'templates/header.php'; ?>
<main class="container map-page">
    <div class="page-heading">
        <div>
            <h1>Map</h1>
            <p class="page-intro">Explore active engagement locations by status and event date.</p>
        </div>
    </div>

    <?php foreach ($filters['errors'] as $filter_error): ?>
        <p class="error"><?php echo htmlspecialchars($filter_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endforeach; ?>

    <form method="get" action="map.php" class="map-filters" aria-label="Map filters">
        <div class="map-filter-field map-status-filter">
            <label for="map-status">Status</label>
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
        <div class="map-filter-actions">
            <button type="submit" class="button-add">Apply filters</button>
            <a href="map.php" class="button-secondary map-clear-button" style="text-decoration: none;">Clear</a>
        </div>
    </form>

    <section class="map-shell" aria-labelledby="map-region-title">
        <div class="map-toolbar">
            <div>
                <h2 id="map-region-title">Engagement Locations</h2>
                <p id="map-feedback" class="map-feedback" role="status" aria-live="polite">Preparing map locations…</p>
            </div>
            <button type="button" id="fit-map-pins" class="button-secondary">Fit visible pins</button>
        </div>
        <div class="map-legend" aria-label="Engagement status colors">
            <span><i class="map-legend-dot status-work-in-progress-pin" aria-hidden="true"></i>Work in progress</span>
            <span><i class="map-legend-dot status-under-review-pin" aria-hidden="true"></i>Under review</span>
            <span><i class="map-legend-dot status-confirmed-pin" aria-hidden="true"></i>Confirmed</span>
        </div>
        <div id="engagement-map" class="engagement-map" aria-label="Interactive engagement map. Use the controls to zoom and drag the map to pan."></div>
        <noscript><p class="map-unavailable">JavaScript is required to display and navigate the engagement map.</p></noscript>
        <p class="map-attribution-note">Map and location data © <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener noreferrer">OpenStreetMap contributors</a>. New address lookups are rate-limited and cached.</p>
    </section>
</main>

<script type="application/json" id="engagement-map-data"><?php echo json_encode(
    $map_payload,
    JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
); ?></script>
<script src="assets/js/map.min.js?v=1.0.2" defer></script>
<?php include 'templates/footer.php'; ?>
</body>
</html>
