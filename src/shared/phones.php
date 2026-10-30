<?php

declare(strict_types=1);

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

function normalizePhoneNumber($country_code, $national_number, $label = 'Phone Number') {
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

function phoneCountryPicker($field_name, $selected_code = null, $aria_label = 'Phone Country Code', bool $native = false) {
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
    if ($native) {
        $html = '<select name="' . $escape($field_name) . '" aria-label="' . $escape($aria_label) . '" data-phone-country-code>';
        foreach ($choices as $choice) {
            $html .= '<option value="' . $escape($choice['code']) . '"' . ($choice['code'] === $selected_code ? ' selected' : '') . '>'
                . $escape($choice['flag'] . ' ' . $choice['country'] . ' ' . $choice['code']) . '</option>';
        }
        return $html . '</select>';
    }
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
        $html .= '<button type="button" class="phone-country-option" role="option" tabindex="-1" aria-selected="'
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
