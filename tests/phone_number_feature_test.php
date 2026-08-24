<?php

function expectPhoneFeature($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Phone number feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static fn($path) => file_get_contents($root . '/' . $path);

$entry_pages = [
    'src/add_organization.php',
    'src/edit_organization.php',
    'src/add_contact.php',
    'src/edit_contact.php',
];
foreach ($entry_pages as $page) {
    $source = $read($page);
    $uses_shared_normalizer = str_contains($source, 'OrganizationInput::normalize')
        || str_contains($source, 'ContactInput::normalize');
    expectPhoneFeature(
        $uses_shared_normalizer,
        "{$page} should use shared server-side input normalization."
    );
}
expectPhoneFeature(
    str_contains($read('src/app/Domain/OrganizationInput.php'), 'normalizePhoneNumber(')
        && str_contains($read('src/app/Domain/ContactInput.php'), 'normalizePhoneNumber(')
        && str_contains($read('src/add_organization.php'), 'phoneCountryPicker(')
        && str_contains($read('src/add_contact.php'), 'data-phone-number'),
    'shared normalizers and entry forms should enforce country-aware telephone input.'
);

$display_pages = [
    'src/view_organization.php',
    'src/view_contact.php',
    'src/view_engagement.php',
    'src/engagement_export_helpers.php',
];
foreach ($display_pages as $page) {
    expectPhoneFeature(
        str_contains($read($page), 'formatPhoneNumberForDisplay('),
        "{$page} should explicitly format displayed telephone numbers."
    );
}

$phone_script = $read('src/assets/js/phone-input.js');
expectPhoneFeature(
    str_contains($phone_script, "document.addEventListener('focusout'")
        && str_contains($phone_script, "document.addEventListener('submit'")
        && str_contains($phone_script, '[data-phone-country-option]')
        && str_contains($phone_script, 'totalDigits > 15')
        && str_contains($phone_script, 'valid telephone number for the selected country'),
    'the shared client behavior should select countries, preserve international formats, and validate E.164 length boundaries.'
);

$header = $read('src/templates/header.php');
expectPhoneFeature(
    str_contains($header, "renderScript('assets/js/phone-input.min.js'")
        && str_contains($read('src/functions.php'), 'assets/css/modern.min.css'),
    'the application shell should load the country picker and centralized production styles.'
);

$phone_styles = $read('src/assets/css/modern.css');
expectPhoneFeature(
    str_contains($phone_styles, 'width: fit-content !important;')
        && str_contains($phone_styles, 'gap: 10px;')
        && str_contains($phone_styles, 'width: 15rem !important;')
        && str_contains($phone_styles, 'background: var(--surface) !important;'),
    'the country selector and telephone field should be separate, compact, and theme-aware.'
);

$migration = $read('migrations/20260818_standardize_phone_numbers.sql');
$e164_migration = $read('migrations/20260822_normalize_phone_e164.sql');
expectPhoneFeature(
    str_contains($migration, 'UPDATE organizations')
        && str_contains($migration, 'UPDATE contacts')
        && str_contains($migration, "'+1 ('")
        && str_contains($migration, 'REGEXP_REPLACE')
        && str_contains($e164_migration, "CONCAT('+', REGEXP_REPLACE")
        && str_contains($e164_migration, 'UPDATE users'),
    'tracked migrations should normalize legacy U.S. values and canonicalize stored telephone numbers as E.164.'
);

echo "Phone number feature tests passed.\n";
