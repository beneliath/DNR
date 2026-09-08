<?php

declare(strict_types=1);

putenv('DNR_TIMEZONE=America/Chicago');
require_once __DIR__ . '/../src/presentation_helpers.php';

function expectUploadMetadata(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$stored = [
    'id' => 123,
    'has_speaker_notes' => true,
    'speaker_notes_filename' => 'notes.pdf',
    'speaker_notes_size' => 2097152,
    'speaker_notes_updated_at' => '2026-09-08 01:30:00.123456',
    'speaker_notes_uploaded_by_username' => 'Taylor <editor>',
];
$render = static function (array $presentation): string {
    $presentation_dom_id = 7;
    $is_saved_presentation = !empty($presentation['id']);
    ob_start();
    include __DIR__ . '/../src/templates/presentation_pdf_upload.php';
    return (string) ob_get_clean();
};
$markup = $render($stored);
expectUploadMetadata(
    str_contains($markup, 'Uploaded Sep 7, 2026 8:30 PM CDT')
        && str_contains($markup, 'By Taylor &lt;editor&gt;')
        && strpos($markup, 'View notes.pdf') < strpos($markup, 'Uploaded Sep 7'),
    'The saved PDF shows its UTC upload time in the application timezone and safely escapes the uploader beside the filename.'
);
$rehydrated = mergeStoredPresentationAssetMetadata([
    ['id' => 123, 'speaker_notes_updated_at' => '2099-01-01', 'speaker_notes_uploaded_by_username' => 'Forged'],
], [$stored]);
expectUploadMetadata(
    $render($rehydrated[0]) === $markup,
    'Validation errors restore the stored metadata instead of accepting submitted attribution.'
);
$legacy = $stored;
unset($legacy['speaker_notes_uploaded_by_username']);
expectUploadMetadata(
    str_contains($render($legacy), 'Uploader unknown')
        && str_contains($render($legacy), 'Uploaded Sep 7, 2026 8:30 PM CDT'),
    'Existing PDFs keep their known timestamps without inventing an uploader.'
);
$legacy['speaker_notes_updated_at'] = null;
expectUploadMetadata(
    !str_contains($render($legacy), 'presentation-upload-timestamp'),
    'Missing timestamps must never be displayed as the current time.'
);
foreach ([[], array_replace($stored, ['has_speaker_notes' => false])] as $no_pdf) {
    expectUploadMetadata(
        !str_contains($render($no_pdf), 'presentation-upload-'),
        'New and removed PDFs do not display stale upload metadata.'
    );
}

echo "Presentation upload metadata tests passed.\n";
