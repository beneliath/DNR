<?php

declare(strict_types=1);
require_once __DIR__ . '/application_runtime.php';
require_once __DIR__ . '/app/Service/ArchiveService.php';
require_once __DIR__ . '/app/Http/ClientAddress.php';

foreach ([dirname(__DIR__) . '/vendor/autoload.php', '/opt/dnr/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;
        break;
    }
}

use Dnr\Service\ArchiveService;
use Dnr\Http\ClientAddress;

function assetUrl($path) {
    $path = ltrim((string) $path, '/');
    $version = defined('APP_VERSION') ? APP_VERSION : 'dev';
    $url = $path . (str_contains($path, '?') ? '&' : '?') . 'v=' . rawurlencode($version);

    // Static assets are cached as immutable. Prefer the build manifest so a
    // request does not re-read and hash large bundles; retain a development
    // fallback for newly added files before the next asset build.
    $asset_path = parse_url($path, PHP_URL_PATH);
    if (is_string($asset_path) && str_starts_with($asset_path, 'assets/')) {
        static $manifest = null;
        if ($manifest === null) {
            $manifest_path = __DIR__ . '/assets/asset-manifest.php';
            $loaded_manifest = is_file($manifest_path) ? require $manifest_path : [];
            $manifest = is_array($loaded_manifest) ? $loaded_manifest : [];
        }
        $fingerprint = $manifest[$asset_path] ?? '';
        if (!is_string($fingerprint) || preg_match('/\A[0-9a-f]{12}\z/', $fingerprint) !== 1) {
            $local_path = __DIR__ . '/' . $asset_path;
            if (is_file($local_path)) {
                static $fallback_fingerprints = [];
                if (!array_key_exists($local_path, $fallback_fingerprints)) {
                    $hash = hash_file('sha256', $local_path);
                    $fallback_fingerprints[$local_path] = is_string($hash) ? substr($hash, 0, 12) : '';
                }
                $fingerprint = $fallback_fingerprints[$local_path];
            }
        }
        if (is_string($fingerprint) && $fingerprint !== '') {
            $url .= '&h=' . rawurlencode($fingerprint);
        }
    }

    return $url;
}

function renderPageHead($title, array $options = []) {
    $full_title = trim((string) $title);
    if ($full_title === '') {
        $full_title = applicationBrandName();
    }
    $styles = $options['styles'] ?? ['assets/css/style.min.css', 'assets/css/modern.min.css'];
    $scripts = $options['scripts'] ?? [];
    echo '<head>' . PHP_EOL;
    echo '    <meta charset="UTF-8">' . PHP_EOL;
    echo '    <meta name="viewport" content="width=device-width, initial-scale=1">' . PHP_EOL;
    echo '    <title>' . htmlspecialchars($full_title, ENT_QUOTES, 'UTF-8') . '</title>' . PHP_EOL;
    echo '    <link rel="icon" type="image/svg+xml" href="'
        . htmlspecialchars(assetUrl('assets/favicon.svg'), ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
    foreach ($styles as $style) {
        echo '    <link rel="stylesheet" href="'
            . htmlspecialchars(assetUrl((string) $style), ENT_QUOTES, 'UTF-8') . '">' . PHP_EOL;
    }
    foreach ($scripts as $script) {
        $path = is_array($script) ? ($script['path'] ?? '') : $script;
        if ($path === '') {
            continue;
        }
        $defer = !is_array($script) || !array_key_exists('defer', $script) || $script['defer'];
        renderScript((string) $path, $defer);
    }
    echo '</head>' . PHP_EOL;
}

function renderScript($path, $defer = true) {
    echo '<script src="' . htmlspecialchars(assetUrl((string) $path), ENT_QUOTES, 'UTF-8') . '"'
        . ($defer ? ' defer' : '') . '></script>' . PHP_EOL;
}

function requestUsesHttps(?array $server = null) {
    $server = $server ?? $_SERVER;
    if (!empty($server['HTTPS']) && strtolower((string) $server['HTTPS']) !== 'off') {
        return true;
    }

    $remote_address = filter_var(
        trim((string) ($server['REMOTE_ADDR'] ?? '')),
        FILTER_VALIDATE_IP
    );
    if (!$remote_address || !isTrustedProxyAddress($remote_address)) {
        return false;
    }

    $forwarded_proto = strtolower(trim(explode(',', (string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
    return $forwarded_proto === 'https';
}

function applicationRequiresHttps() {
    return filter_var(getenv('DNR_REQUIRE_HTTPS') ?: '0', FILTER_VALIDATE_BOOL);
}

function contentSecurityPolicyNonce() {
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(18));
    }
    return $nonce;
}

function sendApplicationSecurityHeaders() {
    if (headers_sent()) {
        return;
    }

    $nonce = contentSecurityPolicyNonce();
    $page = basename((string) ($_SERVER['PHP_SELF'] ?? ''));
    $style_source = $page === 'map.php' ? "'self' 'unsafe-inline'" : "'self'";
    $style_attribute_source = $page === 'map.php' ? "'unsafe-inline'" : "'none'";
    $map_tile_source = deploymentConfig()->tileCspSource();
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$nonce}'; script-src-attr 'none'; style-src {$style_source}; style-src-attr {$style_attribute_source}; img-src 'self' data: blob: {$map_tile_source}; font-src 'self'; connect-src 'self' {$map_tile_source}; worker-src 'self' blob:; child-src blob:; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

// Start a cookie-only session with safe defaults for HTTP development and HTTPS deployment.
function startSecureSession() {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    $is_https = requestUsesHttps();
    if (applicationRequiresHttps() && !$is_https) {
        http_response_code(400);
        exit('HTTPS is required for this deployment.');
    }
    sendApplicationSecurityHeaders();
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $is_https || applicationRequiresHttps(),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();

    $now = time();
    $idle_timeout = max(300, (int) (getenv('DNR_SESSION_IDLE_SECONDS') ?: 43200));
    $absolute_timeout = max($idle_timeout, (int) (getenv('DNR_SESSION_ABSOLUTE_SECONDS') ?: 86400));
    $rotation_interval = max(300, (int) (getenv('DNR_SESSION_ROTATION_SECONDS') ?: 900));
    $started_at = (int) ($_SESSION['_session_started_at'] ?? $now);
    $last_seen_at = (int) ($_SESSION['_session_last_seen_at'] ?? $now);

    if (($now - $last_seen_at) > $idle_timeout || ($now - $started_at) > $absolute_timeout) {
        session_unset();
        session_regenerate_id(true);
        $started_at = $now;
    } elseif (($now - (int) ($_SESSION['_session_rotated_at'] ?? $started_at)) >= $rotation_interval) {
        session_regenerate_id(true);
        $_SESSION['_session_rotated_at'] = $now;
    }

    $_SESSION['_session_started_at'] = $started_at;
    $_SESSION['_session_last_seen_at'] = $now;
    $_SESSION['_session_rotated_at'] = (int) ($_SESSION['_session_rotated_at'] ?? $now);
}

/**
 * Persist the current session and release its file lock before slow read-only
 * database work or response streaming. Session values remain readable for the
 * remainder of the request, but callers must perform every required session
 * mutation (including CSRF token creation) before invoking this helper.
 */
function releaseApplicationSessionLock(): bool {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return true;
    }

    $released = session_write_close();
    if (!$released) {
        applicationLog('warning', 'Unable to release the PHP session lock early');
    }

    return $released;
}

function validIsoDate($date) {
    if (!is_string($date)) {
        return false;
    }
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
}

function requireValidDateRange($start_date, $end_date) {
    if (!validIsoDate($start_date) || !validIsoDate($end_date) || $end_date < $start_date) {
        throw new InvalidArgumentException('Enter a valid event start and end date. The end date cannot precede the start date.');
    }
}

function nullableNonNegativeAmount($value, $label) {
    if (!is_scalar($value) && $value !== null) {
        throw new InvalidArgumentException("Enter a valid {$label} amount.");
    }
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $value)) {
        throw new InvalidArgumentException("Enter a non-negative {$label} amount with no more than two decimal places.");
    }
    return (float) $value;
}

function fulltextSearchQuery($search, $maximum_terms = 8) {
    $search = trim(substr((string) $search, 0, 256));
    if ($search === '' || preg_match('//u', $search) !== 1) {
        return '';
    }
    $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $boolean_terms = [];
    foreach (array_slice($terms, 0, max(1, (int) $maximum_terms)) as $term) {
        $term = preg_replace('/[+\-><()~*"@]+/u', '', $term) ?? '';
        if (strlen($term) >= 3) {
            $boolean_terms[] = '+' . substr($term, 0, 64) . '*';
        }
    }
    return implode(' ', array_values(array_unique($boolean_terms)));
}

function nullableAmountsEqual($left, $right) {
    if ($left === null || $left === '') {
        return $right === null || $right === '';
    }
    if ($right === null || $right === '') {
        return false;
    }
    return (float) $left === (float) $right;
}

function normalizedHttpUrl($url) {
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) ? $url : null;
}

/**
 * @return array<string, array{code: string, name: string, flag: string}>
 */
function addressCountryChoices() {
    static $choices = null;
    if ($choices !== null) {
        return $choices;
    }

    $countries = \Giggsey\Locale\Locale::getAllCountriesForLocale('en');

    // The locale data omits two uninhabited ISO territories and includes a few
    // non-country calling-code regions. Keep this address list to ISO 3166-1,
    // with Kosovo included because it is commonly offered in country pickers.
    $countries['BV'] = 'Bouvet Island';
    $countries['HM'] = 'Heard Island and McDonald Islands';
    foreach (['AC', 'CQ', 'DG', 'EA', 'IC', 'TA'] as $non_country_code) {
        unset($countries[$non_country_code]);
    }
    $countries['US'] = 'United States of America';
    natcasesort($countries);

    $choices = [];
    foreach ($countries as $code => $name) {
        if (preg_match('/\A[A-Z]{2}\z/', $code) !== 1) {
            continue;
        }
        $choices[$code] = [
            'code' => $code,
            'name' => $name,
            'flag' => mb_chr(0x1F1E6 + ord($code[0]) - 65, 'UTF-8')
                . mb_chr(0x1F1E6 + ord($code[1]) - 65, 'UTF-8'),
        ];
    }

    return $choices;
}

function normalizeAddressCountryCode($country) {
    if (!is_scalar($country) && $country !== null) {
        return null;
    }

    $country = trim((string) $country);
    if ($country === '') {
        return '';
    }

    $upper_country = strtoupper($country);
    $upper_country = ['USA' => 'US', 'CAN' => 'CA'][$upper_country] ?? $upper_country;
    $choices = addressCountryChoices();
    if (isset($choices[$upper_country])) {
        return $upper_country;
    }

    $normalized_name = mb_strtolower($country, 'UTF-8');
    $name_aliases = [
        'united states' => 'US',
        'u.s.' => 'US',
        'u.s.a.' => 'US',
    ];
    if (isset($name_aliases[$normalized_name])) {
        return $name_aliases[$normalized_name];
    }
    foreach ($choices as $code => $choice) {
        if (mb_strtolower($choice['name'], 'UTF-8') === $normalized_name) {
            return $code;
        }
    }

    return null;
}

function addressCountryName($country) {
    $code = normalizeAddressCountryCode($country);
    if ($code !== null && $code !== '') {
        return addressCountryChoices()[$code]['name'];
    }
    return is_scalar($country) ? trim((string) $country) : '';
}

function addressCountrySelectOptions($selected_country = null) {
    $selected_country = $selected_country ?? applicationDefaultCountry();
    $selected_code = normalizeAddressCountryCode($selected_country);
    $escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $html = '';
    foreach (addressCountryChoices() as $code => $choice) {
        $html .= '<option value="' . $escape($code) . '"'
            . ($code === $selected_code ? ' selected' : '') . '>'
            . $escape($choice['flag'] . ' ' . $choice['name']) . '</option>' . PHP_EOL;
    }
    return $html;
}

function addressCountryPicker(
    $field_name,
    $selected_country = null,
    $address_prefix = '',
    $required = false
) {
    $selected_country = $selected_country ?? applicationDefaultCountry();
    $selected_code = normalizeAddressCountryCode($selected_country) ?? applicationDefaultCountry();
    $choices = addressCountryChoices();
    if (!isset($choices[$selected_code])) {
        $selected_code = 'US';
    }
    $selected_choice = $choices[$selected_code];
    $escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $id_token = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string) $address_prefix);
    $id_token = trim((string) $id_token, '-') ?: 'address';
    $menu_id = $id_token . '-country-menu';

    $html = '<div class="address-country-picker" data-address-country-picker>';
    $html .= '<select class="address-country-native-select" name="' . $escape($field_name) . '"'
        . ($required ? ' required' : '') . ' tabindex="-1" aria-hidden="true" data-address-country="'
        . $escape($address_prefix) . '" data-address-country-native>';
    $html .= addressCountrySelectOptions($selected_code);
    $html .= '</select>';
    $html .= '<button type="button" class="address-country-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="'
        . $escape($menu_id) . '" aria-label="' . $escape('Country: ' . $selected_choice['name'] . ' (' . $selected_code . ')')
        . '" data-address-country-toggle>';
    $html .= '<span class="address-country-flag" aria-hidden="true" data-address-country-flag>'
        . $escape($selected_choice['flag']) . '</span>';
    $html .= '<span class="address-country-label" data-address-country-label>'
        . $escape($selected_choice['name']) . '</span>';
    $html .= '<span class="address-country-chevron" aria-hidden="true"></span></button>';
    $html .= '<div class="address-country-menu" id="' . $escape($menu_id)
        . '" role="listbox" aria-label="Country options" hidden data-address-country-menu>';
    foreach ($choices as $code => $choice) {
        $is_selected = $code === $selected_code;
        $html .= '<button type="button" class="address-country-option" role="option" tabindex="-1" aria-selected="'
            . ($is_selected ? 'true' : 'false') . '" data-address-country-option data-country-code="'
            . $escape($code) . '" data-country-flag="' . $escape($choice['flag'])
            . '" data-country-name="' . $escape($choice['name']) . '">';
        $html .= '<span class="address-country-option-flag" aria-hidden="true">'
            . $escape($choice['flag']) . '</span>';
        $html .= '<span class="address-country-option-name">' . $escape($choice['name']) . '</span>';
        $html .= '<span class="address-country-option-code">' . $escape($code) . '</span>';
        $html .= '</button>';
    }
    $html .= '</div></div>';

    return $html;
}

/**
 * @return array<string, array{code: string, name: string}>
 */
function addressRegionChoices($country) {
    $country_code = normalizeAddressCountryCode($country);
    $regions = [
        'US' => [
            'AL' => 'Alabama',
            'AK' => 'Alaska',
            'AZ' => 'Arizona',
            'AR' => 'Arkansas',
            'CA' => 'California',
            'CO' => 'Colorado',
            'CT' => 'Connecticut',
            'DE' => 'Delaware',
            'DC' => 'District of Columbia',
            'FL' => 'Florida',
            'GA' => 'Georgia',
            'HI' => 'Hawaii',
            'ID' => 'Idaho',
            'IL' => 'Illinois',
            'IN' => 'Indiana',
            'IA' => 'Iowa',
            'KS' => 'Kansas',
            'KY' => 'Kentucky',
            'LA' => 'Louisiana',
            'ME' => 'Maine',
            'MD' => 'Maryland',
            'MA' => 'Massachusetts',
            'MI' => 'Michigan',
            'MN' => 'Minnesota',
            'MS' => 'Mississippi',
            'MO' => 'Missouri',
            'MT' => 'Montana',
            'NE' => 'Nebraska',
            'NV' => 'Nevada',
            'NH' => 'New Hampshire',
            'NJ' => 'New Jersey',
            'NM' => 'New Mexico',
            'NY' => 'New York',
            'NC' => 'North Carolina',
            'ND' => 'North Dakota',
            'OH' => 'Ohio',
            'OK' => 'Oklahoma',
            'OR' => 'Oregon',
            'PA' => 'Pennsylvania',
            'RI' => 'Rhode Island',
            'SC' => 'South Carolina',
            'SD' => 'South Dakota',
            'TN' => 'Tennessee',
            'TX' => 'Texas',
            'UT' => 'Utah',
            'VT' => 'Vermont',
            'VA' => 'Virginia',
            'WA' => 'Washington',
            'WV' => 'West Virginia',
            'WI' => 'Wisconsin',
            'WY' => 'Wyoming',
        ],
        'CA' => [
            'AB' => 'Alberta',
            'BC' => 'British Columbia',
            'MB' => 'Manitoba',
            'NB' => 'New Brunswick',
            'NL' => 'Newfoundland and Labrador',
            'NT' => 'Northwest Territories',
            'NS' => 'Nova Scotia',
            'NU' => 'Nunavut',
            'ON' => 'Ontario',
            'PE' => 'Prince Edward Island',
            'QC' => 'Quebec',
            'SK' => 'Saskatchewan',
            'YT' => 'Yukon',
        ],
    ];
    if (!is_string($country_code) || !isset($regions[$country_code])) {
        return [];
    }

    $choices = [];
    foreach ($regions[$country_code] as $code => $name) {
        $choices[$code] = [
            'code' => $code,
            'name' => $name,
        ];
    }
    return $choices;
}

function normalizeAddressRegion($country, $region) {
    if (!is_scalar($region) && $region !== null) {
        return null;
    }
    $region = trim((string) $region);
    if ($region === '') {
        return '';
    }

    $choices = addressRegionChoices($country);
    if ($choices === []) {
        return $region;
    }

    $upper_region = strtoupper($region);
    if (isset($choices[$upper_region])) {
        return $upper_region;
    }
    $normalized_name = mb_strtolower($region, 'UTF-8');
    foreach ($choices as $code => $choice) {
        if (mb_strtolower($choice['name'], 'UTF-8') === $normalized_name) {
            return $code;
        }
    }
    return null;
}

function addressRegionName($country, $region) {
    $normalized_region = normalizeAddressRegion($country, $region);
    $choices = addressRegionChoices($country);
    if ($normalized_region !== null && isset($choices[$normalized_region])) {
        return $choices[$normalized_region]['name'];
    }
    return is_scalar($region) ? trim((string) $region) : '';
}

/**
 * @return array<string, array{label: string, choices: list<array{code: string, name: string}>}>
 */
function addressRegionClientData() {
    $configuration = [];
    foreach (['US' => 'State', 'CA' => 'Province'] as $country_code => $label) {
        $configuration[$country_code] = [
            'label' => $label,
            'choices' => array_values(addressRegionChoices($country_code)),
        ];
    }
    return $configuration;
}

function normalizePhoneCountryCode($country_code) {
    if (!is_scalar($country_code) && $country_code !== null) {
        throw new InvalidArgumentException('Select a valid telephone country code.');
    }

    $country_code = preg_replace('/[\s()-]+/', '', trim((string) $country_code));
    if (!preg_match('/\A\+[1-9][0-9]{0,2}\z/', $country_code)) {
        throw new InvalidArgumentException('Select a valid telephone country code.');
    }

    return $country_code;
}

function normalizePhoneNumber($country_code, $national_number, $label = 'Phone number') {
    if (!is_scalar($national_number) && $national_number !== null) {
        throw new InvalidArgumentException("Enter a valid {$label} for the selected country.");
    }

    $national_number = trim((string) $national_number);
    if ($national_number === '') {
        return '';
    }

    $country_code = normalizePhoneCountryCode($country_code);
    $phone_util = \libphonenumber\PhoneNumberUtil::getInstance();
    $expected_country_code = (int) substr($country_code, 1);
    $region = $phone_util->getRegionCodeForCountryCode($expected_country_code);
    if ($region === '' || $region === '001') {
        throw new InvalidArgumentException("Select a supported country for {$label}.");
    }

    try {
        $phone_number = $phone_util->parse(
            $national_number,
            str_starts_with($national_number, '+') ? null : $region
        );
    } catch (\libphonenumber\NumberParseException $exception) {
        throw new InvalidArgumentException("Enter a valid {$label} for the selected country.");
    }

    if ($phone_number->getCountryCode() !== $expected_country_code
        || !$phone_util->isValidNumber($phone_number)
    ) {
        throw new InvalidArgumentException("Enter a valid {$label} for the selected country.");
    }

    return $phone_util->format($phone_number, \libphonenumber\PhoneNumberFormat::E164);
}

function phoneNumberInputParts($stored_phone, $default_country_code = null) {
    $default_country_code = $default_country_code ?? applicationDefaultPhoneCountryCode();
    $stored_phone = trim((string) $stored_phone);
    try {
        $country_code = normalizePhoneCountryCode($default_country_code);
    } catch (InvalidArgumentException $exception) {
        $country_code = '+1';
    }

    if ($stored_phone === '') {
        return [$country_code, ''];
    }

    try {
        $phone_util = \libphonenumber\PhoneNumberUtil::getInstance();
        if (str_starts_with($stored_phone, '+')) {
            $phone_number = $phone_util->parse($stored_phone, null);
            if (!$phone_util->isValidNumber($phone_number)) {
                throw new InvalidArgumentException('The stored telephone number is invalid.');
            }
        } else {
            $normalized = normalizePhoneNumber($country_code, $stored_phone);
            $phone_number = $phone_util->parse($normalized, null);
        }
        return [
            '+' . $phone_number->getCountryCode(),
            $phone_util->format($phone_number, \libphonenumber\PhoneNumberFormat::NATIONAL),
        ];
    } catch (Throwable $exception) {
        return [$country_code, $stored_phone];
    }
}

function formatPhoneNumberForDisplay($stored_phone, $default_country_code = null) {
    $default_country_code = $default_country_code ?? applicationDefaultPhoneCountryCode();
    $stored_phone = trim((string) $stored_phone);
    if ($stored_phone === '') {
        return '';
    }

    try {
        $phone_util = \libphonenumber\PhoneNumberUtil::getInstance();
        if (str_starts_with($stored_phone, '+')) {
            $phone_number = $phone_util->parse($stored_phone, null);
            if (!$phone_util->isValidNumber($phone_number)) {
                return $stored_phone;
            }
        } else {
            $normalized = normalizePhoneNumber($default_country_code, $stored_phone);
            $phone_number = $phone_util->parse($normalized, null);
        }
        return $phone_util->format(
            $phone_number,
            \libphonenumber\PhoneNumberFormat::INTERNATIONAL
        );
    } catch (Throwable $exception) {
        return $stored_phone;
    }
}

function phoneCountryCallingCodeChoices() {
    return [
        ['code' => '+54', 'flag' => '🇦🇷', 'country' => 'Argentina'],
        ['code' => '+61', 'flag' => '🇦🇺', 'country' => 'Australia'],
        ['code' => '+43', 'flag' => '🇦🇹', 'country' => 'Austria'],
        ['code' => '+32', 'flag' => '🇧🇪', 'country' => 'Belgium'],
        ['code' => '+55', 'flag' => '🇧🇷', 'country' => 'Brazil'],
        ['code' => '+359', 'flag' => '🇧🇬', 'country' => 'Bulgaria'],
        ['code' => '+56', 'flag' => '🇨🇱', 'country' => 'Chile'],
        ['code' => '+86', 'flag' => '🇨🇳', 'country' => 'China'],
        ['code' => '+57', 'flag' => '🇨🇴', 'country' => 'Colombia'],
        ['code' => '+385', 'flag' => '🇭🇷', 'country' => 'Croatia'],
        ['code' => '+357', 'flag' => '🇨🇾', 'country' => 'Cyprus'],
        ['code' => '+420', 'flag' => '🇨🇿', 'country' => 'Czechia'],
        ['code' => '+45', 'flag' => '🇩🇰', 'country' => 'Denmark'],
        ['code' => '+20', 'flag' => '🇪🇬', 'country' => 'Egypt'],
        ['code' => '+358', 'flag' => '🇫🇮', 'country' => 'Finland'],
        ['code' => '+33', 'flag' => '🇫🇷', 'country' => 'France'],
        ['code' => '+49', 'flag' => '🇩🇪', 'country' => 'Germany'],
        ['code' => '+30', 'flag' => '🇬🇷', 'country' => 'Greece'],
        ['code' => '+36', 'flag' => '🇭🇺', 'country' => 'Hungary'],
        ['code' => '+354', 'flag' => '🇮🇸', 'country' => 'Iceland'],
        ['code' => '+91', 'flag' => '🇮🇳', 'country' => 'India'],
        ['code' => '+62', 'flag' => '🇮🇩', 'country' => 'Indonesia'],
        ['code' => '+98', 'flag' => '🇮🇷', 'country' => 'Iran'],
        ['code' => '+353', 'flag' => '🇮🇪', 'country' => 'Ireland'],
        ['code' => '+972', 'flag' => '🇮🇱', 'country' => 'Israel'],
        ['code' => '+39', 'flag' => '🇮🇹', 'country' => 'Italy'],
        ['code' => '+81', 'flag' => '🇯🇵', 'country' => 'Japan'],
        ['code' => '+962', 'flag' => '🇯🇴', 'country' => 'Jordan'],
        ['code' => '+254', 'flag' => '🇰🇪', 'country' => 'Kenya'],
        ['code' => '+60', 'flag' => '🇲🇾', 'country' => 'Malaysia'],
        ['code' => '+52', 'flag' => '🇲🇽', 'country' => 'Mexico'],
        ['code' => '+31', 'flag' => '🇳🇱', 'country' => 'Netherlands'],
        ['code' => '+64', 'flag' => '🇳🇿', 'country' => 'New Zealand'],
        ['code' => '+234', 'flag' => '🇳🇬', 'country' => 'Nigeria'],
        ['code' => '+47', 'flag' => '🇳🇴', 'country' => 'Norway'],
        ['code' => '+92', 'flag' => '🇵🇰', 'country' => 'Pakistan'],
        ['code' => '+51', 'flag' => '🇵🇪', 'country' => 'Peru'],
        ['code' => '+63', 'flag' => '🇵🇭', 'country' => 'Philippines'],
        ['code' => '+48', 'flag' => '🇵🇱', 'country' => 'Poland'],
        ['code' => '+351', 'flag' => '🇵🇹', 'country' => 'Portugal'],
        ['code' => '+974', 'flag' => '🇶🇦', 'country' => 'Qatar'],
        ['code' => '+40', 'flag' => '🇷🇴', 'country' => 'Romania'],
        ['code' => '+7', 'flag' => '🇷🇺', 'country' => 'Russia / Kazakhstan'],
        ['code' => '+966', 'flag' => '🇸🇦', 'country' => 'Saudi Arabia'],
        ['code' => '+65', 'flag' => '🇸🇬', 'country' => 'Singapore'],
        ['code' => '+27', 'flag' => '🇿🇦', 'country' => 'South Africa'],
        ['code' => '+82', 'flag' => '🇰🇷', 'country' => 'South Korea'],
        ['code' => '+34', 'flag' => '🇪🇸', 'country' => 'Spain'],
        ['code' => '+46', 'flag' => '🇸🇪', 'country' => 'Sweden'],
        ['code' => '+41', 'flag' => '🇨🇭', 'country' => 'Switzerland'],
        ['code' => '+886', 'flag' => '🇹🇼', 'country' => 'Taiwan'],
        ['code' => '+66', 'flag' => '🇹🇭', 'country' => 'Thailand'],
        ['code' => '+90', 'flag' => '🇹🇷', 'country' => 'Turkey'],
        ['code' => '+380', 'flag' => '🇺🇦', 'country' => 'Ukraine'],
        ['code' => '+971', 'flag' => '🇦🇪', 'country' => 'United Arab Emirates'],
        ['code' => '+44', 'flag' => '🇬🇧', 'country' => 'United Kingdom'],
        ['code' => '+1', 'flag' => '🇺🇸', 'country' => 'United States / Canada'],
        ['code' => '+84', 'flag' => '🇻🇳', 'country' => 'Vietnam'],
    ];
}

function phoneCountryPicker($field_name, $selected_code = null, $aria_label = 'Phone country code') {
    $selected_code = $selected_code ?? applicationDefaultPhoneCountryCode();
    try {
        $selected_code = normalizePhoneCountryCode($selected_code);
    } catch (InvalidArgumentException $exception) {
        $selected_code = '+1';
    }

    $choices = phoneCountryCallingCodeChoices();
    $selected_choice = null;
    foreach ($choices as $choice) {
        if ($choice['code'] === $selected_code) {
            $selected_choice = $choice;
            break;
        }
    }
    if ($selected_choice === null) {
        $selected_choice = ['code' => $selected_code, 'flag' => '🌐', 'country' => 'International'];
        array_unshift($choices, $selected_choice);
    }

    $escape = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    $html = '<div class="phone-country-picker" data-phone-country-picker>';
    $html .= '<button type="button" class="phone-country-trigger" aria-haspopup="listbox" aria-expanded="false" aria-label="'
        . $escape($aria_label . ': ' . $selected_choice['country'] . ' ' . $selected_code)
        . '" data-phone-country-label="' . $escape($aria_label) . '" data-phone-country-toggle>';
    $html .= '<span class="phone-country-flag" aria-hidden="true" data-phone-country-flag>'
        . $escape($selected_choice['flag']) . '</span>';
    $html .= '<span class="phone-country-dial-code" data-phone-country-dial-code>'
        . $escape($selected_code) . '</span>';
    $html .= '<span class="phone-country-chevron" aria-hidden="true"></span></button>';
    $html .= '<input type="hidden" name="' . $escape($field_name) . '" value="'
        . $escape($selected_code) . '" data-phone-country-code>';
    $html .= '<div class="phone-country-menu" role="listbox" hidden data-phone-country-menu>';
    foreach ($choices as $choice) {
        $is_selected = $choice['code'] === $selected_code;
        $html .= '<button type="button" class="phone-country-option" role="option" aria-selected="'
            . ($is_selected ? 'true' : 'false') . '" data-phone-country-option data-country-code="'
            . $escape($choice['code']) . '" data-country-flag="' . $escape($choice['flag'])
            . '" data-country-name="' . $escape($choice['country']) . '">';
        $html .= '<span class="phone-country-option-flag" aria-hidden="true">'
            . $escape($choice['flag']) . '</span>';
        $html .= '<span class="phone-country-option-name">' . $escape($choice['country']) . '</span>';
        $html .= '<span class="phone-country-option-code">' . $escape($choice['code']) . '</span>';
        $html .= '</button>';
    }
    $html .= '</div></div>';

    return $html;
}

function normalizeEventType($event_type, $event_type_other) {
    $event_type = trim((string) $event_type);
    $event_type_other = trim((string) $event_type_other);
    $valid_types = \Dnr\Domain\ReferenceData::eventTypes();
    if (!in_array($event_type, $valid_types, true)) {
        throw new InvalidArgumentException('Select a valid event type.');
    }
    if ($event_type === 'other') {
        if ($event_type_other === '' || mb_strlen($event_type_other, 'UTF-8') > 50) {
            throw new InvalidArgumentException('Describe the other event type using 50 characters or fewer.');
        }
        return ['other', $event_type_other];
    }
    return [$event_type, null];
}

function requireActiveOrganization(mysqli $conn, $organization_id, $lock = false) {
    $organization_id = (int) $organization_id;
    if ($organization_id < 1) {
        throw new InvalidArgumentException('Please select an active organization.');
    }
    $sql = 'SELECT id FROM organizations WHERE id = ? AND is_deleted = 0';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to verify the selected organization.');
    }
    $stmt->bind_param('i', $organization_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to verify the selected organization.');
    }
    $exists = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    if (!$exists) {
        throw new InvalidArgumentException('Please select an active organization.');
    }
    return $organization_id;
}

// A password-only or pre-2FA session must never satisfy application authorization.
function isLoggedIn() {
    return isset($_SESSION['user_id'], $_SESSION['auth_version'])
        && ($_SESSION['auth_complete'] ?? false) === true;
}

function isApplicationRootRequest(array $server) {
    $request_path = parse_url((string) ($server['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if (!is_string($request_path)) {
        return false;
    }

    $script_name = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? '/index.php'));
    $script_directory = rtrim(dirname($script_name), '/.');
    $application_root = ($script_directory !== '' ? $script_directory : '') . '/';

    return $request_path === $application_root;
}

// Ensure that the user is logged in; if not, redirect to the login page
function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: login.php"); // Redirect to login page if not logged in
        exit(); // Ensure no further code is executed after the redirect
    }

    global $conn;
    if (!($conn instanceof mysqli)) {
        http_response_code(500);
        exit('Authentication service unavailable.');
    }

    $user_id = (int) $_SESSION['user_id'];
    $stmt = $conn->prepare(
        'SELECT username, role, auth_version, must_change_password, account_status,
                first_name, last_name, profile_picture_updated_at
         FROM users
         WHERE id = ?'
    );
    if (!$stmt) {
        applicationLog('error', 'Authentication schema is unavailable', ['error' => $conn->error]);
        http_response_code(503);
        exit(applicationBrandName() . ' is being upgraded. The authentication database migration is required.');
    }
    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $user = $stmt->get_result()->fetch_assoc();

    if (!$user
        || $user['account_status'] !== 'active'
        || (int) $user['auth_version'] !== (int) $_SESSION['auth_version']
    ) {
        session_unset();
        session_regenerate_id(true);
        header('Location: login.php');
        exit();
    }

    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['profile_first_name'] = trim((string) ($user['first_name'] ?? ''));
    $profile_display_name = trim(
        trim((string) ($user['first_name'] ?? ''))
        . ' '
        . trim((string) ($user['last_name'] ?? ''))
    );
    $_SESSION['profile_display_name'] = $profile_display_name !== ''
        ? $profile_display_name
        : (string) $user['username'];
    $_SESSION['profile_picture_version'] = ($user['profile_picture_updated_at'] ?? null) !== null
        ? (int) strtotime((string) $user['profile_picture_updated_at'])
        : 0;
    setDatabaseAuditContext($conn, $user_id, (string) $user['username']);
    $_SESSION['must_change_password'] = !empty($user['must_change_password']);
    $current_script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $password_change_pages = [
        'two_factor_settings.php',
        'two_factor_recovery_codes.php',
        'profile_picture.php',
    ];
    if ($_SESSION['must_change_password'] && !in_array($current_script, $password_change_pages, true)) {
        header('Location: two_factor_settings.php?password_reset_required=1');
        exit();
    }
}

function requireAdmin() {
    requireLogin();

    if (!checkRole('admin')) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

// Keep a password-verified identity separate until its required second factor succeeds.
function beginPendingAuthentication(array $user) {
    session_regenerate_id(true);
    unset(
        $_SESSION['user_id'],
        $_SESSION['username'],
        $_SESSION['role'],
        $_SESSION['auth_version'],
        $_SESSION['auth_complete'],
        $_SESSION['two_factor_verified_at'],
        $_SESSION['must_change_password']
    );

    $_SESSION['_pending_auth'] = [
        'user_id' => (int) $user['id'],
        'username' => (string) $user['username'],
        'role' => (string) $user['role'],
        'auth_version' => (int) $user['auth_version'],
        'issued_at' => time(),
    ];
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

function getPendingAuthentication() {
    $pending = $_SESSION['_pending_auth'] ?? null;

    // A fully authenticated user may have a separate in-progress 2FA
    // enrollment. The absence of a pending-login record must not erase it.
    if ($pending === null) {
        return null;
    }

    if (!is_array($pending)
        || empty($pending['user_id'])
        || empty($pending['username'])
        || empty($pending['role'])
        || !isset($pending['auth_version'])
        || !isset($pending['issued_at'])
        || (time() - (int) $pending['issued_at']) > 600
    ) {
        unset($_SESSION['_pending_auth'], $_SESSION['_two_factor_enrollment']);
        return null;
    }

    return $pending;
}

// Password recovery is allowed only after proving possession of an enrolled
// authenticator or one unused recovery code. Keep that proof short-lived.
function beginPasswordRecovery($eligible_user = null, $attempted_username = '') {
    session_regenerate_id(true);
    unset(
        $_SESSION['user_id'],
        $_SESSION['username'],
        $_SESSION['role'],
        $_SESSION['auth_version'],
        $_SESSION['auth_complete'],
        $_SESSION['two_factor_verified_at'],
        $_SESSION['_pending_auth'],
        $_SESSION['_two_factor_enrollment']
    );

    $_SESSION['_password_recovery'] = [
        'user_id' => is_array($eligible_user) ? (int) $eligible_user['id'] : 0,
        'auth_version' => is_array($eligible_user) ? (int) $eligible_user['auth_version'] : 0,
        'account_identifier' => authenticationRateLimitAccountIdentifier($attempted_username),
        'stage' => 'verify',
        'issued_at' => time(),
        'verified_at' => null,
        'attempts' => 0,
    ];
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

function getPasswordRecovery($required_stage = null, $now = null) {
    $recovery = $_SESSION['_password_recovery'] ?? null;
    $now = $now ?? time();

    if (!is_array($recovery)
        || !isset($recovery['user_id'], $recovery['auth_version'], $recovery['stage'], $recovery['issued_at'])
        || ($now - (int) $recovery['issued_at']) > 600
        || ($required_stage !== null && $recovery['stage'] !== $required_stage)
        || ($recovery['stage'] === 'reset'
            && (!isset($recovery['verified_at']) || ($now - (int) $recovery['verified_at']) > 300))
    ) {
        unset($_SESSION['_password_recovery']);
        return null;
    }

    return $recovery;
}

function markPasswordRecoveryVerified() {
    $recovery = getPasswordRecovery('verify');
    if (!$recovery) {
        return false;
    }

    session_regenerate_id(true);
    $recovery['stage'] = 'reset';
    $recovery['verified_at'] = time();
    $recovery['attempts'] = 0;
    $_SESSION['_password_recovery'] = $recovery;
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    return true;
}

function clearPasswordRecovery() {
    unset($_SESSION['_password_recovery']);
}

function completeAuthentication(mysqli $conn, array $user, $two_factor_verified = false) {
    if (($user['account_status'] ?? 'active') !== 'active') {
        throw new RuntimeException('Inactive accounts cannot complete authentication.');
    }
    $user_id = (int) $user['id'];
    setDatabaseAuditContext($conn, $user_id, (string) $user['username']);
    $stmt = $conn->prepare(
        'UPDATE users
         SET last_login_at = UTC_TIMESTAMP(),
             last_updated_at = last_updated_at
         WHERE id = ?'
    );

    if ($stmt) {
        $stmt->bind_param('i', $user_id);
        if (!$stmt->execute()) {
            applicationLog('error', 'Unable to record the successful login timestamp', ['error' => $stmt->error]);
        }
    } else {
        applicationLog('error', 'Unable to prepare the successful login timestamp update', ['error' => $conn->error]);
    }

    recordAuditEvent($conn, [
        'event_category' => 'login',
        'event_type' => 'successful_login',
        'actor_user_id' => $user_id,
        'actor_username' => (string) $user['username'],
        'target_user_id' => $user_id,
        'target_username' => (string) $user['username'],
        'entity_type' => 'users',
        'entity_id' => $user_id,
        'entity_label' => (string) $user['username'],
        'details' => $two_factor_verified ? 'Two-factor authentication verified' : 'Password authentication verified',
    ]);

    session_regenerate_id(true);
    unset($_SESSION['_pending_auth'], $_SESSION['_two_factor_enrollment']);

    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = (string) $user['username'];
    $_SESSION['role'] = (string) $user['role'];
    $_SESSION['auth_version'] = (int) $user['auth_version'];
    $_SESSION['auth_complete'] = true;
    $_SESSION['two_factor_verified_at'] = $two_factor_verified ? time() : null;
    $_SESSION['must_change_password'] = !empty($user['must_change_password']);
    $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
}

function authenticationDestination(array $user) {
    if (!empty($user['must_change_password'])) {
        return 'two_factor_settings.php?password_reset_required=1';
    }

    return 'dashboard.php';
}

function twoFactorRecoveryCodesDestination($initial_login = false) {
    return $initial_login ? 'dashboard.php' : 'two_factor_settings.php';
}

function hasRecentTwoFactorVerification($maximum_age_seconds = 300) {
    $verified_at = $_SESSION['two_factor_verified_at'] ?? null;
    return is_int($verified_at) && (time() - $verified_at) <= $maximum_age_seconds;
}

// Check if the logged-in user has a specific role
function checkRole($role) {
    return (isset($_SESSION['role']) && $_SESSION['role'] === $role);
}

// Attach the authenticated user to database-trigger audit entries for this
// connection. MySQL user variables are scoped to one connection and never
// contain credentials or authentication factors.
function setDatabaseAuditContext(mysqli $conn, $user_id = null, $username = null) {
    $actor_user_id = $user_id === null ? null : (int) $user_id;
    $actor_username = $username === null ? null : substr((string) $username, 0, 50);
    $ip_address = requestIpAddress();
    $stmt = $conn->prepare(
        'SET @dnr_actor_user_id = ?, @dnr_actor_username = ?, @dnr_request_ip = ?'
    );

    if (!$stmt) {
        applicationLog('error', 'Unable to prepare the database audit context', ['error' => $conn->error]);
        return false;
    }

    $stmt->bind_param('iss', $actor_user_id, $actor_username, $ip_address);
    $success = $stmt->execute();
    if (!$success) {
        applicationLog('error', 'Unable to set the database audit context', ['error' => $stmt->error]);
    }
    $stmt->close();

    return $success;
}

function requestIpAddress($server = null) {
    $server = is_array($server) ? $server : $_SERVER;
    return ClientAddress::resolve(
        $server,
        trustedProxyNetworks(),
        trustedCloudflareProxyNetworks()
    );
}

function trustedProxyNetworks() {
    $configured_proxies = getenv('DNR_TRUSTED_PROXY_IPS');
    return is_string($configured_proxies) && trim($configured_proxies) !== ''
        ? $configured_proxies
        : '192.168.65.1';
}

function trustedCloudflareProxyNetworks() {
    $configured_proxies = getenv('DNR_TRUSTED_CLOUDFLARE_PROXY_IPS');
    return is_string($configured_proxies) && trim($configured_proxies) !== ''
        ? $configured_proxies
        : '172.18.0.0/24';
}

function isTrustedProxyAddress($ip_address) {
    return isAddressInTrustedNetworks($ip_address, trustedProxyNetworks());
}

function isTrustedCloudflareProxyAddress($ip_address) {
    return isAddressInTrustedNetworks($ip_address, trustedCloudflareProxyNetworks());
}

function dockerGatewayAddress($route_contents = null) {
    if ($route_contents !== null && !is_string($route_contents)) {
        return null;
    }

    return ClientAddress::dockerGatewayAddress($route_contents);
}

function isAddressInTrustedNetworks($ip_address, $configured_networks, $docker_gateway = null) {
    return ClientAddress::isAddressInTrustedNetworks(
        (string) $ip_address,
        (string) $configured_networks,
        is_string($docker_gateway) ? $docker_gateway : null
    );
}

function ipAddressMatchesNetwork($ip_address, $trusted_network) {
    return ClientAddress::isAddressInTrustedNetworks(
        (string) $ip_address,
        (string) $trusted_network
    );
}

function auditLogSchemaAvailable(mysqli $conn) {
    return true;
}

function requireAuditLogSchema(mysqli $conn) {
    // Schema readiness is checked by the container health check. Runtime
    // requests avoid information_schema queries and surface query failures in
    // the route that needs the unavailable table.
}

function auditUsernameForId(mysqli $conn, $user_id) {
    if ($user_id === null || (int) $user_id < 1) {
        return null;
    }

    if ((int) ($_SESSION['user_id'] ?? 0) === (int) $user_id
        && isset($_SESSION['username'])
    ) {
        return substr((string) $_SESSION['username'], 0, 50);
    }

    $user_id = (int) $user_id;
    $stmt = $conn->prepare('SELECT username FROM users WHERE id = ?');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $user_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $user = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $user ? substr((string) $user['username'], 0, 50) : null;
}

// Record semantic events (such as successful logins and security actions).
// Row-level database changes are recorded by triggers created by the audit
// migration. Audit failures are logged without blocking authentication on a
// deployment where application files precede the database migration.
function recordAuditEvent(mysqli $conn, array $event) {
    $event_category = substr((string) ($event['event_category'] ?? 'security'), 0, 30);
    $event_type = substr((string) ($event['event_type'] ?? ''), 0, 50);
    if ($event_type === '') {
        return false;
    }

    $actor_user_id = isset($event['actor_user_id']) ? (int) $event['actor_user_id'] : null;
    $target_user_id = isset($event['target_user_id']) ? (int) $event['target_user_id'] : null;
    $actor_username = array_key_exists('actor_username', $event)
        ? $event['actor_username']
        : auditUsernameForId($conn, $actor_user_id);
    $target_username = array_key_exists('target_username', $event)
        ? $event['target_username']
        : auditUsernameForId($conn, $target_user_id);
    $actor_username = $actor_username === null ? null : substr((string) $actor_username, 0, 50);
    $target_username = $target_username === null ? null : substr((string) $target_username, 0, 50);
    $entity_type = isset($event['entity_type'])
        ? substr((string) $event['entity_type'], 0, 50)
        : null;
    $entity_id = isset($event['entity_id']) ? (int) $event['entity_id'] : null;
    $entity_label = isset($event['entity_label'])
        ? substr((string) $event['entity_label'], 0, 255)
        : null;
    $details = isset($event['details'])
        ? substr((string) $event['details'], 0, 255)
        : null;
    $ip_address = requestIpAddress();

    $stmt = $conn->prepare(
        'INSERT INTO security_audit_log (
            actor_user_id, actor_username, target_user_id, target_username,
            event_category, event_type, entity_type, entity_id, entity_label,
            details, ip_address
         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
    );
    if (!$stmt) {
        applicationLog('error', 'Unable to prepare an audit log event', ['error' => $conn->error]);
        return false;
    }

    $stmt->bind_param(
        'isissssisss',
        $actor_user_id,
        $actor_username,
        $target_user_id,
        $target_username,
        $event_category,
        $event_type,
        $entity_type,
        $entity_id,
        $entity_label,
        $details,
        $ip_address
    );
    $success = $stmt->execute();
    if (!$success) {
        applicationLog('error', 'Unable to record an audit log event', ['error' => $stmt->error]);
    }
    $stmt->close();

    return $success;
}

function failedLoginAuditEvent($attempted_username, $details, $user = null) {
    $known_user = is_array($user) && !empty($user['id']) && isset($user['username']);
    $username = $known_user
        ? trim((string) $user['username'])
        : trim((string) $attempted_username);

    return [
        'event_category' => 'login',
        'event_type' => 'failed_login',
        'actor_user_id' => null,
        'actor_username' => null,
        'target_user_id' => $known_user ? (int) $user['id'] : null,
        'target_username' => $username === '' ? null : $username,
        'entity_type' => 'users',
        'entity_id' => $known_user ? (int) $user['id'] : null,
        'entity_label' => $username === '' ? '(blank username)' : $username,
        'details' => (string) $details,
    ];
}

function recordFailedLoginAttempt(mysqli $conn, $attempted_username, $details, $user = null) {
    return recordAuditEvent(
        $conn,
        failedLoginAuditEvent($attempted_username, $details, $user)
    );
}

function authenticationRateLimitSettings($scope) {
    $settings = [
        'login_ip' => ['limit' => 8, 'window' => 300, 'block' => 900],
        // Correct credentials bypass account throttles. The account scope only
        // suppresses further failures, so an attacker cannot lock out a user.
        'login_account' => ['limit' => 30, 'window' => 900, 'block' => 300],
        'recovery_ip' => ['limit' => 8, 'window' => 600, 'block' => 1800],
        'recovery_account' => ['limit' => 15, 'window' => 900, 'block' => 900],
    ];
    if (!isset($settings[$scope])) {
        throw new InvalidArgumentException('Unknown authentication rate-limit scope.');
    }
    return $settings[$scope];
}

function loginRateLimitSettings($scope) {
    $legacy_scopes = ['ip' => 'login_ip', 'account' => 'login_account'];
    return authenticationRateLimitSettings($legacy_scopes[$scope] ?? $scope);
}

function authenticationRateLimitKey($scope, $identifier) {
    return hash('sha256', (string) $scope . "\0" . (string) $identifier);
}

function authenticationRateLimitAccountIdentifier($username) {
    return strtolower(substr(trim((string) $username), 0, 255));
}

function requireLoginRateLimitSchema(mysqli $conn) {
    // Schema readiness is checked outside the request path.
}

function authenticationRateLimitIsBlocked(mysqli $conn, $scope, $identifier) {
    $stmt = $conn->prepare(
        'SELECT blocked_until IS NOT NULL AND blocked_until > UTC_TIMESTAMP() AS is_blocked
         FROM authentication_rate_limits
         WHERE key_hash = UNHEX(?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to check authentication availability.');
    }
    $key = authenticationRateLimitKey($scope, $identifier);
    $stmt->bind_param('s', $key);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to check authentication availability.');
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row && !empty($row['is_blocked']);
}

function authenticationSourceIsBlocked(mysqli $conn, $purpose) {
    $scope = $purpose . '_ip';
    return authenticationRateLimitIsBlocked(
        $conn,
        $scope,
        requestIpAddress() ?: 'unknown'
    );
}

function recordAuthenticationRateLimitFailure(mysqli $conn, $purpose, $username) {
    $identifiers = [
        $purpose . '_ip' => requestIpAddress() ?: 'unknown',
    ];
    $account_identifier = authenticationRateLimitAccountIdentifier($username);
    if ($account_identifier !== '') {
        $identifiers[$purpose . '_account'] = $account_identifier;
    }

    $states = [];
    foreach ($identifiers as $scope => $identifier) {
        $settings = authenticationRateLimitSettings($scope);
        $window = (int) $settings['window'];
        $limit = (int) $settings['limit'];
        $block = (int) $settings['block'];
        $key = authenticationRateLimitKey($scope, $identifier);
        $sql = "INSERT INTO authentication_rate_limits
                    (key_hash, scope, attempts, window_started_at, blocked_until, updated_at)
                VALUES (UNHEX(?), ?, 1, UTC_TIMESTAMP(), NULL, UTC_TIMESTAMP())
                ON DUPLICATE KEY UPDATE
                    blocked_until = CASE
                        WHEN window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$window} SECOND) THEN NULL
                        WHEN attempts + 1 >= {$limit} THEN DATE_ADD(UTC_TIMESTAMP(), INTERVAL {$block} SECOND)
                        ELSE blocked_until
                    END,
                    attempts = CASE
                        WHEN window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$window} SECOND) THEN 1
                        ELSE attempts + 1
                    END,
                    window_started_at = CASE
                        WHEN window_started_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL {$window} SECOND) THEN UTC_TIMESTAMP()
                        ELSE window_started_at
                    END,
                    updated_at = UTC_TIMESTAMP()";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            throw new RuntimeException('Unable to record authentication availability.');
        }
        $stmt->bind_param('ss', $key, $scope);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to record authentication availability.');
        }
        $stmt->close();

        $status_stmt = $conn->prepare(
            'SELECT attempts, blocked_until IS NOT NULL AND blocked_until > UTC_TIMESTAMP() AS is_blocked
             FROM authentication_rate_limits WHERE key_hash = UNHEX(?)'
        );
        $status_stmt->bind_param('s', $key);
        $status_stmt->execute();
        $status = $status_stmt->get_result()->fetch_assoc();
        $status_stmt->close();
        $states[$scope] = [
            'attempts' => (int) ($status['attempts'] ?? 0),
            'blocked' => !empty($status['is_blocked']),
            'just_blocked' => !empty($status['is_blocked'])
                && (int) ($status['attempts'] ?? 0) === $limit,
        ];
    }

    return $states;
}

function clearAuthenticationRateLimits(mysqli $conn, $purpose, $username) {
    $keys = [authenticationRateLimitKey($purpose . '_ip', requestIpAddress() ?: 'unknown')];
    $account_identifier = authenticationRateLimitAccountIdentifier($username);
    if ($account_identifier !== '') {
        $keys[] = authenticationRateLimitKey($purpose . '_account', $account_identifier);
    }
    $stmt = $conn->prepare('DELETE FROM authentication_rate_limits WHERE key_hash = UNHEX(?)');
    if (!$stmt) {
        return false;
    }
    $success = true;
    foreach ($keys as $key) {
        $stmt->bind_param('s', $key);
        $success = $stmt->execute() && $success;
    }
    $stmt->close();

    // Successful logins provide a low-cost opportunity to bound stale state.
    // Keep this best-effort so cleanup can never prevent authentication.
    try {
        $conn->query(
            'DELETE FROM authentication_rate_limits
             WHERE updated_at < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)'
        );
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to prune stale login rate-limit records', ['error' => $exception->getMessage()]);
    }

    return $success;
}

function loginRateLimitIsBlocked(mysqli $conn) {
    return authenticationSourceIsBlocked($conn, 'login');
}

function recordLoginRateLimitFailure(mysqli $conn, $username = '') {
    $states = recordAuthenticationRateLimitFailure($conn, 'login', $username);
    $ip_state = $states['login_ip'] ?? ['attempts' => 0, 'blocked' => false, 'just_blocked' => false];
    $account_blocked = !empty($states['login_account']['blocked']);
    return [
        'blocked' => !empty($ip_state['blocked']) || $account_blocked,
        'should_audit' => $ip_state['attempts'] <= 3 || !empty($ip_state['just_blocked']),
    ];
}

function clearLoginRateLimitForCurrentIp(mysqli $conn, $username = '') {
    return clearAuthenticationRateLimits($conn, 'login', $username);
}

// Check if the logged-in user has any of the specified roles
function hasRole($roles = []) {
    return (isset($_SESSION['role']) && in_array($_SESSION['role'], $roles, true));
}

function canArchiveEntries($role) {
    return in_array($role, ['admin', 'editor'], true);
}

function canDeleteEntries($role) {
    return $role === 'admin';
}

// Generate one CSRF token per session for state-changing requests.
function generateCsrfToken() {
    if (empty($_SESSION['_csrf_token'])) {
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_token'];
}

// Render the hidden token field used by POST forms.
function csrfInput() {
    return '<input type="hidden" name="csrf_token" value="' .
        htmlspecialchars(generateCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

// Validate a submitted CSRF token without leaking the session token.
function validateCsrfToken($token) {
    return is_string($token)
        && isset($_SESSION['_csrf_token'])
        && hash_equals($_SESSION['_csrf_token'], $token);
}

// Stop a state-changing request before it reaches the database.
function requireValidCsrfToken() {
    if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
        http_response_code(400);
        exit('Invalid or expired request token. Please reload the page and try again.');
    }
}

// Keep archive operations consistent across each of the record lists. The
// existing is_deleted columns are the reversible archive flag; permanent
// deletion is handled separately below.
function archiveEntity(mysqli $conn, $entity, $id) {
    return setEntityArchived($conn, $entity, $id, true);
}

function restoreEntity(mysqli $conn, $entity, $id) {
    return setEntityArchived($conn, $entity, $id, false);
}

function organizationActiveDependencyCounts(mysqli $conn, $organization_id) {
    try {
        return ArchiveService::organizationActiveDependencyCounts(
            $conn,
            (int) $organization_id
        );
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to count active organization dependencies', [
            'organization_id' => (int) $organization_id,
            'error' => $exception->getMessage(),
        ]);
        return null;
    }
}

function organizationArchiveDependencyMessage(array $dependencies) {
    $parts = [];
    $contacts = max(0, (int) ($dependencies['contacts'] ?? 0));
    $engagements = max(0, (int) ($dependencies['engagements'] ?? 0));

    if ($contacts > 0) {
        $parts[] = $contacts . ' active contact' . ($contacts === 1 ? '' : 's');
    }
    if ($engagements > 0) {
        $parts[] = $engagements . ' active engagement' . ($engagements === 1 ? '' : 's');
    }

    if (!$parts) {
        return '';
    }

    $dependency_summary = count($parts) === 2
        ? $parts[0] . ' and ' . $parts[1]
        : $parts[0];

    return 'This organization cannot be archived while it has ' . $dependency_summary
        . '. Archive those related records first, or move them to another organization.';
}

function setEntityArchived(mysqli $conn, $entity, $id, $is_archived) {
    if (!canArchiveEntries($_SESSION['role'] ?? null)) {
        return false;
    }

    $id = (int) $id;
    $archive_value = $is_archived ? 1 : 0;
    try {
        return ArchiveService::setArchived($conn, (string) $entity, $id, (bool) $is_archived);
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to change archive state', [
            'entity' => $entity,
            'entity_id' => $id,
            'is_archived' => $archive_value,
            'error' => $exception->getMessage(),
        ]);
        return false;
    }
}

// Permanently remove an entity. Organization deletion explicitly removes its
// dependent records so the operation behaves consistently on existing
// databases whose foreign keys do not use ON DELETE CASCADE.
function permanentlyDeleteEntity(mysqli $conn, $entity, $id) {
    if (!canDeleteEntries($_SESSION['role'] ?? null)) {
        return false;
    }

    $id = (int) $id;
    if ($id < 1) {
        return false;
    }

    if ($entity === 'organization') {
        return permanentlyDeleteOrganization($conn, $id);
    }

    if ($entity === 'engagement' || $entity === 'event') {
        return permanentlyDeleteEngagement($conn, $id);
    }

    if ($entity !== 'contact') {
        return false;
    }

    $stmt = $conn->prepare('DELETE FROM contacts WHERE id = ?');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $id);
    $success = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();

    return $success;
}

function permanentlyDeleteEngagement(mysqli $conn, $engagement_id) {
    if (!$conn->begin_transaction()) {
        return false;
    }

    $chron_stmt = $conn->prepare(
        'DELETE FROM engagement_chron_entries WHERE engagement_id = ?'
    );
    $presentation_stmt = $conn->prepare(
        'DELETE FROM presentations WHERE engagement_id = ?'
    );
    $engagement_stmt = $conn->prepare('DELETE FROM engagements WHERE id = ?');
    if (!$chron_stmt || !$presentation_stmt || !$engagement_stmt) {
        if ($chron_stmt) {
            $chron_stmt->close();
        }
        if ($presentation_stmt) {
            $presentation_stmt->close();
        }
        if ($engagement_stmt) {
            $engagement_stmt->close();
        }
        $conn->rollback();
        return false;
    }

    $chron_stmt->bind_param('i', $engagement_id);
    $chron_entries_deleted = $chron_stmt->execute();
    $chron_stmt->close();

    $presentation_stmt->bind_param('i', $engagement_id);
    $presentations_deleted = $presentation_stmt->execute();
    $presentation_stmt->close();

    $engagement_stmt->bind_param('i', $engagement_id);
    $engagement_deleted = $engagement_stmt->execute()
        && $engagement_stmt->affected_rows === 1;
    $engagement_stmt->close();

    if (!$chron_entries_deleted || !$presentations_deleted || !$engagement_deleted || !$conn->commit()) {
        $conn->rollback();
        return false;
    }

    return true;
}

function permanentlyDeleteOrganization(mysqli $conn, $organization_id) {
    if (!$conn->begin_transaction()) {
        return false;
    }

    $queries = [
        'DELETE ce FROM engagement_chron_entries ce
         INNER JOIN engagements e ON e.id = ce.engagement_id
         WHERE e.organization_id = ?',
        'DELETE p FROM presentations p
         INNER JOIN engagements e ON e.id = p.engagement_id
         WHERE e.organization_id = ?',
        'DELETE FROM engagements WHERE organization_id = ?',
        'DELETE FROM contacts WHERE organization_id = ?',
        'DELETE FROM organizations WHERE id = ?',
    ];

    foreach ($queries as $index => $query) {
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            $conn->rollback();
            return false;
        }

        $stmt->bind_param('i', $organization_id);
        $success = $stmt->execute();
        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        $is_organization_delete = $index === count($queries) - 1;
        if (!$success || ($is_organization_delete && $affected_rows !== 1)) {
            $conn->rollback();
            return false;
        }
    }

    if (!$conn->commit()) {
        $conn->rollback();
        return false;
    }

    return true;
}

function actionIconSvg($action)
{
    static $icons = [
        'view' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6-9.5-6-9.5-6Z"/><circle cx="12" cy="12" r="2.5"/></svg>',
        'edit' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L8 18l-4 1 1-4Z"/></svg>',
        'start' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m10 8 6 4-6 4Z"/></svg>',
        'complete' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/></svg>',
        'archive' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="4" rx="1"/><path d="M5 8v12h14V8M9 12h6"/></svg>',
        'restore' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/></svg>',
        'delete' => '<svg class="action-icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6M10 10v6M14 10v6"/></svg>',
    ];

    return $icons[$action] ?? '';
}
?>
