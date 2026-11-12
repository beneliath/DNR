<?php
declare(strict_types=1);

/** Natural Earth label points for the country totals on the analytics map. */
function audienceInteractionMapCoordinate(string $country): ?array
{
    static $countries = null;
    if ($countries === null) {
        $json = file_get_contents(__DIR__.'/assets/data/interaction-place-coordinates.json');
        $countries = $json === false ? [] : json_decode($json, true, 512, JSON_THROW_ON_ERROR)['countries'];
    }
    return $countries[$country] ?? null;
}

function audienceInteractionMapTypes(): array
{
    return [
        'message:open'=>'Email Opens', 'message:click'=>'Email Clicks',
        'contact:signup-permission'=>'Signups', 'contact:subscription-confirmed'=>'Confirmed Subscriptions',
        'contact:preferences'=>'Preference Updates', 'contact:status'=>'Unsubscribes',
        'contact:survey-response'=>'Survey Responses',
    ];
}

/** One pin per country across both subscriber and email interactions. */
function audienceInteractionMapSummarize(array $rows): array
{
    $pins = [];
    $unmapped = 0;
    $unmappedTypes = [];
    $total = 0;
    $types = audienceInteractionMapTypes();
    $countryNames = addressCountryChoices();
    foreach ($rows as $row) {
        $count = max(0, (int)($row['observations'] ?? 0));
        if ($count === 0) continue;
        $total += $count;
        $type = $types[($row['source'] ?? '').':'.($row['event_type'] ?? '')] ?? null;
        $country = strtoupper(trim((string)($row['country_code'] ?? '')));
        $point = audienceInteractionMapCoordinate($country);
        if ($point === null) {
            $unmapped += $count;
            if ($type !== null) $unmappedTypes[$type] = ($unmappedTypes[$type] ?? 0) + $count;
            continue;
        }
        $pins[$country] ??= [
            'label'=>$countryNames[$country]['name'] ?? $country,
            'longitude'=>$point[0], 'latitude'=>$point[1],
            'observations'=>0, 'types'=>[],
        ];
        $pins[$country]['observations'] += $count;
        if ($type !== null) $pins[$country]['types'][$type] = ($pins[$country]['types'][$type] ?? 0) + $count;
    }
    foreach ($pins as &$pin) {
        arsort($pin['types']);
    }
    unset($pin);
    $pins = array_values($pins);
    usort($pins, static fn(array $a, array $b): int => $b['observations'] <=> $a['observations'] ?: strcmp($a['label'], $b['label']));
    return ['pins'=>$pins, 'observations'=>$total, 'unmapped'=>$unmapped, 'unmapped_types'=>$unmappedTypes];
}
