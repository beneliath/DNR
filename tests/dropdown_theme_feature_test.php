<?php

function expectDropdownThemeFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Dropdown theme feature test failed: {$message}\n");
        exit(1);
    }
}

$styles = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');

expectDropdownThemeFeature(
    preg_match('/html body select\s*\{[^}]*color-scheme:\s*light;/s', $styles) === 1
        && preg_match('/html\.dark-mode body select\s*\{[^}]*color-scheme:\s*dark;/s', $styles) === 1,
    'native dropdown windows should receive the active light or dark browser color scheme.'
);

expectDropdownThemeFeature(
    preg_match(
        '/html body select option,\s*html body select optgroup\s*\{[^}]*background-color:\s*var\(--surface\)\s*!important;[^}]*color:\s*var\(--text\)\s*!important;/s',
        $styles
    ) === 1,
    'native dropdown options and groups should use shared theme surface and text colors.'
);

foreach (['address-country', 'address-region', 'phone-country'] as $menu) {
    expectDropdownThemeFeature(
        preg_match(
            '/html body \.' . preg_quote($menu, '/') . '-menu\s*\{[^}]*background:\s*var\(--surface\)\s*!important;/s',
            $styles
        ) === 1
            && preg_match(
                '/html body \.' . preg_quote($menu, '/') . '-option\s*\{[^}]*color:\s*var\(--text\)\s*!important;/s',
                $styles
            ) === 1,
        "{$menu} custom dropdowns should use the same theme surface and text colors."
    );
}

echo "Dropdown theme feature tests passed.\n";
