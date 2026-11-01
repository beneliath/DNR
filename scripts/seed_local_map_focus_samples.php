<?php
/** Fictional MOED engagement locations for testing map focus on localhost. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli'
    || getenv('DNR_LOCAL_SAMPLE_APPLY') !== '1'
    || getenv('DNR_ACCOUNT_KEY') !== 'shalom-in-messiah'
    || getenv('DNR_ACCOUNT_MODE') !== 'primary'
    || getenv('DNR_REQUIRE_HTTPS') !== '0') {
    throw new RuntimeException('This fixture is limited to the opted-in local primary preview.');
}

require '/var/www/html/bootstrap.php';
require_once '/var/www/html/map_helpers.php';
$db = applicationDatabaseConnection();
$profile = $db->query('SELECT account_key FROM account_profile WHERE id=1')->fetch_assoc();
if (($profile['account_key'] ?? '') !== 'shalom-in-messiah') {
    throw new RuntimeException('Unexpected account database.');
}
$actor = $db->query("SELECT id, username FROM users WHERE role='admin' AND account_status='active' ORDER BY id LIMIT 1")->fetch_assoc();
if (!$actor) throw new RuntimeException('An active local admin is required for sample pins.');
setDatabaseAuditContext($db, (int) $actor['id'], (string) $actor['username']);

$today = new DateTimeImmutable('today', new DateTimeZone('America/Chicago'));
$fixtures = [
    ['chicago', 'Chicago', 'Illinois', '60601', 'United States of America', 41.8781, -87.6298, 10],
    ['london', 'London', 'England', 'EC1A 1BB', 'United Kingdom', 51.5072, -0.1276, 14],
    ['sydney', 'Sydney', 'New South Wales', '2000', 'Australia', -33.8688, 151.2093, 18],
];

$db->begin_transaction();
try {
    $organizationName = 'MOED Map Focus Demo (Fictional)';
    $organization = $db->execute_query('SELECT id FROM organizations WHERE organization_name=? ORDER BY id LIMIT 1', [$organizationName])->fetch_assoc();
    if ($organization) {
        $organizationId = (int) $organization['id'];
    } else {
        $db->execute_query('INSERT INTO organizations(organization_name,notes) VALUES (?,?)',
            [$organizationName, 'Fictional localhost-only map focus fixtures; addresses and venue names are illustrative.']);
        $organizationId = (int) $db->insert_id;
    }

    $seeded = [];
    foreach ($fixtures as [$key, $city, $region, $postcode, $country, $latitude, $longitude, $days]) {
        $title = '[Map Focus Sample] ' . ucfirst($key) . ' workshop';
        $existing = $db->execute_query('SELECT id FROM engagements WHERE organization_id=? AND event_title=? LIMIT 1',
            [$organizationId, $title])->fetch_assoc();
        if ($existing) {
            $engagementId = (int) $existing['id'];
        } else {
            $date = $today->modify('+' . $days . ' days')->format('Y-m-d');
            $db->execute_query('INSERT INTO engagements(organization_id,event_title,event_description,event_start_date,event_end_date,event_type,confirmation_status,lifecycle_status,event_address_line_1,event_city,event_state,event_zipcode,event_country) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$organizationId, $title, 'Fictional local sample for testing On Map navigation. The pin marks an approximate city center, not a real venue.', $date, $date, 'conference', 'under_review', 'active', '100 Example Way', $city, $region, $postcode, $country]);
            $engagementId = (int) $db->insert_id;
        }

        $engagement = $db->execute_query('SELECT * FROM engagements WHERE id=?', [$engagementId])->fetch_assoc();
        $address = engagementMapAddress($engagement);
        if ($address === '') throw new RuntimeException('Sample address was not accepted.');
        $pin = $db->execute_query('SELECT engagement_id FROM engagement_map_pins WHERE engagement_id=?', [$engagementId])->fetch_assoc();
        if (!$pin) {
            $db->execute_query('INSERT INTO engagement_map_pins(engagement_id,address_hash,latitude,longitude,confirmed_by) VALUES (?,?,?,?,?)',
                [$engagementId, engagementMapAddressHash($address), $latitude, $longitude, (int) $actor['id']]);
        }
        $seeded[] = ['id' => $engagementId, 'title' => $title, 'date' => $engagement['event_start_date']];
    }
    $db->commit();
    echo json_encode(['sample_engagements' => $seeded], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), PHP_EOL;
} catch (Throwable $error) {
    $db->rollback();
    throw $error;
}
