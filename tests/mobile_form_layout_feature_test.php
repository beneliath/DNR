<?php

function expectMobileFormLayoutFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Mobile form layout feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$modern_styles = file_get_contents($root . '/src/assets/css/modern.css');
$engagement_styles = file_get_contents($root . '/src/assets/css/pages/index.css');

expectMobileFormLayoutFeature(
    !str_contains($modern_styles, 'html.dark-mode body div:not(')
        && preg_match(
            '/html body \.address-region-menu\s*\{[^}]*background:\s*var\(--surface\)\s*!important;/s',
            $modern_styles
        ) === 1
        && preg_match(
            '/html body \.phone-country-menu\s*\{[^}]*background:\s*var\(--surface\)\s*!important;/s',
            $modern_styles
        ) === 1,
    'dark mode should preserve solid state and telephone dropdown menu surfaces.'
);

expectMobileFormLayoutFeature(
    str_contains($engagement_styles, 'width: 300px !important;')
        && preg_match(
            '/@media \(max-width: 760px\).*?form:not\(\.footer-logout-form\):not\(\.sidebar-logout-form\).*?input:not\(\[type="checkbox"\]\).*?width:\s*100%\s*!important;.*?min-width:\s*0\s*!important;.*?max-width:\s*100%\s*!important;/s',
            $modern_styles
        ) === 1,
    'shared mobile form styles should override later fixed-width page fields.'
);

expectMobileFormLayoutFeature(
    preg_match(
        '/@media \(max-width: 760px\).*?form:not\(\.footer-logout-form\):not\(\.sidebar-logout-form\) \.form-field\s*\{[^}]*width:\s*100%\s*!important;[^}]*align-items:\s*stretch\s*!important;[^}]*flex-direction:\s*column\s*!important;/s',
        $modern_styles
    ) === 1,
    'mobile labels and their controls should stack instead of combining into an over-wide row.'
);

echo "Mobile form layout feature tests passed.\n";
