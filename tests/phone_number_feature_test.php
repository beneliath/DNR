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
    expectPhoneFeature(
        str_contains($source, 'phoneCountryPicker(')
            && str_contains($source, 'data-phone-number')
            && str_contains($source, 'normalizePhoneNumber('),
        "{$page} should provide country-code entry and server-side normalization."
    );
}

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
        && str_contains($phone_script, 'Enter a 10-digit telephone number.'),
    'the shared client behavior should select countries, format on blur and save, and validate local digits.'
);

$header = $read('src/templates/header.php');
expectPhoneFeature(
    str_contains($header, 'assets/js/phone-input.min.js?v=0.2.0')
        && str_contains($header, 'assets/css/modern.min.css?v=0.1.59'),
    'the application shell should load the country picker behavior and current production styles.'
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
expectPhoneFeature(
    str_contains($migration, 'UPDATE organizations')
        && str_contains($migration, 'UPDATE contacts')
        && str_contains($migration, "'+1 ('")
        && str_contains($migration, 'REGEXP_REPLACE'),
    'the migration should normalize legacy U.S. organization, fax, and contact values.'
);

echo "Phone number feature tests passed.\n";
