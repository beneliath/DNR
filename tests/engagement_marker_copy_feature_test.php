<?php

declare(strict_types=1);

function expectEngagementMarkerCopy(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Engagement marker copy feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$page = file_get_contents($root . '/src/view_engagement.php');
$actions = file_get_contents($root . '/src/assets/js/page-actions.js');
$styles = file_get_contents($root . '/src/assets/css/pages/view_engagement.css');

expectEngagementMarkerCopy(
    str_contains($page, 'class="action-icon-button engagement-marker-copy"')
        && str_contains($page, '$engagement_marker = applicationInboundMarker($engagement_id)')
        && str_contains($page, 'data-copy-text="<?php echo htmlspecialchars($engagement_marker')
        && str_contains($page, 'aria-label="Copy email subject marker"')
        && str_contains($page, 'id="engagement-marker-copy-status"')
        && str_contains($page, 'engagement-marker-copy-icon')
        && str_contains($page, 'engagement-marker-copied-icon'),
    'the subject marker should have an adjacent, accessible copy icon with live feedback.'
);

expectEngagementMarkerCopy(
    str_contains($actions, "document.querySelectorAll('[data-copy-text]')")
        && str_contains($actions, "await copyText(button.dataset.copyText || '')")
        && str_contains($actions, "button.classList.add('is-copied')")
        && str_contains($actions, "button.classList.add('is-copy-failed')")
        && str_contains($actions, 'navigator.clipboard.writeText(value)')
        && str_contains($actions, "document.execCommand('copy')"),
    'copy controls should use the Clipboard API with a legacy fallback and clear success or failure states.'
);

expectEngagementMarkerCopy(
    str_contains($styles, '.engagement-email-marker-control {')
        && str_contains($styles, 'white-space: nowrap;')
        && str_contains($styles, 'html body .engagement-marker-copy.is-copied')
        && str_contains($styles, '.engagement-marker-copy.is-copied .engagement-marker-copied-icon'),
    'the marker and copy icon should stay together and expose a visual copied state.'
);

echo "Engagement marker copy feature tests passed.\n";
