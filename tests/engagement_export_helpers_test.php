<?php
$source_directory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
$vendor_autoload = getenv('DNR_TEST_VENDOR_AUTOLOAD') ?: __DIR__ . '/../vendor/autoload.php';
require_once $vendor_autoload;
require_once $source_directory . '/engagement_export_helpers.php';
require_once $source_directory . '/engagement_pdf.php';

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
    'event_description' => "A multi-day gathering\nfor community leaders — أهلاً وسهلاً — שלום.",
    'organization_name' => 'Example & Partners',
    'event_type' => 'Conference',
    'event_start_date' => '2026-08-20',
    'event_end_date' => '2026-08-22',
    'confirmation_status' => 'under_review',
    'lifecycle_status' => 'canceled',
    'cancellation_reason' => 'Venue unavailable',
    'rescheduled_event_label' => 'Autumn Summit · 2026-10-20',
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
    'engagement_contact_roles' => ['primary_host', 'travel'],
    'contact_email' => 'jamie@example.test',
    'contact_phone' => '+13125550100',
]];
$presentations = [[
    'topic_title' => 'Opening Keynote',
    'presentation_date' => '2026-08-21',
    'presentation_time' => '09:30',
    'speaker_name' => 'Jordan Speaker',
    'duration_minutes' => 75,
    'expected_attendance' => 250,
    'actual_attendance' => 225,
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
$follow_up_tasks = [
    [
        'title' => 'Confirm travel arrangements',
        'status' => 'open',
        'priority' => 'urgent',
        'due_date' => '2026-08-14',
        'assignee_username' => 'Alex Editor',
        'waiting_on' => null,
    ],
    [
        'title' => 'Send the final speaker packet',
        'status' => 'in_progress',
        'priority' => 'high',
        'due_date' => '2026-08-15',
        'assignee_username' => 'Jordan Admin',
        'waiting_on' => null,
    ],
    [
        'title' => 'Collect final slides',
        'status' => 'waiting',
        'priority' => 'normal',
        'due_date' => '2026-08-18',
        'assignee_username' => '',
        'waiting_on' => 'Presenter approval',
    ],
    [
        'title' => 'Completed setup task',
        'status' => 'completed',
        'priority' => 'low',
        'due_date' => '2026-08-13',
        'assignee_username' => 'Alex Editor',
        'waiting_on' => null,
    ],
];

$export = buildEngagementExport($engagement, $contacts, $presentations, $chron_entries);
$plain_text = renderEngagementPlainText($export);
$markdown = renderEngagementMarkdown($export);

expectExport(str_contains($plain_text, "Organization: Example & Partners"), 'Plain text includes the organization.');
expectExport(
    str_contains($plain_text, "Lifecycle: Canceled\nConfirmation: under review")
        && str_contains($plain_text, 'Cancellation Reason: Venue unavailable')
        && str_contains($plain_text, 'Rescheduled Event: Autumn Summit · 2026-10-20'),
    'exports should distinguish lifecycle, confirmation, cancellation, and replacement details.'
);
expectExport(!str_contains($plain_text, 'Event Title:'), 'Overview does not repeat the event title.');
expectExport(
    str_contains($plain_text, "Event Description: A multi-day gathering\n  for community leaders — أهلاً وسهلاً — שלום."),
    'Plain text preserves the multi-line Unicode event description.'
);
expectExport(
    str_contains($plain_text, "Jamie Smith\nEvent Roles: Primary host, Travel\nRole: Events Director"),
    'Plain text includes event-specific and organization contact roles.'
);
expectExport(str_contains($plain_text, 'Phone: +1 312-555-0100'), 'Exports format canonical telephone values for display.');
expectExport(str_contains($plain_text, "Opening Keynote\nSpeaker: Jordan Speaker"), 'Plain text includes presentations.');
expectExport(
    str_contains($plain_text, "Duration: 75 minutes\nExpected Attendance: 250\nActual Attendance: 225"),
    'presentation exports should include duration and both attendance figures.'
);
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
expectExport(
    engagementPdfDisplayValue('Event Dates', '2026-08-20 to 2026-08-22') === 'August 20, 2026 - August 22, 2026',
    'PDF dates use a readable display format.'
);
expectExport(
    engagementPdfDisplayValue('Status', 'under_review') === 'Under Review',
    'PDF status values are formatted for display.'
);
expectExport(
    engagementPdfDisplayValue('Date and Time', '2026-08-21 09:30 AM') === 'August 21, 2026 at 9:30 AM',
    'PDF presentation dates use a readable display format.'
);
expectExport(
    engagementPdfBrandLogoPath() === realpath($source_directory . '/' . applicationBrandEmailLogo()),
    'PDF export uses the configured digest-email logo artwork.'
);
expectExport(
    engagementPdfDateLabel('2026-08-15') === 'August 15, 2026',
    'PDF generated dates use the same application business date as task due states.'
);

$task_section = buildEngagementPdfTaskSection($follow_up_tasks, '2026-08-15');
expectExport(
    $task_section !== null
        && array_column($task_section['entries'], 'due_state') === ['overdue', 'today', 'upcoming']
        && array_column($task_section['entries'], 'title') === [
            'Confirm travel arrangements',
            'Send the final speaker packet',
            'Collect final slides',
        ],
    'PDF export includes only active event tasks and preserves their due-state semantics.'
);
$truncated_task_section = buildEngagementPdfTaskSection($follow_up_tasks, '2026-08-15', true);
expectExport(
    $truncated_task_section !== null
        && str_contains($truncated_task_section['notice'], 'first 3 active tasks')
        && str_contains($truncated_task_section['notice'], 'Additional active tasks'),
    'bounded PDF task exports disclose when additional active work was omitted.'
);
expectExport(
    engagementPdfTaskCardPalette('overdue')['edge'] === [217, 45, 32]
        && engagementPdfTaskCardPalette('overdue')['fill'] === [255, 232, 238]
        && engagementPdfTaskCardPalette('today')['edge'] === [37, 99, 235]
        && engagementPdfTaskCardPalette('today')['fill'] === [228, 242, 255],
    'PDF task cards use the Dashboard overdue and due-today fills and semantic edges.'
);

$pagination_pdf = new DnrEngagementPdf('P', 'mm', 'LETTER', true, 'UTF-8', false);
$pagination_pdf->SetMargins(18, 23, 18);
$pagination_pdf->SetAutoPageBreak(true, 18);
$pagination_pdf->AddPage();
expectExport(
    engagementPdfSectionMinimumStartHeight($pagination_pdf, $task_section) > 24,
    'task sections reserve room for both the heading and first card before choosing a page.'
);

$pdf_contents = renderEngagementPdf(
    $export,
    'August 15, 2026',
    $follow_up_tasks,
    '2026-08-15'
);
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
    preg_match('/\/Subtype\s*\/Image\b/', $pdf_contents) === 1,
    'PDF export embeds the graphical brand logo.'
);
expectExport(
    preg_match('/\/Count\s+([2-9]|[1-9][0-9]+)\b/', $pdf_contents) === 1,
    'PDF export includes a separate final page for Chron.'
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
