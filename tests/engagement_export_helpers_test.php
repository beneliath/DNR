<?php
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/engagement_export_helpers.php';
require_once __DIR__ . '/../src/engagement_pdf.php';

putenv('DNR_TIMEZONE=America/Chicago');

function expectExport($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$engagement = [
    'id' => 42,
    'event_title' => 'Summer *Summit*',
    'event_description' => "A multi-day gathering\nfor community leaders.",
    'organization_name' => 'Example & Partners',
    'event_type' => 'Conference',
    'event_start_date' => '2026-08-20',
    'event_end_date' => '2026-08-22',
    'confirmation_status' => 'under_review',
    'is_deleted' => 1,
    'book_table' => 1,
    'brochures' => 0,
    'travel_covered' => 'yes',
    'caller_name' => 'Alex Caller',
    'compensation_type' => 'Honorarium',
    'other_compensation' => 'Payable after event',
    'travel_amount' => '125.50',
    'housing_type' => 'Provided',
    'other_housing' => 'Two nights',
    'housing_amount' => '300.00',
    'event_address_line_1' => '123 Main Street',
    'event_address_line_2' => 'Suite 4',
    'event_city' => 'Madison',
    'event_state' => 'WI',
    'event_zipcode' => '53703',
    'event_country' => 'USA',
];
$contacts = [[
    'contact_first_name' => 'Jamie',
    'contact_last_name' => 'Smith',
    'contact_role' => 'other',
    'contact_role_other' => 'Events Director',
    'contact_email' => 'jamie@example.test',
    'contact_phone' => '555-0100',
]];
$presentations = [[
    'topic_title' => 'Opening Keynote',
    'presentation_date' => '2026-08-21',
    'presentation_time' => '09:30',
    'speaker_name' => 'Jordan Speaker',
    'expected_attendance' => 250,
]];
$chron_entries = [
    [
        'id' => 8,
        'entry_text' => "Newest line\nSecond [line]",
        'created_at' => '2026-08-15 16:00:00',
        'updated_at' => '2026-08-15 16:00:00',
        'created_by_username' => 'Jordan Admin',
        'is_archived' => 0,
    ],
    [
        'id' => 7,
        'entry_text' => 'Earlier entry',
        'created_at' => '2026-08-14 14:30:00',
        'updated_at' => '2026-08-14 14:30:00',
        'created_by_username' => 'Alex Editor',
        'is_archived' => 0,
    ],
    [
        'id' => 6,
        'entry_text' => 'Archived entry must stay private',
        'created_at' => '2026-08-13 14:30:00',
        'updated_at' => '2026-08-13 14:30:00',
        'created_by_username' => 'Jordan Admin',
        'is_archived' => 1,
    ],
];

$export = buildEngagementExport($engagement, $contacts, $presentations, $chron_entries);
$plain_text = renderEngagementPlainText($export);
$markdown = renderEngagementMarkdown($export);

expectExport(str_contains($plain_text, "Organization: Example & Partners"), 'Plain text includes the organization.');
expectExport(!str_contains($plain_text, 'Event Title:'), 'Overview does not repeat the event title.');
expectExport(
    str_contains($plain_text, "Event Description: A multi-day gathering\n  for community leaders."),
    'Plain text includes the multi-line event description.'
);
expectExport(str_contains($plain_text, "Jamie Smith\nRole: Events Director"), 'Plain text includes contact details.');
expectExport(str_contains($plain_text, "Opening Keynote\nSpeaker: Jordan Speaker"), 'Plain text includes presentations.');
expectExport(
    str_contains($plain_text, "August 15, 2026 at 11:00 AM CDT - Jordan Admin\nEntry: Newest line\n  Second [line]"),
    'Plain text includes the Chron timestamp, creator, and multiline entry.'
);
expectExport(
    strpos($plain_text, 'Newest line') < strpos($plain_text, 'Earlier entry'),
    'Chron entries export in reverse chronological order.'
);
expectExport(!str_contains($plain_text, 'Archived entry must stay private'), 'Archived Chron entries are excluded.');
expectExport(str_contains($plain_text, 'Travel Amount: $125.50'), 'Plain text includes formatted currency.');
expectExport(str_contains($plain_text, "Address: 123 Main Street\n  Suite 4\n  Madison, WI 53703\n  USA"), 'Plain text includes the complete location.');

expectExport(str_starts_with($markdown, "# Summer \\*Summit\\*\n"), 'Markdown escapes formatting characters in the title.');
expectExport(str_contains($markdown, "- **Entry:** Newest line  \n  Second \\[line\\]"), 'Markdown preserves and indents multiline Chron entries.');
expectExport(str_contains($markdown, '## Presentations'), 'Markdown includes section headings.');
expectExport(
    str_contains($markdown, "- **Address:** 123 Main Street  \n  Suite 4  \n  Madison, WI 53703  \n  USA"),
    'Markdown keeps address continuation lines inside the list item.'
);
expectExport(
    engagementPdfFilename($engagement) === 'engagement-42-summer-summit.pdf',
    'PDF filenames are stable and safe.'
);

$pdf_contents = renderEngagementPdf($export, 'August 15, 2026');
$pdf_section_headings = array_column(orderEngagementPdfSections($export['sections']), 'heading');
expectExport(
    array_slice($pdf_section_headings, 0, 3) === ['Overview', 'Location', 'Event Details'],
    'PDF export places Location between Overview and Event Details.'
);
expectExport(!in_array('Chron', $pdf_section_headings, true), 'Chron is separated from the main PDF section order.');
expectExport(
    array_key_last($export['sections']) !== null
        && $export['sections'][array_key_last($export['sections'])]['heading'] === 'Chron',
    'Chron is the final export section.'
);
expectExport(str_starts_with($pdf_contents, '%PDF-'), 'PDF export has a valid PDF header.');
expectExport(str_ends_with(rtrim($pdf_contents), '%%EOF'), 'PDF export has a valid PDF trailer.');
expectExport(strlen($pdf_contents) > 1500, 'PDF export contains rendered engagement content.');
expectExport(
    preg_match('/\/Count\s+2\b/', $pdf_contents) === 1,
    'PDF export places Chron on its own final page for the complete fixture.'
);

$sample_output = getenv('DNR_PDF_TEST_OUTPUT');
if (is_string($sample_output) && $sample_output !== '') {
    $directory = dirname($sample_output);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        fwrite(STDERR, "FAIL: Unable to create the PDF test output directory.\n");
        exit(1);
    }
    file_put_contents($sample_output, $pdf_contents);
}

echo "Engagement export helper tests passed.\n";
