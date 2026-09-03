<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/presentation_helpers.php';

function expectPresentationAssetFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Presentation asset feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/migrations/20260825_add_presentation_assets.sql');
$manifest = file_get_contents($root . '/migrations/order.txt');
$asset_helper = file_get_contents($root . '/src/presentation_asset_helpers.php');
$asset_route = file_get_contents($root . '/src/presentation_asset.php');
$presentation_helper = file_get_contents($root . '/src/presentation_helpers.php');
$template = file_get_contents($root . '/src/templates/presentation_form.php');
$new_engagement = file_get_contents($root . '/src/index.php');
$edit_engagement = file_get_contents($root . '/src/edit_engagement.php');
$view_engagement = file_get_contents($root . '/src/view_engagement.php');
$presentation_script = file_get_contents($root . '/src/assets/js/presentation-form.js');
$page_actions = file_get_contents($root . '/src/assets/js/page-actions.js');
$modern_css = file_get_contents($root . '/src/assets/css/modern.css');
$view_engagement_css = file_get_contents($root . '/src/assets/css/pages/view_engagement.css');
$production_ini = file_get_contents($root . '/docker/php-production.ini');
$development_ini = file_get_contents($root . '/docker/php-development.ini');
$compose = file_get_contents($root . '/docker-compose.yaml');

expectPresentationAssetFeature(
    str_contains($migration, 'slide_deck_pdf LONGBLOB')
        && str_contains($migration, 'speaker_notes_qr_image MEDIUMBLOB')
        && str_contains($migration, 'speaker_website_qr_image MEDIUMBLOB')
        && str_contains($migration, 'speaker_donation_qr_image MEDIUMBLOB')
        && str_contains($manifest, '20260825_add_presentation_assets.sql'),
    'the ordered migration should store the PDF and each QR image on presentations.'
);

expectPresentationAssetFeature(
    str_contains($new_engagement, 'enctype="multipart/form-data"')
        && str_contains($edit_engagement, 'enctype="multipart/form-data"')
        && str_contains($new_engagement, 'attachPresentationAssetChanges')
        && str_contains($edit_engagement, 'attachPresentationAssetChanges'),
    'new and edit engagement submissions should accept and validate presentation uploads.'
);

expectPresentationAssetFeature(
    str_contains($template, '[slide_deck]')
        && str_contains($template, "'speaker_notes_qr' =>")
        && str_contains($template, "'speaker_website_qr' =>")
        && str_contains($template, "'speaker_donation_qr' =>")
        && str_contains($template, 'data-paste-qr')
        && !str_contains($template, 'data-qr-paste-zone')
        && str_contains($template, 'Download <?php echo htmlspecialchars($slide_filename')
        && !str_contains($template, 'target="_blank"')
        && str_contains($template, 'data-copy-qr-url'),
    'the shared presentation form should expose PDF and direct QR paste controls without a separate paste zone.'
);

expectPresentationAssetFeature(
    str_contains($asset_route, 'startSecureSession();')
        && str_contains($asset_route, 'requireLogin();')
        && str_contains($asset_route, "'Content-Disposition: attachment;")
        && str_contains($asset_route, 'Content-Security-Policy: sandbox')
        && str_contains($asset_route, "'X-Content-Type-Options: nosniff'")
        && str_contains($asset_route, 'presentationAssetDefinitionForQueryType'),
    'the asset route should be authenticated and allowlisted, with PDFs delivered as downloads.'
);

expectPresentationAssetFeature(
    str_contains($view_engagement, 'Download PDF slide deck')
        && !str_contains($view_engagement, 'target="_blank"')
        && str_contains($view_engagement, 'Speaker Notes')
        && str_contains($view_engagement, 'Speaker Website')
        && str_contains($view_engagement, 'Speaker Donations')
        && str_contains($view_engagement, 'data-copy-qr-url'),
    'engagement details should show the PDF in a new tab and all available QR codes.'
);

expectPresentationAssetFeature(
    preg_match('/\.presentation-view-pdf:hover,\s*\.presentation-view-pdf:focus-visible\s*\{[^}]*background:\s*var\(--control-hover-bg\);[^}]*color:\s*var\(--control-hover-fg\);[^}]*text-decoration:\s*none;[^}]*transform:\s*translateY\(-1px\);/s', $view_engagement_css) === 1
        && !preg_match('/\.presentation-view-pdf:hover,[^{]*\{[^}]*text-decoration:\s*underline;/s', $view_engagement_css),
    'the PDF slide-deck link should use the standard colored hover treatment without an underline.'
);

expectPresentationAssetFeature(
    str_contains($presentation_script, 'navigator.clipboard.read()')
        && str_contains($presentation_script, 'navigator.permissions.query({ name: "clipboard-read" })')
        && str_contains($presentation_script, 'event.clipboardData')
        && substr_count($presentation_script, 'document.addEventListener("paste",') === 1
        && str_contains($presentation_script, 'document.addEventListener("paste", pasteQrImage)')
        && !str_contains($presentation_script, 'data-qr-paste-zone')
        && str_contains($presentation_script, 'new DataTransfer()')
        && str_contains($presentation_script, 'URL.createObjectURL(input.files[0])')
        && str_contains($presentation_script, 'URL.revokeObjectURL(previewUrl)')
        && str_contains($presentation_script, 'if (!shouldReleaseQrPreviews(event)) return;')
        && !str_contains($presentation_script, 'readAsDataURL')
        && str_contains($page_actions, "new ClipboardItem({ 'image/png': png })")
        && str_contains($page_actions, "window.open(url, '_blank', 'noopener')"),
    'QR images should support paste input and image clipboard copy with a safe fallback.'
);

expectPresentationAssetFeature(
    str_contains($modern_css, 'html body button.presentation-qr-preview img')
        && str_contains($modern_css, 'width: 160px !important;')
        && str_contains($modern_css, 'height: 160px !important;')
        && str_contains($modern_css, 'align-self: center;')
        && str_contains($modern_css, 'html body .presentation-paste-button:hover')
        && str_contains($modern_css, 'background: var(--control-hover-bg) !important;')
        && str_contains($modern_css, 'overflow-wrap: anywhere;')
        && !str_contains($modern_css, '.presentation-paste-zone'),
    'QR previews should reserve centered layout space and paste buttons should use the project hover treatment.'
);

expectPresentationAssetFeature(
    str_contains($asset_helper, 'PRESENTATION_SLIDE_DECK_MAX_BYTES = 100 * 1024 * 1024')
        && str_contains($asset_helper, 'PDF slide decks must be 100 MB or smaller.')
        && str_contains($template, 'PDF slide decks may be up to 100 MB.')
        && str_contains($presentation_script, 'PDF slide decks may be up to 100 MB.')
        && str_contains($asset_helper, "!== 'application/pdf'")
        && str_contains($asset_helper, "preg_match('/\\A%PDF-")
        && str_contains($asset_helper, 'startxref\\s+\\d+\\s+%%EOF')
        && str_contains($production_ini, 'memory_limit=512M')
        && str_contains($production_ini, 'post_max_size=120M')
        && str_contains($production_ini, 'upload_max_filesize=100M')
        && str_contains($development_ini, 'post_max_size=120M')
        && str_contains($development_ini, 'upload_max_filesize=100M')
        && str_contains($compose, '/tmp:rw,noexec,nosuid,size=640m')
        && str_contains($compose, 'DNR_DATABASE_BACKUP_MAX_BYTES:-268435456')
        && str_contains($compose, '--max-allowed-packet=128M'),
    'PDF validation, request handling, temporary storage, and database transport should accommodate the documented 100 MB limit.'
);

$pdf_path = tempnam(sys_get_temp_dir(), 'dnr-presentation-pdf-');
if ($pdf_path === false) {
    throw new RuntimeException('Unable to create a temporary PDF fixture.');
}
file_put_contents(
    $pdf_path,
    "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\nxref\n0 2\n"
        . "0000000000 65535 f \n0000000009 00000 n \ntrailer\n<< /Root 1 0 R /Size 2 >>\n"
        . "startxref\n52\n%%EOF\n"
);
try {
    $pdf = presentationSlideDeckFromPath($pdf_path, '../../Unsafe Deck.PDF', false);
    expectPresentationAssetFeature(
        $pdf['mime_type'] === 'application/pdf'
            && $pdf['filename'] === 'Unsafe Deck.PDF'
            && $pdf['size'] > 0
            && strlen($pdf['sha256']) === 32,
        'a valid PDF should be normalized with a safe basename and integrity hash.'
    );
} finally {
    unlink($pdf_path);
}

$invalid_path = tempnam(sys_get_temp_dir(), 'dnr-presentation-invalid-');
if ($invalid_path === false) {
    throw new RuntimeException('Unable to create a temporary invalid upload fixture.');
}
file_put_contents($invalid_path, '<html>not a pdf</html>');
try {
    presentationSlideDeckFromPath($invalid_path, 'not-a-pdf.pdf', false);
    expectPresentationAssetFeature(false, 'a non-PDF upload should be rejected.');
} catch (InvalidArgumentException $exception) {
    expectPresentationAssetFeature(
        str_contains($exception->getMessage(), 'valid PDF'),
        'an invalid PDF should return a useful validation message.'
    );
} finally {
    unlink($invalid_path);
}

$truncated_pdf_path = tempnam(sys_get_temp_dir(), 'dnr-presentation-truncated-pdf-');
if ($truncated_pdf_path === false) {
    throw new RuntimeException('Unable to create a truncated PDF fixture.');
}
file_put_contents($truncated_pdf_path, "%PDF-1.7\n1 0 obj\n<<>>\nendobj\n");
try {
    presentationSlideDeckFromPath($truncated_pdf_path, 'truncated.pdf', false);
    expectPresentationAssetFeature(false, 'a PDF without a final cross-reference marker should be rejected.');
} catch (InvalidArgumentException $exception) {
    expectPresentationAssetFeature(
        str_contains($exception->getMessage(), 'valid PDF'),
        'a truncated PDF should return a useful validation message.'
    );
} finally {
    unlink($truncated_pdf_path);
}

try {
    normalizeEngagementPresentations(
        [7 => ['topic_title' => '', 'speaker_name' => 'Default Speaker']],
        '2026-08-20',
        '2026-08-22',
        'Default Speaker',
        false,
        ['7' => true]
    );
    expectPresentationAssetFeature(false, 'an upload-only blank presentation should require core fields.');
} catch (InvalidArgumentException $exception) {
    expectPresentationAssetFeature(
        str_contains($exception->getMessage(), 'topic/title'),
        'an upload-only presentation should explain its missing topic/title.'
    );
}

expectPresentationAssetFeature(
    str_contains($presentation_helper, 'applyPresentationAssetChanges')
        && str_contains($presentation_helper, "'asset_changes'")
        && str_contains($edit_engagement, 'mergeStoredPresentationAssetMetadata'),
    'asset replacements and removals should participate in transactional presentation synchronization.'
);

expectPresentationAssetFeature(
    presentationAssetByteRange('', 100) === null
        && presentationAssetByteRange('bytes=10-19', 100) === [
            'start' => 10, 'end' => 19, 'length' => 10,
        ]
        && presentationAssetByteRange('bytes=95-', 100) === [
            'start' => 95, 'end' => 99, 'length' => 5,
        ]
        && presentationAssetByteRange('bytes=-8', 100) === [
            'start' => 92, 'end' => 99, 'length' => 8,
        ],
    'asset delivery should parse bounded, open-ended, and suffix byte ranges.'
);
try {
    presentationAssetByteRange('bytes=100-101', 100);
    expectPresentationAssetFeature(false, 'an out-of-bounds byte range should be rejected.');
} catch (OutOfRangeException $exception) {
    expectPresentationAssetFeature(true, 'an out-of-bounds byte range should return 416.');
}
expectPresentationAssetFeature(
    str_contains($asset_route, 'asset_size')
        && str_contains($asset_route, 'HTTP_IF_NONE_MATCH')
        && str_contains($asset_route, 'HTTP_IF_RANGE')
        && str_contains($asset_route, 'hash_equals($etag, $if_range)')
        && strpos($asset_route, 'HTTP_IF_NONE_MATCH') < strpos($asset_route, '$data_sql')
        && str_contains($asset_route, "' = UNHEX(?) AND '")
        && str_contains($asset_route, 'Content-Range: bytes '),
    'asset delivery should validate stable metadata and 304 responses before reading BLOB bytes, with range support.'
);

echo "Presentation asset feature tests passed.\n";
