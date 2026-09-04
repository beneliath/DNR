<?php

declare(strict_types=1);

function expectBookingInquiryFeature(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Booking inquiry feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static function (string $path) use ($root): string {
    $contents = file_get_contents($root . '/' . $path);
    if (!is_string($contents)) {
        throw new RuntimeException("Unable to read {$path}.");
    }
    return $contents;
};

$migration = $read('migrations/20260904_add_inquiry_booking_pipeline.sql');
$helpers = $read('src/booking_inquiry_helpers.php');
$board = $read('src/inquiries.php');
$view = $read('src/view_inquiry.php');
$conversion = $read('src/convert_inquiry.php');
$tasks = $read('src/follow_up_task_helpers.php');
$mail = $read('src/compose_inquiry_email.php');
$inbound = $read('src/inbound_email_helpers.php');
$inboundReview = $read('src/inbound_mail.php');
$dashboard = $read('src/dashboard.php');
$header = $read('src/templates/header.php');
$manual = $read('src/help.php');
$chronRestore = $read('src/restore_entity_chron_entries.php');
$pageActions = $read('src/assets/js/page-actions.js');
$modernStyles = $read('src/assets/css/modern.css');
$bookingStyles = $read('src/assets/css/pages/booking_inquiries.css');
$formTemplate = $read('src/templates/booking_inquiry_form.php');
$addInquiry = $read('src/add_inquiry.php');
$editInquiry = $read('src/edit_inquiry.php');
$outboundMessage = $read('src/outbound_mail.php');
$emailHelpers = $read('src/engagement_email_helpers.php');
$workQueue = $read('src/tasks.php');
$archiveService = $read('src/app/Service/ArchiveService.php');

expectBookingInquiryFeature(
    str_contains($migration, 'CREATE TABLE booking_inquiries')
        && str_contains($migration, 'CREATE TABLE booking_inquiry_stage_history')
        && str_contains($migration, 'CREATE TABLE booking_inquiry_chron_entries')
        && str_contains($migration, "'proposal_sent', 'booked', 'declined'")
        && str_contains($migration, 'chk_booking_inquiry_decline')
        && str_contains($migration, 'chk_booking_inquiry_conversion')
        && str_contains($migration, "'general', 'engagement', 'organization', 'contact', 'inquiry'")
        && str_contains($migration, 'chk_follow_up_task_subject'),
    'the schema should enforce first-class inquiries, durable history, and exclusive task subjects.'
);

expectBookingInquiryFeature(
    str_contains($bookingStyles, 'html body main.container.inquiry-form-page > form.inquiry-form.inquiry-form')
        && str_contains($bookingStyles, 'background-color: transparent !important;')
        && preg_match('/main\.container\.inquiry-form-page > form\.inquiry-form\.inquiry-form\s*\{[^}]*border:\s*0 !important;[^}]*background-color:\s*transparent !important;[^}]*box-shadow:\s*none !important;/s', $bookingStyles) === 1
        && str_contains($bookingStyles, '.inquiry-form-heading,')
        && str_contains($bookingStyles, '.inquiry-form-heading > div,')
        && str_contains($bookingStyles, 'main.inquiry-form-page > header.inquiry-form-heading')
        && str_contains($bookingStyles, 'main.inquiry-form-page > header.inquiry-form-heading::after')
        && str_contains($bookingStyles, 'border-bottom: 0 !important;')
        && str_contains($bookingStyles, 'border-top: 0 !important;')
        && preg_match('/main\.inquiry-form-page > header\.inquiry-form-heading\s*\{[^}]*margin-bottom:\s*\.5rem !important;/s', $bookingStyles) === 1
        && str_contains($addInquiry, 'class="page-heading inquiry-form-heading"')
        && str_contains($editInquiry, 'class="page-heading inquiry-form-heading"')
        && preg_match('/\.inquiry-pipeline-page,\s*\.inquiry-detail-page,\s*\.inquiry-form-page,\s*\.inquiry-conversion-page\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);/s', $bookingStyles) === 1
        && preg_match('/\.inquiry-form-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $bookingStyles) === 1
        && preg_match('/\.inquiry-form-body \.app-footer\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);/s', $bookingStyles) === 1
        && str_contains($bookingStyles, '.inquiry-form-section-heading')
        && str_contains($bookingStyles, '.inquiry-form-actions')
        && str_contains($formTemplate, 'class="engagement-page-actions inquiry-form-actions"')
        && str_contains($formTemplate, 'class="save-button inquiry-primary-action"')
        && str_contains($formTemplate, 'class="form-group inquiry-request-summary"')
        && str_contains($bookingStyles, '.inquiry-request-summary { margin-top: .55rem; }')
        && str_contains($bookingStyles, '.inquiry-form input[type="date"]::-webkit-calendar-picker-indicator')
        && str_contains($bookingStyles, 'accent-color: var(--primary);')
        && str_contains($bookingStyles, 'color-scheme: dark;')
        && preg_match('/\.inquiry-form-actions\s*\{[^}]*border-top:/s', $bookingStyles) === 0,
    'inquiry forms should use transparent page-level wrappers, clean section cards, and the booking workflow action hierarchy.'
);

expectBookingInquiryFeature(
    str_contains($formTemplate, 'class="address-section is-saved-address-section inquiry-location-section"')
        && str_contains($formTemplate, 'data-address-region-control data-address-region-for="event"')
        && str_contains($formTemplate, "addressCountryPicker(")
        && str_contains($formTemplate, 'id="address-region-data"')
        && str_contains($formTemplate, '$inquiry_sources = bookingInquirySelectableSources();')
        && str_contains($helpers, "unset(\$sources['mattermost']);")
        && str_contains($helpers, 'array_key_exists($source, bookingInquirySelectableSources())')
        && str_contains($formTemplate, 'class="inquiry-source-fields"')
        && str_contains($formTemplate, 'form-group inquiry-source-detail-field')
        && str_contains($formTemplate, 'form-group inquiry-next-action-detail-field')
        && str_contains($bookingStyles, 'grid-template-columns: minmax(190px, .55fr) minmax(0, 1.45fr);')
        && preg_match('/\.inquiry-source-detail-field input,\s*\.inquiry-next-action-detail-field input\s*\{[^}]*width:\s*100% !important;[^}]*border-radius:\s*8px !important;/s', $bookingStyles) === 1
        && preg_match('/\.inquiry-next-action-fields\s*\{[^}]*margin-top:\s*\.9rem;/s', $bookingStyles) === 1
        && str_contains($formTemplate, 'class="inquiry-form-grid inquiry-event-type-grid"')
        && str_contains($formTemplate, 'id="other_event_type_div"')
        && str_contains($formTemplate, "=== 'other' ? '' : ' hidden'")
        && !str_contains($bookingStyles, '.inquiry-event-type-grid:has(')
        && str_contains($bookingStyles, 'flex: 0 0 calc((100% - 1.1rem) / 2);')
        && str_contains($pageActions, '.engagement-form, #engagement-edit-form, .inquiry-form, [data-chron-form]')
        && str_contains($pageActions, "document.getElementById('preferred-start')")
        && str_contains($pageActions, "document.getElementById('alternate-start')")
        && str_contains($pageActions, 'range.end.min = range.start.value;')
        && str_contains($helpers, "end date cannot be before its start date")
        && !str_contains($formTemplate, 'name="event_phone"'),
    'inquiry qualification should reuse the shared address controls and reveal Other Event Type only when selected.'
);

expectBookingInquiryFeature(
    str_contains($helpers, 'function normalizeBookingInquiryInput(')
        && str_contains($helpers, 'function createBookingInquiry(')
        && str_contains($helpers, 'function changeBookingInquiryStage(')
        && str_contains($helpers, 'function bookingInquiryDateConflicts(')
        && str_contains($helpers, 'function convertBookingInquiry(')
        && str_contains($helpers, 'begin_transaction()')
        && str_contains($helpers, 'bookingInquiryDateConflicts(')
        && preg_match('/bookingInquiryDateConflicts\([^;]+true\s*\)/s', $helpers) === 1
        && str_contains($helpers, '($lockRows ? \' FOR UPDATE\' : \'\')')
        && str_contains($helpers, "'booked'")
        && str_contains($helpers, 'generateEngagementFollowUpChecklist(')
        && str_contains($tasks, 'function lockFollowUpTaskInquiries(')
        && str_contains($tasks, "'SELECT id FROM booking_inquiries WHERE id = ? FOR UPDATE'")
        && str_contains($helpers, 'BOOKED FROM INQUIRY #'),
    'the pipeline should validate, audit, and transactionally convert an inquiry into one engagement.'
);

expectBookingInquiryFeature(
    str_contains($board, 'class="inquiry-kanban inquiry-kanban-columns-')
        && str_contains($board, 'bookingInquiryStages()')
        && str_contains($board, 'owner')
        && str_contains($board, 'mine_or_unassigned')
        && str_contains($board, 'priority')
        && str_contains($board, 'next_action_due_date')
        && str_contains($board, "'missing_action'")
        && str_contains($board, '<option value="">All</option>')
        && str_contains($board, "['new', 'contacted', 'awaiting_details', 'proposal_sent', 'booked']")
        && str_contains($board, '$displayCounts = array_map(')
        && str_contains($board, 'static fn(array $stageInquiries): int => count($stageInquiries)')
        && str_contains($board, '$displayCounts[$stage]')
        && !str_contains($board, 'class="inquiry-pipeline-summary"')
        && str_contains($board, 'class="inquiry-stage-icon"')
        && str_contains($board, '$stageIcons[$stage]')
        && str_contains($bookingStyles, '.inquiry-stage-icon {')
        && str_contains($board, 'data-inquiry-filter')
        && str_contains($board, 'data-disclosure-popover')
        && str_contains($board, 'data-disclosure-popover-close>Cancel</button>')
        && substr_count($board, '<option value="" selected disabled>Select Stage</option>') === 1
        && str_contains($board, 'class="app-popover inquiry-stage-popover"')
        && str_contains($board, '$stage === \'booked\'')
        && str_contains($board, 'converted_at')
        && str_contains($board, "'export' => 'csv'")
        && str_contains($view, 'fetchBookingInquiryStageHistory')
        && str_contains($view, 'fetchBookingInquiryEmailMessages')
        && str_contains($view, 'entity_type=inquiry')
        && str_contains($chronRestore, "['contact', 'organization', 'inquiry']")
        && str_contains($view, "fetchFollowUpTasksForSubject(\$conn, 'inquiry'")
        && str_contains($view, 'name="stage" required><option value="" selected disabled>Select Stage</option>')
        && str_contains($conversion, 'Convert Inquiry to Engagement')
        && str_contains($conversion, 'acknowledge_conflicts')
        && str_contains($conversion, 'task_ids[]'),
    'the UI should provide a filterable board, one working record, and an explicit booking review.'
);

expectBookingInquiryFeature(
    str_contains($pageActions, 'function initializeDisclosurePopovers()')
        && str_contains($pageActions, "querySelectorAll('[data-disclosure-popover-close]')")
        && !str_contains($pageActions, '!disclosure.contains(event.target)')
        && str_contains($pageActions, "event.key !== 'Escape'")
        && str_contains($pageActions, 'closeDisclosure(openDisclosure, true)')
        && str_contains($modernStyles, '.app-popover {')
        && str_contains($modernStyles, '.app-popover-actions {')
        && str_contains($modernStyles, 'background: var(--surface) !important;')
        && str_contains($modernStyles, 'border-radius: var(--radius-md) !important;')
        && str_contains($bookingStyles, '.inquiry-card-stage-menu .inquiry-stage-popover'),
    'stage popovers should use the shared themed surface and dismiss explicitly with Cancel or Escape.'
);

expectBookingInquiryFeature(
    str_contains($bookingStyles, '.inquiry-card-list { display: grid; min-width: 0; min-height: 0;')
        && !str_contains($bookingStyles, 'min-height: 520px;'),
    'the pipeline lanes should shrink-wrap to the tallest card stack instead of reserving dead vertical space.'
);

expectBookingInquiryFeature(
    str_contains($mail, 'initial_response')
        && str_contains($helpers, 'proposal_follow_up')
        && str_contains($helpers, 'applicationInquiryInboundMarker')
        && str_contains($helpers, 'booking_inquiry_id')
        && str_contains($inbound, 'inboundEmailMessageInquiryMarkers')
        && str_contains($inbound, 'authoritative_inquiry')
        && str_contains($inbound, "elseif (\$inquiry['stage'] === 'booked')")
        && str_contains($inbound, 'converted_engagement_id')
        && str_contains($inbound, 'inboundEmailEngagementMatch(')
        && str_contains($emailHelpers, 'LEFT JOIN booking_inquiries inquiry')
        && str_contains($emailHelpers, "Failed deliveries can be retried only while the Inquiry is active.")
        && str_contains($outboundMessage, '$isInquiryMessage = !empty($message[\'booking_inquiry_id\']);')
        && str_contains($outboundMessage, 'compose_inquiry_email.php')
        && str_contains($view, 'outbound_mail.php?id=')
        && str_contains($inboundReview, 'Create Inquiry')
        && str_contains($inboundReview, 'inquiry_ids[]'),
    'outbound templates and signed replies should preserve correspondence on the inquiry.'
);

expectBookingInquiryFeature(
    str_contains($workQueue, 'inquiry.title, inquiry.request_summary, inquiry.source_detail,')
        && str_contains($workQueue, 'inquiry.event_city, inquiry.event_state')
        && str_contains($archiveService, 'AS active_inquiries')
        && str_contains($archiveService, 'function contactActiveInquiryCount(')
        && str_contains($helpers, 'function lockBookingInquiryRelationships('),
    'Work Queue search and archive protections should include active Inquiry relationships.'
);

expectBookingInquiryFeature(
    str_contains($header, "'inquiries' => [")
        && str_contains($header, "'inquiries.php'")
        && str_contains($header, 'Booking Pipeline')
        && str_contains($dashboard, 'Inquiry Next Actions')
        && str_contains($dashboard, 'fetchDashboardOpenBookingInquiryCount')
        && str_contains($manual, 'id="booking-pipeline" data-manual-section')
        && str_contains($manual, 'Inquiry First, Engagement After Booking')
        && str_contains($manual, 'Open the Booking Pipeline'),
    'navigation, Dashboard triage, and the high-level User Manual should expose the workflow.'
);

echo "Booking inquiry feature tests passed.\n";
