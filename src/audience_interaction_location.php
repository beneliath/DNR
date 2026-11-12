<?php
declare(strict_types=1);

const AUDIENCE_DBIP_CITY_DATABASE = '/opt/dnr/geoip/dbip-city-lite.mmdb';
const AUDIENCE_MANAGED_DBIP_CITY_DATABASE = '/run/dnr/deployment/geoip/dbip-city-lite.mmdb';

/** Store the verified network address and an approximate, local IP lookup. */
function audienceInteractionLocation(array $server, ?callable $lookup = null, ?string $database = null): array
{
    $ip = requestIpAddress($server);
    if ($ip === null) return [];

    $location = ['ip_address' => $ip] + audienceInteractionIpLookup($ip, $lookup, $database);
    if (isset($location['country_code'])) return $location;
    $edgeCountry = function_exists('audienceCollectionCountry') ? audienceCollectionCountry($server) : null;
    if ($edgeCountry !== null) $location += ['country_code' => $edgeCountry, 'source' => 'trusted edge country'];
    return $location;
}

/** Prefer configured data. A country-only override can be refined by a matching City record. */
function audienceInteractionIpLookup(string $ip, ?callable $lookup = null, ?string $database = null): array
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) return [];
    $databases = $database === null
        ? array_unique([(string) getenv('DNR_GEOIP_DATABASE'), AUDIENCE_MANAGED_DBIP_CITY_DATABASE, AUDIENCE_DBIP_CITY_DATABASE])
        : [$database];
    $best = [];
    $source = '';
    foreach ($databases as $path) {
        if ($path === '' || ($lookup === null && !is_readable($path))) continue;
        $dbip = in_array($path, [AUDIENCE_MANAGED_DBIP_CITY_DATABASE, AUDIENCE_DBIP_CITY_DATABASE], true);
        try {
            if ($lookup === null) {
                $reader = new \MaxMind\Db\Reader($path);
                try {
                    $record = $reader->get($ip);
                    $dbip = $reader->metadata()->databaseType === 'DBIP-City-Lite';
                } finally { $reader->close(); }
            } else $record = $lookup($path, $ip);
            $resolved = audienceInteractionGeoRecord($record);
            if (count($resolved) <= count($best) || array_diff_assoc($best, $resolved)) continue;
            $best = $resolved;
            $source = $dbip ? 'DB-IP City Lite' : 'local GeoIP database';
            if (isset($best['region'], $best['city'])) break;
        } catch (Throwable) {
            applicationLog('warning', 'Audience interaction GeoIP lookup unavailable');
        }
    }
    return $best ? $best + ['source' => $source] : [];
}

/** Country databases and City databases share this shape; only City has finer detail. */
function audienceInteractionGeoRecord(mixed $record): array
{
    if (!is_array($record)) return [];
    $country = strtoupper((string) ($record['country']['iso_code'] ?? ''));
    if (!preg_match('/^[A-Z]{2}$/D', $country) || in_array($country, ['XX', 'ZZ'], true)) return [];
    $result = ['country_code' => $country];
    foreach (['region' => $record['subdivisions'][0]['names']['en'] ?? $record['subdivisions'][0]['iso_code'] ?? '', 'city' => $record['city']['names']['en'] ?? ''] as $key => $value) {
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

/** Reuse a lookup within one profile or digest, including when an address is unmapped. */
function audienceInteractionEstimateLookup(): Closure
{
    $byIp = [];
    return static function (string $ip) use (&$byIp): array {
        return $byIp[$ip] ??= audienceInteractionIpLookup($ip);
    };
}

/** Return a separately attributable estimate only when it adds detail to the saved event. */
function audienceInteractionCurrentEstimate(array $recorded, ?callable $lookup = null): ?array
{
    $ip = $recorded['ip_address'] ?? null;
    if (!empty($recorded['city']) && !empty($recorded['region'])) return null;
    if (!is_string($ip) || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) return null;

    $current = $lookup !== null ? $lookup($ip) : audienceInteractionIpLookup($ip);
    foreach (['city', 'region', 'country_code'] as $field) {
        if (empty($recorded[$field]) && !empty($current[$field])) {
            return ['place' => audienceInteractionPlace($current), 'source' => $current['source'] ?? ''];
        }
    }
    return null;
}
