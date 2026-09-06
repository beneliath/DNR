<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/map_helpers.php';
require_once __DIR__ . '/record_workspace_helpers.php';
startSecureSession();
requireLogin();
if (!in_array((string) ($_SESSION['role'] ?? ''), ['admin', 'editor'], true)) {
    http_response_code(403);
    exit('Editing access is required to set an engagement pin.');
}
$id = \Dnr\Http\RequestInput::positiveInt($_GET, 'id');
$return_to = safeRecordReturnUrl($_POST['return_to'] ?? $_GET['return_to'] ?? null, 'map.php');
if ($id === null) { http_response_code(404); exit('Engagement not found.'); }
$conn = applicationDatabaseConnection();
$is_post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$error = '';
if ($is_post) { requireValidCsrfToken(); $conn->begin_transaction(); }
$stmt = $conn->prepare('SELECT * FROM engagements WHERE id = ? AND is_deleted = 0' . ($is_post ? ' FOR UPDATE' : ''));
$stmt->bind_param('i', $id);
$stmt->execute();
$engagement = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$engagement) {
    if ($is_post) $conn->rollback();
    http_response_code(404);
    exit('Engagement not found.');
}
$address = engagementMapAddress($engagement);
$address_hash = engagementMapAddressHash($address);
if ($is_post) {
    try {
        if (!is_string($_POST['address_hash'] ?? null) || !hash_equals($address_hash, $_POST['address_hash'])) {
            http_response_code(409);
            throw new InvalidArgumentException('The event address changed while this page was open. Check the updated address and place the pin again.');
        }
        if (($_POST['action'] ?? '') === 'clear') {
            $save = $conn->prepare('DELETE FROM engagement_map_pins WHERE engagement_id = ?');
            $save->bind_param('i', $id);
        } else {
            $latitude = filter_var($_POST['latitude'] ?? '', FILTER_VALIDATE_FLOAT);
            $longitude = filter_var($_POST['longitude'] ?? '', FILTER_VALIDATE_FLOAT);
            if ($address === '' || $latitude === false || $longitude === false
                || !engagementMapCoordinatesAreValid($latitude, $longitude)
                || ($_POST['confirm_pin'] ?? '') !== 'yes'
            ) {
                http_response_code(400);
                throw new InvalidArgumentException('Enter an event address, choose valid coordinates, and confirm that you checked the pin location.');
            }
            $user_id = (int) $_SESSION['user_id'];
            $save = $conn->prepare('INSERT INTO engagement_map_pins (engagement_id, address_hash, latitude, longitude, confirmed_by, confirmed_at)
                VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE address_hash=VALUES(address_hash),
                latitude=VALUES(latitude), longitude=VALUES(longitude), confirmed_by=VALUES(confirmed_by), confirmed_at=VALUES(confirmed_at)');
            $save->bind_param('isddi', $id, $address_hash, $latitude, $longitude, $user_id);
        }
        $save->execute();
        $save->close();
        if (!recordAuditEvent($conn, ['event_category' => 'engagement', 'event_type' => ($_POST['action'] ?? '') === 'clear' ? 'map_pin_cleared' : 'map_pin_confirmed',
            'actor_user_id' => (int) $_SESSION['user_id'], 'entity_type' => 'engagement', 'entity_id' => $id])) {
            throw new RuntimeException('Unable to audit the engagement pin change.');
        }
        $conn->commit();
        header('Location: ' . $return_to, true, 303);
        exit;
    } catch (InvalidArgumentException $exception) {
        $conn->rollback();
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        $conn->rollback();
        applicationLog('error', 'Unable to save engagement pin', ['engagement_id' => $id, 'error' => $exception->getMessage()]);
        http_response_code(503);
        $error = 'The pin could not be saved. Please try again.';
    }
}
$location = engagementMapLocationStatuses($conn, [$id])[0];
// A stale submission must not retain coordinates for a different address.
$preserve_submission = $is_post && http_response_code() !== 409;
$latitude_value = $preserve_submission && is_scalar($_POST['latitude'] ?? null) ? (string) $_POST['latitude'] : (string) ($location['latitude'] ?? '');
$longitude_value = $preserve_submission && is_scalar($_POST['longitude'] ?? null) ? (string) $_POST['longitude'] : (string) ($location['longitude'] ?? '');
$pin_payload = ['latitude' => is_numeric($latitude_value) ? (float) $latitude_value : null,
    'longitude' => is_numeric($longitude_value) ? (float) $longitude_value : null,
    'tileUrl' => deploymentConfig()->string('map.tile_url'), 'maximumZoom' => deploymentConfig()->integer('map.maximum_zoom'),
    'attributionText' => deploymentConfig()->string('map.attribution_text'), 'attributionUrl' => deploymentConfig()->string('map.attribution_url')];
$csrf = generateCsrfToken();
releaseApplicationSessionLock();
$escape = static fn ($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Set Map Pin'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/map.min.css']]); ?>
<body class="map-body">
<?php include 'templates/header.php'; ?>
<main class="container map-page">
    <div class="page-heading"><div><h1>Set Map Pin</h1><p class="page-intro"><?php echo $escape($engagement['event_title']); ?></p></div></div>
    <p><?php echo $escape($address ?: 'No event address entered'); ?></p>
    <?php if (($location['matchedAddress'] ?? '') !== ''): ?><p class="map-list-help">Automatic match: <?php echo $escape($location['matchedAddress']); ?></p><?php endif; ?>
    <p>Zoom in and click the venue location, drag the pin, or enter coordinates. A confirmed pin takes priority over automatic lookups for this engagement.</p>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo $escape($error); ?></p><?php endif; ?>
    <?php if ($address === ''): ?><p class="error">Enter the event address before saving a pin. <a href="edit_engagement.php?id=<?php echo $id; ?>">Edit engagement</a></p><?php endif; ?>
    <div id="pin-editor-map" class="engagement-map" aria-label="Choose the venue location on the map"></div>
    <p class="map-attribution-note">Map data © <a href="<?php echo $escape($pin_payload['attributionUrl']); ?>" target="_blank" rel="noopener noreferrer"><?php echo $escape($pin_payload['attributionText']); ?></a></p>
    <?php if (engagementMapGeocoderProvider() === 'geoapify' || ($location['provider'] ?? '') === 'geoapify'): ?><p class="map-attribution-note">Address lookup powered by <a href="https://www.geoapify.com/" target="_blank" rel="noopener noreferrer">Geoapify</a></p><?php endif; ?>
    <p id="pin-editor-feedback" class="map-feedback" role="status" aria-live="polite">Choose a location on the map or enter latitude and longitude</p>
    <form method="post" class="map-pin-form">
        <input type="hidden" name="csrf_token" value="<?php echo $escape($csrf); ?>">
        <input type="hidden" name="return_to" value="<?php echo $escape($return_to); ?>">
        <input type="hidden" name="address_hash" value="<?php echo $escape($address_hash); ?>">
        <div class="map-pin-coordinates">
            <div><label for="pin-latitude">Latitude</label><input id="pin-latitude" name="latitude" type="number" step="any" min="-90" max="90" required value="<?php echo $escape($latitude_value); ?>"></div>
            <div><label for="pin-longitude">Longitude</label><input id="pin-longitude" name="longitude" type="number" step="any" min="-180" max="180" required value="<?php echo $escape($longitude_value); ?>"></div>
        </div>
        <label class="map-pin-confirm"><input id="confirm-pin" type="checkbox" name="confirm_pin" value="yes" required> I have checked that this pin marks the correct venue</label>
        <p class="map-list-help">Changing the event address will require a new pin confirmation. Automatic lookups cannot move a confirmed pin.</p>
        <div class="map-pin-actions"><button type="submit" class="button-add">Save confirmed pin</button><a class="button-secondary" href="<?php echo $escape($return_to); ?>">Cancel</a>
        <?php if (($location['provider'] ?? '') === 'manual'): ?><button class="button-secondary" type="submit" name="action" value="clear" formnovalidate>Use automatic lookup</button><?php endif; ?></div>
    </form>
</main>
<script nonce="<?php echo $escape(contentSecurityPolicyNonce()); ?>" type="application/json" id="pin-editor-data"><?php echo json_encode($pin_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<?php renderScript('assets/js/map-pin.min.js'); ?>
<?php include 'templates/footer.php'; ?>
</body></html>
