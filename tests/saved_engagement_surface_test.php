<?php

declare(strict_types=1);

function expectSavedEngagementSurface(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Saved engagement surface test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$edit_engagement = file_get_contents($root . '/src/edit_engagement.php');
$new_engagement = file_get_contents($root . '/src/index.php');
$modern_css = file_get_contents($root . '/src/assets/css/modern.css');

expectSavedEngagementSurface(
    str_contains($edit_engagement, 'class="address-section is-saved-address-section"')
        && !str_contains($new_engagement, 'class="address-section is-saved-address-section"'),
    'only the saved engagement form should mark Event Location as a saved surface.'
);

expectSavedEngagementSurface(
    preg_match('/\.address-section\.is-saved-address-section\s*\{[^}]*background:\s*transparent\s*!important;/s', $modern_css) === 1,
    'saved Event Location panels should have a transparent background.'
);

echo "Saved engagement surface tests passed.\n";
