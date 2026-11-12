<?php
declare(strict_types=1);

require_once __DIR__.'/audience_store.php';
require_once __DIR__.'/email_helpers.php';
require_once __DIR__.'/email_ascii_footer.php';

/** Names, custom fields and GeoIP labels can themselves contain an email address. */
function audienceSummaryLabel(mixed $value): string
{
    if (!is_string($value) || str_contains($value, '@')) return '';
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function audienceSummaryAddress(mixed $value): array
{
    $parts = audienceAddressParts($value);
    $city = audienceSummaryLabel($parts['city']);
    $state = audienceSummaryLabel($parts['region']);
    if ($city !== '' || $state !== '' || !is_string($value) || str_contains($value, "\n")) {
        return [$city, $state];
    }

    // Older Mailchimp imports have a single mailing line. Only use a recognizable
    // state before a ZIP and a city separable from the street.
    static $statePattern;
    if ($statePattern === null) {
        $states = addressRegionChoices('US');
        $names = array_merge(array_keys($states), array_column($states, 'name'));
        $statePattern = '/\b('.implode('|', array_map(static fn(string $name): string => preg_quote($name, '/'), $names)).')\s+\d{5}(?:-\d{4})?\b/i';
    }
    if (!preg_match($statePattern, $value, $match, PREG_OFFSET_CAPTURE)) {
        return ['', ''];
    }
    $state = normalizeAddressRegion('US', $match[1][0]);
    if (!$state) return ['', ''];
    $beforeState = rtrim(substr($value, 0, $match[1][1]), " ,\t");
    $segments = explode(',', $beforeState);
    $lastPart = trim(end($segments));
    $streetType = '(?:street|st|avenue|ave|road|rd|drive|dr|lane|ln|court|ct|boulevard|blvd|circle|cir|way|parkway|pkwy)';
    if (preg_match('/(?:\b'.$streetType.'\.?|\bP\.?O\.?\s+Box\s+\d+)\s+(\p{L}[\p{L} .\'’\-]*)$/iuD', $lastPart, $match)
        && preg_match('/\d/', $lastPart)) {
        $city = $match[1];
    } elseif (preg_match('/^\p{L}[\p{L} .\'’\-]*$/uD', $lastPart) && !preg_match('/\b'.$streetType.'\.?$/iuD', $lastPart)) {
        $city = $lastPart;
    }
    return [audienceSummaryLabel($city), $state];
}

function audienceSummaryProfile(array $row, array $fields, bool $includeAddress): array
{
    $custom = audienceJson($row['custom_json']);
    $name = trim(audienceSummaryLabel($row['first_name']).' '.audienceSummaryLabel($row['last_name']));
    $city = $state = $address = '';
    foreach ($fields as $field) {
        $value = $custom[$field['id']] ?? '';
        if ($value === '') continue;
        $tag = $field['merge_tag'];
        if ($name === '' && ($tag === 'FULLNAME' || strcasecmp($field['label'], 'Full Name') === 0)) {
            $name = audienceSummaryLabel($value);
        }
        if (!$includeAddress) continue;
        if ($field['field_type'] === 'address' && $address === '') {
            [$fieldCity, $fieldState] = audienceSummaryAddress($value);
            $address = implode(', ', array_filter([$fieldCity, $fieldState]));
        } elseif ($city === '' && ($tag === 'CITY' || strcasecmp($field['label'], 'City') === 0)) {
            $city = audienceSummaryLabel($value);
        } elseif ($state === '' && (in_array($tag, ['STATE','REGION','PROVINCE'], true)
            || in_array(strtolower($field['label']), ['state','province','state / province','region'], true))) {
            $state = audienceSummaryLabel($value);
        }
    }
    return [
        $name !== '' ? $name : 'Connection #'.$row['contact_id'],
        $address !== '' ? $address : implode(', ', array_filter([$city, $state])),
    ];
}

/** One row per observed interaction, even if the same person changes status again. */
function audienceDailySummaryRows(AudienceStore $store, string $day, int $startEvent): array
{
    $groups = ['signup'=>[], 'pending'=>[], 'unsubscribe'=>[]];
    // Older form receipts omit the prior status. The next transition's "from"
    // describes it at sign-up time. With no later transition the current status still applies.
    $events = $store->db->execute_query(
        "SELECT e.contact_id, e.event_type, e.details_json, e.created_at,
                c.first_name, c.last_name, c.custom_json,
                CASE WHEN e.event_type='signup-permission' AND e.details_json->>'$.opt_in'='double'
                        AND NOT JSON_CONTAINS_PATH(e.details_json,'one','$.prior_status')
                    THEN COALESCE((
                        SELECT n.details_json->>'$.from' FROM marketing_contact_events n
                        WHERE n.contact_id=e.contact_id AND n.id>e.id
                            AND n.event_type IN ('subscription-confirmed','subscription_changed','status')
                            AND n.details_json->>'$.from' IN ('pending','subscribed','unsubscribed','non-subscribed','cleaned')
                        ORDER BY n.id LIMIT 1
                    ), c.email_status) END AS legacy_signup_status
         FROM marketing_contact_events e JOIN marketing_contacts c ON c.id=e.contact_id
         WHERE c.audience_id=? AND c.deleted_at IS NULL AND e.id>?
             AND e.created_at>=? AND e.created_at<DATE_ADD(?, INTERVAL 1 DAY)
             AND e.event_type IN ('signup-permission','subscription-confirmed','subscription_changed','status','contact_added')
         ORDER BY e.created_at DESC, e.id DESC",
        [$store->audienceId, $startEvent, $day, $day]
    );
    $fields = $store->fields();
    $utc = new DateTimeZone('UTC');
    $estimateLookup = audienceInteractionEstimateLookup();
    foreach ($events as $event) {
        $details = audienceJson($event['details_json']);
        if ($event['event_type'] === 'signup-permission') {
            if (($details['origin'] ?? '') === 'Import' || ($details['prior_status'] ?? $event['legacy_signup_status']) === 'subscribed') continue;
            $kind = match ($details['opt_in'] ?? '') { 'single'=>'signup', 'double'=>'pending', default=>null };
        } else {
            $kind = audienceSubscriptionChange($event['event_type'], $details, true);
        }
        if ($kind === null) continue;
        [$name, $address] = audienceSummaryProfile($event, $fields, $kind !== 'unsubscribe');
        $location = is_array($details['interaction_location'] ?? null) ? $details['interaction_location'] : [];
        $place = audienceSummaryLabel(audienceInteractionPlace($location));
        $estimate = audienceInteractionCurrentEstimate($location, $estimateLookup);
        $estimatePlace = audienceSummaryLabel($estimate['place'] ?? '');
        $groups[$kind][] = [
            'contact_id'=>(int) $event['contact_id'], 'name'=>$name, 'address'=>$address,
            'location'=>$place !== '' ? $place : 'Not Recorded',
            'current_estimate'=>$estimatePlace,
            'dbip_location'=>($place !== '' && ($location['source'] ?? '') === 'DB-IP City Lite')
                || ($estimatePlace !== '' && $estimate['source'] === 'DB-IP City Lite'),
            'time'=>(new DateTimeImmutable($event['created_at'], $utc))->format('M j, Y · g:i:s A T'),
        ];
    }
    return $groups;
}

/** Render both MIME alternatives from the same rows and account-scoped links. */
function audienceDailySummaryEmail(string $audienceName, string $day, array $groups): array
{
    $sections = [
        'signup'=>['title'=>'Subscriptions', 'action'=>'Subscribed', 'place'=>'Subscription Location', 'empty'=>'No subscriptions.', 'color'=>'#137a55', 'tint'=>'#e7f6ef'],
        'pending'=>['title'=>'Pending Sign-Ups', 'action'=>'Signed Up', 'place'=>'Sign-Up Location', 'empty'=>'No pending sign-ups.', 'color'=>'#843600', 'tint'=>'#fff3d8'],
        'unsubscribe'=>['title'=>'Unsubscribes', 'action'=>'Unsubscribed', 'place'=>'Unsubscribe Location', 'empty'=>'No unsubscribes.', 'color'=>'#b42318', 'tint'=>'#fff0ee'],
    ];
    $audienceName = audienceSummaryLabel($audienceName) ?: 'Audience';
    $analyticsUrl = applicationPublicUrl('audience.php', ['view'=>'analytics', 'section'=>'audience']);
    $brandLogoUrl = emailBrandLogoUrl();
    // Local previews can use the hosted MOED artwork while record links stay on localhost.
    // The transparent PNG is the email version of the Comprehensive User Guide's logo.
    $monkeyLogoUrl = preg_replace('~/assets/dnr-logo-email\.(?:png|svg)(?:[?#].*)?$~D', '/assets/audience-logo-email.png', $brandLogoUrl, 1, $matched);
    if (!$matched) $monkeyLogoUrl = applicationPublicUrl('assets/audience-logo-email.png');
    $counts = [];
    foreach (['signup'=>'subscription', 'pending'=>'pending sign-up', 'unsubscribe'=>'unsubscribe'] as $kind => $noun) {
        $n = count($groups[$kind]);
        $counts[] = $n.' '.$noun.($n === 1 ? '' : 's');
    }
    $summary = implode(' · ', $counts);
    $lines = ['MessageMonkey Daily Audience Summary', $audienceName, 'Audience changes for '.$day.' (UTC)', $summary];
    $usesDbip = false;
    foreach ($sections as $kind => $section) {
        $lines[] = '';
        $lines[] = $section['title'];
        if (!$groups[$kind]) $lines[] = $section['empty'];
        foreach ($groups[$kind] as $index => $row) {
            if (!empty($row['dbip_location'])) $usesDbip = true;
            $url = applicationPublicUrl('audience.php', ['view'=>'contact', 'id'=>$row['contact_id']]);
            $groups[$kind][$index]['url'] = $url;
            $lines[] = $row['name'].($row['address'] !== '' ? ' · '.$row['address'] : '');
            $lines[] = $section['place'].' · '.$row['location'];
            if (!empty($row['current_estimate'])) $lines[] = 'Current IP estimate · '.$row['current_estimate'];
            $lines[] = $section['action'].' · '.$row['time'];
            $lines[] = $url;
        }
    }
    $lines[] = "\nOpen Audience Analytics\n".$analyticsUrl;
    if ($usesDbip) $lines[] = 'IP geolocation by DB-IP · https://db-ip.com/';
    $lines[] = "\n".emailAsciiFooterText();
    $h = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    ob_start();
    require __DIR__.'/templates/audience/daily-summary-email.php';
    return ['text'=>implode("\n", $lines), 'html'=>trim((string) ob_get_clean())];
}
