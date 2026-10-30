<?php

declare(strict_types=1);

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
    $required = false,
    $native_fallback = false
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
    $html .= '<select class="address-country-native-select" id="' . $escape($id_token . '-country') . '" name="' . $escape($field_name) . '"'
        . ($required ? ' required' : '') . ($native_fallback ? '' : ' tabindex="-1" aria-hidden="true"') . ' data-address-country="'
        . $escape($address_prefix) . '" data-address-country-native>';
    $html .= addressCountrySelectOptions($selected_code);
    $html .= '</select>';
    $html .= '<button type="button" class="address-country-trigger"' . ($native_fallback ? ' hidden' : '') . ' aria-haspopup="listbox" aria-expanded="false" aria-controls="'
        . $escape($menu_id) . '" aria-label="' . $escape('Country: ' . $selected_choice['name'] . ' (' . $selected_code . ')')
        . '" data-address-country-toggle>';
    $html .= '<span class="address-country-flag" aria-hidden="true" data-address-country-flag>'
        . $escape($selected_choice['flag']) . '</span>';
    $html .= '<span class="address-country-label" data-address-country-label>'
        . $escape($selected_choice['name']) . '</span>';
    $html .= '<span class="address-country-chevron" aria-hidden="true"></span></button>';
    $html .= '<div class="address-country-menu" id="' . $escape($menu_id)
        . '" role="listbox" aria-label="Country Options" hidden data-address-country-menu>';
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
