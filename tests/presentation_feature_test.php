<?php
require_once __DIR__ . '/../src/presentation_helpers.php';

function expectPresentationFeature($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Presentation feature test failed: {$message}\n");
        exit(1);
    }
}

$presentations = normalizeEngagementPresentations(
    [[
        'topic_title' => 'Opening keynote',
        'presentation_date' => '2026-08-21',
        'presentation_time' => '9:30 pm',
        'speaker_name' => '',
        'expected_attendance' => '125',
    ]],
    '2026-08-20',
    '2026-08-22',
    'Default Speaker'
);

expectPresentationFeature(count($presentations) === 1, 'a complete presentation should be normalized.');
expectPresentationFeature($presentations[0]['presentation_time'] === '21:30:00', 'time should be normalized for the SQL TIME column.');
expectPresentationFeature($presentations[0]['speaker_name'] === 'Default Speaker', 'blank speaker should use the default.');
expectPresentationFeature($presentations[0]['expected_attendance'] === 125, 'attendance should be an integer.');
expectPresentationFeature(normalizePresentationTime('0800') === '08:00:00', '0800 should convert to 08:00:00.');
expectPresentationFeature(normalizePresentationTime('1530') === '15:30:00', '1530 should convert to 15:30:00.');
expectPresentationFeature(normalizePresentationTime('0000') === '00:00:00', '0000 should convert to 00:00:00.');
expectPresentationFeature(normalizePresentationTime('1200') === '12:00:00', '1200 should convert to 12:00:00.');
expectPresentationFeature(formatPresentationTime('21:30:00') === '09:30 PM', 'stored times should be formatted for people.');

$blank_presentations = normalizeEngagementPresentations(
    [['topic_title' => '', 'speaker_name' => 'Default Speaker']],
    '2026-08-20',
    '2026-08-22',
    'Default Speaker'
);
expectPresentationFeature($blank_presentations === [], 'the initial blank presentation row should remain optional.');

try {
    requirePresentationForConfirmedEngagement('confirmed', []);
    expectPresentationFeature(false, 'confirmed engagements without presentations must be rejected.');
} catch (InvalidArgumentException $exception) {
    expectPresentationFeature(
        str_contains($exception->getMessage(), 'at least one presentation'),
        'the confirmation error should explain the requirement.'
    );
}

requirePresentationForConfirmedEngagement('under_review', []);
requirePresentationForConfirmedEngagement('confirmed', $presentations);
expectPresentationFeature(
    presentationRemovalRequiresReview('confirmed', 1),
    'removing the last presentation from a confirmed engagement should require review.'
);
expectPresentationFeature(
    !presentationRemovalRequiresReview('confirmed', 2)
        && !presentationRemovalRequiresReview('under_review', 1),
    'presentation removal should preserve status when the confirmed invariant remains satisfied.'
);

foreach ([
    [[['topic_title' => '', 'presentation_date' => '2026-08-21']], 'topic/title'],
    [[['topic_title' => 'Bad date', 'presentation_date' => '2026-08-23']], 'between'],
    [[['topic_title' => 'Missing date', 'presentation_time' => '09:30 AM']], 'enter a date'],
    [[['topic_title' => 'Missing time', 'presentation_date' => '2026-08-21']], 'enter a time'],
    [[['topic_title' => 'Bad time', 'presentation_date' => '2026-08-21', 'presentation_time' => '25:00']], 'valid presentation time'],
    [[['topic_title' => 'Bad attendance', 'presentation_date' => '2026-08-21', 'presentation_time' => '09:30 AM', 'expected_attendance' => '0']], 'at least 1'],
] as [$submission, $expected_message]) {
    try {
        normalizeEngagementPresentations(
            $submission,
            '2026-08-20',
            '2026-08-22',
            'Default Speaker'
        );
        expectPresentationFeature(false, "invalid presentation should be rejected: {$expected_message}.");
    } catch (InvalidArgumentException $exception) {
        expectPresentationFeature(
            str_contains(strtolower($exception->getMessage()), strtolower($expected_message)),
            "invalid presentation should explain: {$expected_message}."
        );
    }
}

$new_engagement_source = file_get_contents(__DIR__ . '/../src/index.php');
$edit_engagement_source = file_get_contents(__DIR__ . '/../src/edit_engagement.php');
$presentation_template = file_get_contents(__DIR__ . '/../src/templates/presentation_form.php');
$presentation_script = file_get_contents(__DIR__ . '/../src/assets/js/presentation-form.js');
$restore_presentations = file_get_contents(__DIR__ . '/../src/restore_presentations.php');
$presentation_migration = file_get_contents(__DIR__ . '/../migrations/20260817_add_presentation_archiving.sql');
$calendar_source = file_get_contents(__DIR__ . '/../src/calendar.php');
$view_source = file_get_contents(__DIR__ . '/../src/view_engagement.php');
$pdf_source = file_get_contents(__DIR__ . '/../src/download_engagement_pdf.php');
$modern_styles = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');

foreach ([$new_engagement_source, $edit_engagement_source] as $engagement_source) {
    expectPresentationFeature(
        str_contains($engagement_source, "include 'templates/presentation_form.php'")
            && str_contains($engagement_source, 'requirePresentationForConfirmedEngagement')
            && str_contains($engagement_source, "renderScript('assets/js/presentation-form.min.js'"),
        'new and edit engagement flows should share the form and enforce the confirmation rule.'
    );
}

expectPresentationFeature(
    str_contains($edit_engagement_source, 'syncEngagementPresentations'),
    'editing should transactionally synchronize added and changed presentations.'
);
expectPresentationFeature(
    str_contains($view_source, "require_once __DIR__ . '/engagement_export_helpers.php';")
        && str_contains($view_source, "require_once __DIR__ . '/presentation_helpers.php';")
        && !str_contains($view_source, "include 'presentation_helpers.php';"),
    'the engagement detail route should load overlapping export and presentation helpers idempotently.'
);
expectPresentationFeature(
    str_contains($presentation_template, '>Add Presentation</button>')
        && str_contains($presentation_template, 'name="presentations[')
        && substr_count($presentation_template, '<span class="required">*</span>') >= 3,
    'the shared form should render presentation inputs, required markers, and an add button.'
);
expectPresentationFeature(
    str_contains($presentation_script, 'confirmedOption.disabled = !hasCompletePresentation()')
        && str_contains($presentation_script, 'status.value === "confirmed" && !hasCompletePresentation()')
        && !str_contains($presentation_template, 'data-require-presentation-on-save')
        && !str_contains($presentation_script, 'requiresPresentationOnSave')
        && str_contains($presentation_script, 'Enter a date for this presentation.')
        && str_contains($presentation_script, 'Enter a time for this presentation.')
        && str_contains($presentation_script, 'for (var existingEntry of entries)')
        && str_contains($presentation_script, 'validatePresentationEntry(existingEntry, startInput, endInput, true)')
        && str_contains($presentation_script, 'function compact24HourTime(time)')
        && str_contains($presentation_script, 'compactValue.charAt(0) !== "0"')
        && str_contains($presentation_script, 'parseInt(compactValue, 10) < 1300')
        && str_contains($presentation_script, 'timeInput.addEventListener("blur"')
        && str_contains($presentation_script, 'periodInput.checked = true;'),
    'the browser should validate entered presentations while allowing presentation-free draft and review engagements.'
);
expectPresentationFeature(
    str_contains($edit_engagement_source, "in_array(\$presentation_action, ['archive', 'delete'], true)")
        && str_contains($edit_engagement_source, "SET confirmation_status = 'under_review', updated_at = CURRENT_TIMESTAMP")
        && str_contains($edit_engagement_source, 'Engagement status changed to under review')
        && str_contains($presentation_template, 'presentation_action_message')
        && str_contains($presentation_template, 'presentation_action_error')
        && str_contains($presentation_template, 'data-delete-confirmation') === false
        && str_contains($edit_engagement_source, 'data-delete-confirmation="Permanently delete this presentation?"')
        && str_contains($restore_presentations, "SET is_archived = 0, archived_by = NULL, archived_at = NULL")
        && str_contains($restore_presentations, 'falls outside the current engagement dates')
        && str_contains($restore_presentations, 'name="presentation_dates[')
        && str_contains($restore_presentations, 'data-archive-button-label="Keep Archived"'),
    'saved presentations should support archive, restore, and confirmed permanent deletion.'
);
expectPresentationFeature(
    str_contains(file_get_contents(__DIR__ . '/../src/presentation_helpers.php'), 'Every active presentation must be included when saving'),
    'engagement edits should reject crafted submissions that omit an active presentation.'
);
expectPresentationFeature(
    str_contains($presentation_migration, 'is_archived TINYINT(1) NOT NULL DEFAULT 0')
        && str_contains($presentation_migration, 'fk_presentation_archiver')
        && str_contains($calendar_source, 'AND p.is_archived = 0')
        && str_contains($view_source, 'WHERE engagement_id = ? AND is_archived = 0')
        && str_contains($pdf_source, 'WHERE engagement_id = ? AND is_archived = 0'),
    'archived presentations should be tracked and excluded from active views, calendars, and exports.'
);
expectPresentationFeature(
    str_contains($modern_styles, '.currency-input input[type="number"]::-webkit-inner-spin-button')
        && preg_match('/\.currency-input input\[type="number"\]\s*\{[^}]*appearance:\s*textfield;/s', $modern_styles) === 1,
    'currency amount fields should hide native number spinners without affecting attendance fields.'
);
expectPresentationFeature(
    preg_match('/\.presentation-management-actions\s*\{[^}]*gap:\s*12px;/s', $modern_styles) === 1,
    'saved presentation archive and delete buttons should have visible separation.'
);
expectPresentationFeature(
    preg_match('/\.time-input-container\s*>\s*input\[type="text"\]\s*\{[^}]*width:\s*150px\s*!important;[^}]*flex:\s*0\s+0\s+150px;/s', $modern_styles) === 1,
    'the presentation time field should be wide enough to show its full placeholder.'
);

echo "Presentation feature tests passed.\n";
