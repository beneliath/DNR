<?php
declare(strict_types=1);

/** Store the verified network address and an approximate, local IP lookup. */
function audienceInteractionLocation(array $server, ?callable $lookup = null, ?string $database = null): array
{
    $ip = requestIpAddress($server);
    if ($ip === null) return [];

    $location = ['ip_address' => $ip];
    $edgeCountry = function_exists('audienceCollectionCountry') ? audienceCollectionCountry($server) : null;
    $database ??= (string) (getenv('DNR_GEOIP_DATABASE') ?: '');
    $publicAddress = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    if ($publicAddress && $database !== '' && ($lookup !== null || is_readable($database))) {
        try {
            if ($lookup === null) {
                $reader = new \MaxMind\Db\Reader($database);
                try { $record = $reader->get($ip); } finally { $reader->close(); }
            } else $record = $lookup($database, $ip);
            $resolved = audienceInteractionGeoRecord($record);
            if ($resolved) $location += $resolved + ['source' => 'local GeoIP database'];
        } catch (Throwable) {
            applicationLog('warning', 'Audience interaction GeoIP lookup unavailable');
        }
    }
    if (!isset($location['country_code']) && $edgeCountry !== null) {
        $location['country_code'] = $edgeCountry;
        $location['source'] = 'trusted edge country';
    }
    return $location;
}

/** Country databases and City databases share this shape; only City has finer detail. */
function audienceInteractionGeoRecord(mixed $record): array
{
    if (!is_array($record)) return [];
    $country = strtoupper((string) ($record['country']['iso_code'] ?? ''));
    if (!preg_match('/^[A-Z]{2}$/D', $country) || in_array($country, ['XX', 'ZZ'], true)) return [];
    $result = ['country_code' => $country];
    foreach (['region' => $record['subdivisions'][0]['names']['en'] ?? '', 'city' => $record['city']['names']['en'] ?? ''] as $key => $value) {
        if (!is_string($value)) continue;
        $value = trim(preg_replace('/[\p{C}]/u', ' ', $value) ?? '');
        if ($value !== '') $result[$key] = mb_substr($value, 0, 100);
    }
    return $result;
}

function audienceInteractionPlace(array $location): string
{
    $country = strtoupper((string) ($location['country_code'] ?? ''));
    $countries = addressCountryChoices();
    $parts = [];
    foreach (['city', 'region'] as $key) if (is_string($location[$key] ?? null) && trim($location[$key]) !== '') $parts[] = trim($location[$key]);
    if (preg_match('/^[A-Z]{2}$/D', $country)) $parts[] = $countries[$country]['name'] ?? $country;
    return implode(', ', $parts);
}
