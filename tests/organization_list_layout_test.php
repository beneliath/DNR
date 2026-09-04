<?php

declare(strict_types=1);

function expectOrganizationListLayout(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Organization list layout test failed: {$message}\n");
        exit(1);
    }
}

$source = file_get_contents(__DIR__ . '/../src/organizations.php');
$styles = file_get_contents(__DIR__ . '/../src/assets/css/pages/organizations.css');

expectOrganizationListLayout(
    str_contains($source, 'class="organizations-body"')
        && str_contains($source, 'class="container organizations-page"')
        && str_contains($source, 'class="page-heading organizations-heading"'),
    'The Organizations list should expose page-specific layout hooks.'
);

expectOrganizationListLayout(
    preg_match('/\.organizations-page\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);[^}]*padding-inline:\s*var\(--app-content-padding\);/s', $styles) === 1
        && preg_match('/\.organizations-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $styles) === 1,
    'The Organizations list should use the Dashboard canvas width and heading scale.'
);

expectOrganizationListLayout(
    preg_match('/\.organizations-body \.app-footer\s*\{[^}]*max-width:\s*var\(--app-content-max\);[^}]*padding-inline:\s*var\(--app-content-padding\);/s', $styles) === 1,
    'The Organizations footer should align with the Dashboard-width page canvas.'
);

echo "Organization list layout tests passed.\n";
