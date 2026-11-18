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
    str_contains($edit_engagement, '<section class="form-section engagement-location-section">')
        && str_contains($new_engagement, '<section class="form-section engagement-location-section">'),
    'New and Edit Engagement should each show Event Location in its own pane.'
);

expectSavedEngagementSurface(
    preg_match('/\.address-section\.is-saved-address-section\s*\{[^}]*background:\s*transparent\s*!important;/s', $modern_css) === 1,
    'embedded saved address panels should retain their transparent background.'
);

echo "Saved engagement surface tests passed.\n";
