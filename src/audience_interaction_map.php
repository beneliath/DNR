<?php
declare(strict_types=1);

/** Offline Natural Earth lookup; city positions and country label points are approximate. */
function audienceInteractionMapCoordinate(string $country, string $region, string $city): ?array
{
    static $places = null;
    if ($places === null) {
        $json = file_get_contents(__DIR__.'/assets/data/interaction-place-coordinates.json');
        $places = $json === false ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }
    $countryPoint = $places['countries'][$country] ?? null;
    if (!is_array($countryPoint)) return null;
    if ($city !== '') {
        $key = mb_convert_case(trim($city), MB_CASE_FOLD, 'UTF-8');
        $candidates = $places['cities'][$country][$key] ?? [];
        if ($candidates) {
            $regionKey = mb_convert_case(trim($region), MB_CASE_FOLD, 'UTF-8');
            $matches = $regionKey === '' ? [] : array_values(array_filter($candidates,
                static fn(array $row): bool => mb_convert_case($row[0], MB_CASE_FOLD, 'UTF-8') === $regionKey));
            // A unique city in a country is useful even when the region naming differs.
            $chosen = $matches[0] ?? (count($candidates) === 1 ? $candidates[0] : null);
            if ($chosen !== null) return ['longitude'=>$chosen[1], 'latitude'=>$chosen[2], 'precision'=>'city'];
        }
    }
    return ['longitude'=>$countryPoint[0], 'latitude'=>$countryPoint[1], 'precision'=>'country'];
}

/** Collapse coarse observations into map pins without exposing IPs or contacts. */
function audienceInteractionMapSummarize(array $rows): array
{
    $pins = [];
    $unmapped = 0;
    $total = 0;
    $types = [
        'message:open'=>'Email opens', 'message:click'=>'Email clicks',
        'contact:signup-permission'=>'Signups', 'contact:subscription-confirmed'=>'Confirmed subscriptions',
        'contact:preferences'=>'Preference updates', 'contact:status'=>'Unsubscribes',
        'contact:survey-response'=>'Survey responses',
    ];
    foreach ($rows as $row) {
        $count = max(0, (int)($row['observations'] ?? 0));
        if ($count === 0) continue;
        $total += $count;
        $country = strtoupper(trim((string)($row['country_code'] ?? '')));
        $region = trim((string)($row['region'] ?? ''));
        $city = trim((string)($row['city'] ?? ''));
        if (!preg_match('/^[A-Z]{2}$/D', $country) || !($point = audienceInteractionMapCoordinate($country, $region, $city))) {
            $unmapped += $count;
            continue;
        }
        $key = $point['precision'] === 'city'
            ? $country.'|'.$point['longitude'].'|'.$point['latitude']
            : $country;
        $countryName = addressCountryChoices()[$country]['name'] ?? $country;
        $place = audienceInteractionPlace(['country_code'=>$country, 'region'=>$region, 'city'=>$city]);
        if (!isset($pins[$key])) $pins[$key] = [
            'label'=>$point['precision'] === 'city' ? $place : $countryName,
            'country'=>$countryName,
            'longitude'=>$point['longitude'], 'latitude'=>$point['latitude'],
            'precision'=>$point['precision'], 'observations'=>0, 'types'=>[], 'reported_places'=>[],
        ];
        $pins[$key]['observations'] += $count;
        $type = $types[($row['source'] ?? '').':'.($row['event_type'] ?? '')] ?? null;
        if ($type !== null) $pins[$key]['types'][$type] = ($pins[$key]['types'][$type] ?? 0) + $count;
        if ($point['precision'] === 'country' && $place !== $countryName && $place !== '') {
            $pins[$key]['reported_places'][$place] = true;
        }
    }
    foreach ($pins as &$pin) {
        $pin['reported_places'] = array_keys($pin['reported_places']);
        arsort($pin['types']);
    }
    unset($pin);
    $pins = array_values($pins);
    usort($pins, static fn(array $a, array $b): int => $b['observations'] <=> $a['observations'] ?: strcmp($a['label'], $b['label']));
    return ['pins'=>$pins, 'observations'=>$total, 'unmapped'=>$unmapped];
}
