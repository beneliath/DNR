<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/record_workspace_helpers.php';
require_once __DIR__ . '/presentation_chunk_helpers.php';
include 'chron_log_helpers.php';
include 'presentation_helpers.php';
include 'map_helpers.php';
include 'two_factor_helpers.php';
include 'follow_up_task_helpers.php';
include 'engagement_contact_helpers.php';
require_once __DIR__ . '/engagement_contact_input_helpers.php';
require_once __DIR__ . '/engagement_lifecycle_helpers.php';
startSecureSession();
requireLogin();

// Check if user has appropriate role
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['admin', 'editor'])) {
    header("Location: index.php");
    exit();
}

$engagement_id = \Dnr\Http\RequestInput::positiveInt($_GET, 'id');
$record_edit_return = safeRecordReturnUrl($_POST['return_to'] ?? $_GET['return_to'] ?? null, 'engagements.php');
if ($engagement_id === null) {
    header("Location: engagements.php");
    exit();
}

// Get engagement data
$query = "SELECT e.*, COALESCE(caller.username, e.caller_name) AS caller_name,
                 o.organization_name
          FROM engagements e
          LEFT JOIN organizations o ON e.organization_id = o.id
          LEFT JOIN users caller ON caller.id = e.caller_user_id
          WHERE e.id = ? AND e.is_deleted = 0";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $engagement_id);
$stmt->execute();
$result = $stmt->get_result();

if (!$result || $result->num_rows === 0) {
    header("Location: engagements.php");
    exit();
}

$engagement = $result->fetch_assoc();
$speaker_options = fetchSpeakerOptions($conn);
$default_speaker_id = defaultSpeakerId($speaker_options, applicationDefaultSpeaker());
$submitted_engagement_contacts = null;
$submitted_engagement_added_contact_ids = [];
$submitted_engagement_new_contacts = [];

// Archive or permanently delete one saved presentation without submitting
// unrelated edits in the engagement form.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['presentation_id'], $_POST['action'])) {
    requireValidCsrfToken();
    $presentation_id = filter_input(INPUT_POST, 'presentation_id', FILTER_VALIDATE_INT);
    $presentation_action = (string) $_POST['action'];

    if (!$presentation_id || !in_array($presentation_action, ['archive', 'delete'], true)) {
        $_SESSION['presentation_action_error'] = 'Select a valid presentation action.';
        header('Location: ' . recordUrlWithQuery('edit_engagement.php?id=' . $engagement_id, ['return_to' => $record_edit_return]) . '#presentations-container');
        exit();
    }
    if ($presentation_action === 'archive' && !canArchiveEntries($user_role)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    if ($presentation_action === 'delete' && !canDeleteEntries($user_role)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    if ($presentation_action === 'delete') {
        requireRecentAdminElevation('edit_engagement.php?id=' . $engagement_id . '#presentations-container');
    }

    $conn->begin_transaction();
    try {
        $engagement_lock_stmt = $conn->prepare(
            'SELECT confirmation_status, lifecycle_status, presentation_not_required
             FROM engagements WHERE id = ? FOR UPDATE'
        );
        if (!$engagement_lock_stmt) {
            throw new RuntimeException('Unable to prepare the presentation action.');
        }
        $engagement_lock_stmt->bind_param('i', $engagement_id);
        $engagement_lock_stmt->execute();
        $locked_engagement = $engagement_lock_stmt->get_result()->fetch_assoc();
        $engagement_lock_stmt->close();
        if (!$locked_engagement) {
            throw new InvalidArgumentException('That engagement is no longer available.');
        }

        $presentation_stmt = $conn->prepare(
            'SELECT is_archived
             FROM presentations
             WHERE id = ? AND engagement_id = ?
             FOR UPDATE'
        );
        if (!$presentation_stmt) {
            throw new RuntimeException('Unable to prepare the presentation action.');
        }
        $presentation_stmt->bind_param('ii', $presentation_id, $engagement_id);
        $presentation_stmt->execute();
        $presentation = $presentation_stmt->get_result()->fetch_assoc();
        $presentation_stmt->close();
        if (!$presentation || !empty($presentation['is_archived'])) {
            throw new InvalidArgumentException('That active presentation is no longer available.');
        }

        $presentation_removal_requires_review = false;
        if ($locked_engagement['confirmation_status'] === 'confirmed') {
            $active_count_stmt = $conn->prepare(
                'SELECT COUNT(*) AS presentation_count
                 FROM presentations
                 WHERE engagement_id = ? AND is_archived = 0'
            );
            if (!$active_count_stmt) {
                throw new RuntimeException('Unable to verify the confirmed engagement.');
            }
            $active_count_stmt->bind_param('i', $engagement_id);
            $active_count_stmt->execute();
            $active_count = (int) ($active_count_stmt->get_result()->fetch_assoc()['presentation_count'] ?? 0);
            $active_count_stmt->close();
            $presentation_removal_requires_review = presentationRemovalRequiresReview(
                $locked_engagement['confirmation_status'],
                $active_count,
                $locked_engagement['lifecycle_status'],
                (bool) $locked_engagement['presentation_not_required']
            );
        }

        if ($presentation_action === 'archive') {
            $action_stmt = $conn->prepare(
                'UPDATE presentations
                 SET is_archived = 1, archived_by = ?, archived_at = UTC_TIMESTAMP()
                 WHERE id = ? AND engagement_id = ? AND is_archived = 0'
            );
            $action_message = 'Presentation archived.';
        } else {
            $action_stmt = $conn->prepare(
                'DELETE FROM presentations
                 WHERE id = ? AND engagement_id = ? AND is_archived = 0'
            );
            $action_message = 'Presentation permanently deleted.';
        }
        if (!$action_stmt) {
            throw new RuntimeException('Unable to prepare the presentation action.');
        }
        if ($presentation_action === 'archive') {
            $current_user_id = (int) $_SESSION['user_id'];
            $action_stmt->bind_param('iii', $current_user_id, $presentation_id, $engagement_id);
        } else {
            $action_stmt->bind_param('ii', $presentation_id, $engagement_id);
        }
        if (!$action_stmt->execute() || $action_stmt->affected_rows !== 1) {
            $action_stmt->close();
            throw new RuntimeException('Unable to update the presentation.');
        }
        $action_stmt->close();

        $touch_sql = $presentation_removal_requires_review
            ? "UPDATE engagements
               SET confirmation_status = 'under_review', updated_at = CURRENT_TIMESTAMP(6)
               WHERE id = ?"
            : 'UPDATE engagements SET updated_at = CURRENT_TIMESTAMP(6) WHERE id = ?';
        $touch_stmt = $conn->prepare($touch_sql);
        if (!$touch_stmt) {
            throw new RuntimeException('Unable to update the engagement calendar timestamp.');
        }
        $touch_stmt->bind_param('i', $engagement_id);
        if (!$touch_stmt->execute()) {
            $touch_stmt->close();
            throw new RuntimeException('Unable to update the engagement calendar timestamp.');
        }
        $touch_stmt->close();

        if ($presentation_removal_requires_review) {
            $action_message .= ' Engagement status changed to under review because it no longer has an active presentation.';
        }

        $conn->commit();
        $_SESSION['presentation_action_message'] = $action_message;
    } catch (Throwable $exception) {
        $conn->rollback();
        $_SESSION['presentation_action_error'] = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Unable to update the presentation. Please try again.';
    }

    header('Location: ' . recordUrlWithQuery('edit_engagement.php?id=' . $engagement_id, ['return_to' => $record_edit_return]) . '#presentations-container');
    exit();
}

// Chron entries are managed individually so their timestamps and permissions
// are independent from the rest of the engagement form.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chron_action'])) {
    requireValidCsrfToken();
    $chron_action = (string) $_POST['chron_action'];
    $chron_entry_id = filter_input(INPUT_POST, 'chron_entry_id', FILTER_VALIDATE_INT);
    $current_user_id = (int) $_SESSION['user_id'];
    $chron_stmt = null;

    try {
        if (!$chron_entry_id) {
            throw new InvalidArgumentException('Select a valid Chron entry.');
        } elseif ($chron_action === 'archive') {
            $chron_stmt = $conn->prepare(
                'UPDATE engagement_chron_entries
                 SET is_archived = 1, archived_by = ?, archived_at = UTC_TIMESTAMP(),
                     updated_by = ?, updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND engagement_id = ? AND is_archived = 0'
            );
            if ($chron_stmt) {
                $chron_stmt->bind_param(
                    'iiii',
                    $current_user_id,
                    $current_user_id,
                    $chron_entry_id,
                    $engagement_id
                );
            }
            $chron_message = 'Chron entry archived.';
        } elseif ($chron_action === 'delete') {
            if ($user_role !== 'admin') {
                http_response_code(403);
                exit('Forbidden.');
            }
            requireRecentAdminElevation('edit_engagement.php?id=' . $engagement_id . '#chron-log');
            $chron_stmt = $conn->prepare(
                'DELETE FROM engagement_chron_entries
                 WHERE id = ? AND engagement_id = ?'
            );
            if ($chron_stmt) {
                $chron_stmt->bind_param('ii', $chron_entry_id, $engagement_id);
            }
            $chron_message = 'Chron entry permanently deleted.';
        } else {
            throw new InvalidArgumentException('Invalid Chron action.');
        }

        if (!$chron_stmt || !$chron_stmt->execute() || $chron_stmt->affected_rows !== 1) {
            throw new RuntimeException('Unable to update the Chron entry.');
        }
        $chron_stmt->close();
        $_SESSION['chron_action_message'] = $chron_message;
    } catch (Throwable $exception) {
        if ($chron_stmt instanceof mysqli_stmt) {
            $chron_stmt->close();
        }
        $_SESSION['chron_action_error'] = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Unable to update the Chron log. Please try again.';
    }

    header('Location: ' . recordUrlWithQuery('edit_engagement.php?id=' . $engagement_id, ['return_to' => $record_edit_return]) . '#chron-log');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['save_engagement']) || isset($_POST['save_and_add_chron']))) {
    requireValidCsrfToken();
    applicationLog('info', 'Processing engagement update', ['engagement_id' => $engagement_id]);

    $locked_engagement = null;
    try {
        $submitted_engagement_added_contact_ids = normalizeEngagementAddedContactIds(
            $_POST['engagement_added_contact_ids'] ?? null
        );
        $submitted_engagement_contacts = normalizeEngagementContactAssignments(
            $_POST['engagement_contacts'] ?? null
        );
        $submitted_engagement_new_contacts = normalizeEngagementNewContacts(
            $_POST['engagement_new_contacts'] ?? null
        );
        $result = \Dnr\Service\EngagementWrite::edit(
            $conn,
            $engagement_id,
            $_POST,
            $_FILES,
            [
                'assignments' => $submitted_engagement_contacts,
                'added_ids' => $submitted_engagement_added_contact_ids,
                'new_contacts' => $submitted_engagement_new_contacts,
            ],
            $default_speaker_id,
            (int) $_SESSION['user_id'],
            (string) $_SESSION['username'],
            $locked_engagement
        );
        $archived_task_count = $result['archived_tasks'];
        foreach ($result['consumed_upload_tokens'] as $token) {
            discardPresentationChunk($token);
        }
        $success_message = 'Engagement updated successfully.';
        if ($archived_task_count > 0) {
            $success_message .= ' ' . $archived_task_count . ' associated task'
                . ($archived_task_count === 1 ? ' was' : 's were') . ' archived.';
        }
        $_SESSION['clear_form_drafts'][] = 'edit_engagement.php:' . $engagement_id;
        $_SESSION['engagement_action_message'] = $success_message;
        applicationLog('info', 'Engagement updated', ['engagement_id' => $engagement_id]);

        // Redirect to engagements listing
        header('Location: ' . $record_edit_return);
        exit();

    } catch (Throwable $e) {
        if ($locked_engagement !== null) {
            $engagement = array_merge($engagement, $locked_engagement);
        }
        $event_type_raw = $locked_engagement !== null && is_scalar($_POST['event_type'] ?? null)
            ? trim((string) $_POST['event_type'])
            : '';
        applicationLog('error', 'Engagement update failed', [
            'engagement_id' => $engagement_id,
            'error' => $e->getMessage(),
        ]);
        $error_message = $e instanceof InvalidArgumentException
            ? $e->getMessage()
            : "Unable to update the engagement. Please try again.";

        $rehydrated_fields = [
            'organization_id', 'event_title', 'event_description', 'event_start_date', 'event_end_date',
            'caller_user_id', 'confirmation_status', 'lifecycle_status',
            'cancellation_reason', 'rescheduled_to_engagement_id',
            'travel_covered', 'travel_amount',
            'compensation_type', 'other_compensation', 'housing_type', 'other_housing',
            'housing_amount', 'event_address_line_1', 'event_address_line_2',
            'event_city', 'event_state', 'event_zipcode', 'event_country',
        ];
        foreach ($rehydrated_fields as $rehydrated_field) {
            if (array_key_exists($rehydrated_field, $_POST) && is_scalar($_POST[$rehydrated_field])) {
                $engagement[$rehydrated_field] = trim((string) $_POST[$rehydrated_field]);
            }
        }
        $engagement['event_type'] = in_array($event_type_raw, \Dnr\Domain\ReferenceData::eventTypes(), true)
            ? $event_type_raw
            : ($engagement['event_type'] ?? 'conference');
        $engagement['event_type_other'] = trim((string) ($_POST['event_type_other'] ?? ''));
        $engagement['book_table'] = isset($_POST['book_table']) ? 1 : 0;
        $engagement['brochures'] = isset($_POST['brochures']) ? 1 : 0;
        $engagement['presentation_not_required'] = ($_POST['presentation_not_required'] ?? '') === '1';
        if (isset($_POST['engagement_version']) && is_scalar($_POST['engagement_version'])) {
            $engagement['updated_at'] = (string) $_POST['engagement_version'];
        }
    }
}

// Get presentations for this engagement
$presentations_query = "SELECT p.id, p.engagement_id, p.topic_title, p.presentation_date,
                               p.presentation_time, p.speaker_id, p.duration_minutes,
                               p.expected_attendance, p.actual_attendance,
                               (n.storage_key IS NOT NULL OR n.pdf IS NOT NULL) AS has_speaker_notes,
                               d.storage_key IS NOT NULL AS has_ppt_slidedeck,
                               d.filename AS ppt_slidedeck_filename,
                               d.size AS ppt_slidedeck_size,
                               d.updated_at AS ppt_slidedeck_updated_at,
                               COALESCE(deck_uploader.username, d.uploaded_by_username_snapshot) AS ppt_slidedeck_uploaded_by_username,
                               n.filename AS speaker_notes_filename,
                               n.size AS speaker_notes_size,
                               n.updated_at AS speaker_notes_updated_at,
                               COALESCE(uploader.username, n.uploaded_by_username_snapshot) AS speaker_notes_uploaded_by_username,
                               p.speaker_notes_qr_image IS NOT NULL AS has_speaker_notes_qr,
                               p.speaker_notes_qr_updated_at,
                               p.speaker_website_qr_image IS NOT NULL AS has_speaker_website_qr,
                               p.speaker_website_qr_updated_at,
                               p.speaker_donation_qr_image IS NOT NULL AS has_speaker_donation_qr,
                               p.speaker_donation_qr_updated_at
                        FROM presentations p
                        LEFT JOIN presentation_notes n ON n.presentation_id = p.id AND n.speaker_id = p.speaker_id
                        LEFT JOIN users uploader ON uploader.id = n.uploaded_by
                        LEFT JOIN presentation_slidedecks d ON d.presentation_id = p.id AND d.speaker_id = p.speaker_id
                        LEFT JOIN users deck_uploader ON deck_uploader.id = d.uploaded_by
                        WHERE p.engagement_id = ? AND p.is_archived = 0
                        ORDER BY p.presentation_date, p.presentation_time, p.id";
$stmt = $conn->prepare($presentations_query);
$stmt->bind_param("i", $engagement_id);
$stmt->execute();
$presentations_result = $stmt->get_result();
$presentations = [];
while ($row = $presentations_result->fetch_assoc()) {
    $presentations[] = $row;
}

$engagement_contact_role_options = engagementContactRoles();
$selected_engagement_organization_id = (int) ($engagement['organization_id'] ?? 0);
$organization_search = \Dnr\Http\RequestInput::string($_GET, 'organization_search', '', 128);
require_once __DIR__ . '/organization_options_helpers.php';
$engagement_organization_options = boundedOrganizationOptions(
    $conn,
    [$selected_engagement_organization_id],
    $organization_search
);
try {
    $organization_contacts = fetchOrganizationContactOptions(
        $conn,
        $selected_engagement_organization_id,
        $engagement_id
    );
    $engagement_added_contacts = fetchEngagementAddedContactOptions(
        $conn,
        $submitted_engagement_added_contact_ids
    );
    $organization_contacts = array_values(array_filter($organization_contacts,
        static fn(array $contact): bool => !in_array((int) $contact['id'], $submitted_engagement_added_contact_ids, true)));
    $displayed_engagement_contact_assignments = $submitted_engagement_contacts !== null
        && !empty($error_message)
        ? $submitted_engagement_contacts
        : fetchEngagementContactAssignments($conn, $engagement_id);
    $engagement_contact_assignment_map = engagementContactAssignmentMap(
        $displayed_engagement_contact_assignments
    );
    $reschedule_candidates = fetchEngagementRescheduleCandidates(
        $conn,
        $selected_engagement_organization_id,
        $engagement_id
    );
} catch (Throwable $exception) {
    abortApplication(503, 'The engagement contacts are temporarily unavailable.', [
        'engagement_id' => $engagement_id,
        'error' => $exception->getMessage(),
    ]);
}
$engagement_new_contact_rows = engagementNewContactFormRows($_POST['engagement_new_contacts'] ?? null);
$engagement_lifecycle_options = engagementLifecycleStatuses();
$engagement_confirmation_statuses = \Dnr\Domain\ReferenceData::engagementStatuses();
$selected_lifecycle_status = (string) ($engagement['lifecycle_status'] ?? 'active');
$selected_confirmation_status = (string) (
    $engagement['confirmation_status'] ?? 'work_in_progress'
);
$selected_cancellation_reason = (string) ($engagement['cancellation_reason'] ?? '');
$selected_rescheduled_to_engagement_id = (int) (
    $engagement['rescheduled_to_engagement_id'] ?? 0
);
$current_engagement_id = $engagement_id;

$chron_action_message = $_SESSION['chron_action_message'] ?? '';
$chron_action_error = $_SESSION['chron_action_error'] ?? '';
$presentation_action_message = $_SESSION['presentation_action_message'] ?? '';
$presentation_action_error = $_SESSION['presentation_action_error'] ?? '';
unset(
    $_SESSION['chron_action_message'],
    $_SESSION['chron_action_error'],
    $_SESSION['presentation_action_message'],
    $_SESSION['presentation_action_error']
);
try {
    $chron_page_size = paginationPageSizePreference('edit_engagement_chron', $_GET['chron_per_page'] ?? null, 20);
    $chron_entry_count = countActiveChronLogEntries($conn, $engagement_id);
    $chron_total_pages = max(1, (int) ceil($chron_entry_count / $chron_page_size));
    $chron_page = min(
        (\Dnr\Http\RequestInput::positiveInt($_GET, 'chron_page') ?? 1),
        $chron_total_pages
    );
    $chron_entries = fetchChronLogEntries(
        $conn,
        $engagement_id,
        false,
        $chron_page_size,
        ($chron_page - 1) * $chron_page_size
    );
    $archived_chron_count = countArchivedChronLogEntries($conn, $engagement_id);
    $archived_presentation_count = countArchivedEngagementPresentations($conn, $engagement_id);
} catch (Throwable $exception) {
    http_response_code(503);
    exit('The engagement details are temporarily unavailable while ' . applicationBrandName() . ' is being upgraded.');
}
require_once __DIR__ . '/document_scanning_helpers.php';
$document_scan_messages = documentScanMessages($conn, (int) $engagement_id);
$submitted_chron_versions = is_array($_POST['chron_entry_versions'] ?? null)
    ? $_POST['chron_entry_versions']
    : [];
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Edit Engagement'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/edit_engagement.min.css',
    3 => 'assets/css/pages/engagement_contacts.min.css',
    4 => 'assets/css/pages/engagement_lifecycle.min.css',
    5 => 'assets/css/pages/engagement_form.min.css',
  ),
  'scripts' => ['assets/js/relationship-search.min.js'],
)); ?>
<body class="edit-engagement-body">
<?php renderScript('assets/js/edit-engagement.min.js', false); ?>
<?php include 'templates/header.php'; ?>
<div class="container edit-engagement-page" role="main">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="engagements.php">Engagements</a><span aria-hidden="true">/</span><span>Edit Engagement</span></nav>
    <div class="page-heading form-page-heading edit-engagement-heading"><div><h1>Edit Engagement</h1><p class="page-intro">Update event details, schedule, presentations, and logistics.</p></div></div>
    <?php include __DIR__ . '/templates/organization_search_fallback.php'; ?>
    <?php if (!empty($error_message)): ?>
        <?php echo formErrorSummary($error_message); ?>
    <?php endif; ?>
    <?php if ($chron_action_message !== ''): ?>
        <div class="success"><?php echo htmlspecialchars($chron_action_message); ?></div>
    <?php endif; ?>
    <?php if ($chron_action_error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($chron_action_error); ?></div>
    <?php endif; ?>
    <form method="post" action="edit_engagement.php?id=<?php echo (int) $engagement_id; ?>" class="engagement-form" id="engagement-edit-form" data-recoverable-draft="edit_engagement.php:<?php echo (int)$engagement_id; ?>" data-chunk-engagement="<?php echo (int) $engagement_id; ?>" enctype="multipart/form-data">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($record_edit_return, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="engagement_version" value="<?php echo htmlspecialchars((string) $engagement['updated_at'], ENT_QUOTES, 'UTF-8'); ?>">
        <?php $edit_baseline = $_POST['edit_baseline'] ?? ''; ?>
        <input type="hidden" name="edit_baseline" value="<?php echo is_string($edit_baseline) && preg_match('/\A[a-f0-9]{64}\z/', $edit_baseline) ? $edit_baseline : ''; ?>">
        <?php foreach ($chron_entries as $chron_entry): ?>
            <?php
            $submitted_chron_version = $submitted_chron_versions[(string) $chron_entry['id']] ?? null;
            $chron_entry_version = is_scalar($submitted_chron_version)
                ? (string) $submitted_chron_version
                : (string) $chron_entry['updated_at'];
            ?>
            <input type="hidden" name="chron_entry_versions[<?php echo (int) $chron_entry['id']; ?>]" value="<?php echo htmlspecialchars($chron_entry_version); ?>">
        <?php endforeach; ?>
        <div class="engagement-form-meta">
            <?php renderFormDraftPanel(true); ?>
            <p class="required-fields-note"><span aria-hidden="true">*</span>Required Field</p>
        </div>
        <?php
        $engagement_form_editing = true;
        $engagement_form_values = $engagement;
        include 'templates/engagement_lifecycle_form.php';
        unset($engagement_form_editing, $engagement_form_values);
        ?>
        <div class="engagement-section-row engagement-primary-row">
            <section class="form-section engagement-details-section">
                <h2>Event Details</h2>
                <div class="organization-container">
                    <label for="organization_id">Organization</label>
                    <input type="search" placeholder="Find an Organization…" aria-label="Find an Organization for Organization" data-relationship-search-input>
                    <select name="organization_id" id="organization_id" data-organization-search required>
                        <?php foreach ($engagement_organization_options as $organization_option): ?>
                            <option value="<?php echo (int) $organization_option['id']; ?>"<?php echo (int) $organization_option['id'] === $selected_engagement_organization_id ? ' selected' : ''; ?><?php echo !empty($organization_option['is_deleted']) ? ' disabled' : ''; ?>><?php echo htmlspecialchars((string) $organization_option['organization_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="field-help" role="status" data-relationship-search-status></p>
                </div>

                <label for="event_title" class="required"><span aria-hidden="true">*</span>Event Title</label>
                <input type="text" name="event_title" id="event_title" maxlength="255" required value="<?php echo htmlspecialchars($engagement['event_title'] ?? ''); ?>">

                <label for="event_description">Event Description</label>
                <textarea name="event_description" id="event_description" rows="11"><?php echo htmlspecialchars($engagement['event_description'] ?? ''); ?></textarea>

            </section>

            <div class="engagement-contact-column">
                <?php
                $engagement_contact_show_caller = true;
                $engagement_contact_edit_caller_id = (int) ($engagement['caller_user_id'] ?? 0);
                include 'templates/engagement_contact_form.php';
                unset($engagement_contact_show_caller, $engagement_contact_edit_caller_id);
                ?>
            </div>
        </div>

        <div class="engagement-section-row">
            <section class="form-section engagement-logistics-section">
                <div class="engagement-logistics-heading">
                    <h2>Logistics and Compensation</h2>
                    <div class="checkbox-group">
                        <label class="checkbox-label">
                            <input type="checkbox" name="book_table" <?php echo $engagement['book_table'] ? 'checked' : ''; ?>> Book Table Provided
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" name="brochures" <?php echo $engagement['brochures'] ? 'checked' : ''; ?>> Brochures Permitted
                        </label>
                    </div>
                    <fieldset class="radio-row"><legend>All Travel Covered</legend>
                        <div class="radio-options">
                            <?php
                            $travel_covered = $engagement['travel_covered'] ?? 'unknown';
                            $options = \Dnr\Domain\ReferenceData::travelCoverage();
                            foreach ($options as $option) {
                                $checked = ($travel_covered === $option) ? 'checked' : '';
                                echo "<label><input type='radio' name='travel_covered' value='{$option}' {$checked}> " . ucfirst($option) . "</label>";
                            }
                            ?>
                        </div>
                    </fieldset>
                </div>

                <div class="compensation-grid">
                    <div class="compensation-type-row">
                        <div class="form-field">
                            <div class="field-group">
                                <label for="compensation_type">Type of Compensation</label>
                                <select name="compensation_type" id="compensation_type" class="narrow-select">
                                    <?php
                                    $valid_compensation_types = \Dnr\Domain\ReferenceData::compensationTypes();
                                    $selected_comp = $engagement['compensation_type'] ?? 'Unknown';
                                    foreach ($valid_compensation_types as $type) {
                                        $selected = ($selected_comp === $type) ? 'selected' : '';
                                        echo "<option value='" . htmlspecialchars($type) . "' {$selected}>" . htmlspecialchars($type) . "</option>";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>

                        <div class="form-field" id="other_compensation_div" <?php echo $engagement['compensation_type'] === 'Other' ? '' : 'hidden'; ?>>
                            <div class="field-group">
                                <label for="other_compensation" class="required"><span aria-hidden="true">*</span>Describe Other Compensation</label>
                                <input type="text" name="other_compensation" id="other_compensation" value="<?php echo htmlspecialchars($engagement['other_compensation'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <div class="amount-row">
                        <div class="form-field">
                            <label for="travel_amount">Travel (Not in Compensation)</label>
                            <div class="currency-input">
                                <span>$</span>
                                <input type="number" id="travel_amount" name="travel_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($engagement['travel_amount'] ?? ''); ?>">
                            </div>
                        </div>
                        <div class="form-field">
                            <label for="housing_amount">Lodging (Not in Travel)</label>
                            <div class="currency-input">
                                <span>$</span>
                                <input type="number" id="housing_amount" name="housing_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($engagement['housing_amount'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="compensation-type-row">
                    <div class="form-field">
                        <div class="field-group">
                            <label for="housing_type">Lodging Type</label>
                            <select name="housing_type" id="housing_type" class="narrow-select">
                                <?php
                                $housing_types = \Dnr\Domain\ReferenceData::housingTypes();
                                $selected_housing = $engagement['housing_type'] ?? 'Unknown';
                                foreach ($housing_types as $type) {
                                    $selected = ($selected_housing === $type) ? 'selected' : '';
                                    echo "<option value='{$type}' {$selected}>{$type}</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-field" id="other_housing_div" <?php echo $engagement['housing_type'] === 'Other' ? '' : 'hidden'; ?>>
                        <div class="field-group">
                            <label for="other_housing" class="required"><span aria-hidden="true">*</span>Describe Other Lodging</label>
                            <input type="text" name="other_housing" id="other_housing" value="<?php echo htmlspecialchars($engagement['other_housing'] ?? ''); ?>">
                        </div>
                    </div>
                </div>
            </section>

            <section class="form-section engagement-location-section">
                <h2>Event Location</h2>
                <div id="organization-address-option" hidden>
                    <button type="button" class="button-secondary" id="use-organization-address" aria-describedby="organization-address-status">Use Organization Address</button>
                    <p class="field-help" id="organization-address-status" role="status"></p>
                </div>
                <?php include 'templates/organization_address_confirmation.php'; ?>
                <div class="address-fields">
                    <div class="event-address-lines">
                        <div class="form-field">
                            <label for="event_address_line_1">Address Line 1</label>
                            <input type="text" name="event_address_line_1" id="event_address_line_1" value="<?php echo htmlspecialchars($engagement['event_address_line_1'] ?? ''); ?>">
                        </div>
                        <div class="form-field">
                            <label for="event_address_line_2">Address Line 2</label>
                            <input type="text" name="event_address_line_2" id="event_address_line_2" value="<?php echo htmlspecialchars($engagement['event_address_line_2'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="event-address-row">
                        <div class="form-field event-address-city-field">
                            <label for="event_city">City</label>
                            <input type="text" name="event_city" id="event_city" value="<?php echo htmlspecialchars($engagement['event_city'] ?? ''); ?>">
                        </div>
                        <div class="form-field event-address-state-field">
                            <label for="event_state">State</label>
                            <div data-address-region-control data-address-region-for="event">
                                <input type="text" name="event_state" id="event_state" value="<?php echo htmlspecialchars($engagement['event_state'] ?? ''); ?>" data-address-region-input>
                            </div>
                        </div>
                    </div>
                    <div class="event-address-row event-address-postal-row">
                        <div class="form-field event-address-zipcode-field">
                            <label for="event_zipcode">Zipcode</label>
                            <input type="text" name="event_zipcode" id="event_zipcode" value="<?php echo htmlspecialchars($engagement['event_zipcode'] ?? ''); ?>">
                        </div>
                        <div class="form-field event-address-country-field">
                            <span id="event-country-label">Country</span>
                            <?php echo addressCountryPicker(
                                'event_country',
                                $engagement['event_country'] ?: applicationDefaultCountry(),
                                'event'
                            ); ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <?php
        $presentation_not_required = (bool) $engagement['presentation_not_required'];
        $presentation_form_rows = !$presentation_not_required && !empty($error_message) && is_array($_POST['presentations'] ?? null)
            ? mergeStoredPresentationAssetMetadata($_POST['presentations'], $presentations)
            : $presentations;
        include 'templates/presentation_form.php';
        ?>

    </form>

    <?php foreach ($presentations as $presentation_management_row): ?>
        <?php $presentation_management_id = (int) $presentation_management_row['id']; ?>
        <?php if (canArchiveEntries($user_role)): ?>
            <form id="archive-presentation-<?php echo $presentation_management_id; ?>" method="post" action="edit_engagement.php?id=<?php echo $engagement_id; ?>#presentations-container" hidden>
                <?php echo csrfInput(); ?>
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($record_edit_return, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="presentation_id" value="<?php echo $presentation_management_id; ?>">
                <input type="hidden" name="action" value="archive">
            </form>
        <?php endif; ?>
        <?php if (canDeleteEntries($user_role)): ?>
            <form id="delete-presentation-<?php echo $presentation_management_id; ?>" method="post" action="edit_engagement.php?id=<?php echo $engagement_id; ?>#presentations-container"
                  data-delete-confirmation="Permanently delete this presentation?"
                  data-archive-action="archive" hidden>
                <?php echo csrfInput(); ?>
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($record_edit_return, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="presentation_id" value="<?php echo $presentation_management_id; ?>">
                <input type="hidden" name="action" value="delete">
            </form>
        <?php endif; ?>
    <?php endforeach; ?>

    <section class="form-section chron-log-section" id="chron-log">
        <div class="chron-log-heading">
            <div>
                <h2>Chron Log</h2>
                <p>Entries are shown newest first. Archived entries are removed from this page.</p>
            </div>
            <?php if ($archived_chron_count > 0): ?>
                <a href="restore_chron_entries.php?engagement_id=<?php echo $engagement_id; ?>" class="restore-button">Restore Archived Entries (<?php echo $archived_chron_count; ?>)</a>
            <?php endif; ?>
        </div>

        <div class="chron-add-form">
            <label for="new-chron-entry">New Chron Log Entry</label>
            <textarea name="new_chron_entry" id="new-chron-entry" rows="6" maxlength="100000" form="engagement-edit-form" placeholder="Add scheduling notes, important information, or reminders."><?php echo htmlspecialchars($_POST['new_chron_entry'] ?? ''); ?></textarea>
            <button type="submit" name="save_and_add_chron" value="1" class="save-button" form="engagement-edit-form" data-add-chron-entry>Add Chron Log Entry</button>
        </div>

        <?php renderPagination($chron_entry_count, $chron_page, $chron_page_size, recordUrlWithQuery('edit_engagement.php?id=' . $engagement_id, ['return_to' => $record_edit_return]) . '#chron-log', 'entries', 'Chron log pages', 'chron_page', 'chron_per_page'); ?>
        <div class="chron-entry-list">
            <?php
$submitted_chron_values = is_array($_POST['chron_entries'] ?? null)
    ? $_POST['chron_entries']
    : [];
            ?>
            <?php foreach ($chron_entries as $chron_entry): ?>
                <?php
                $created_timestamp = chronLogTimestampDetails($chron_entry['created_at']);
                $updated_timestamp = chronLogTimestampDetails($chron_entry['updated_at']);
                $entry_author = $chron_entry['created_by_username']
                    ?: (!empty($chron_entry['legacy_engagement_note']) ? 'Migrated legacy note' : 'System');
                $was_edited = (string) $chron_entry['updated_at'] !== (string) $chron_entry['created_at'];
                $submitted_chron_value = $submitted_chron_values[(string) $chron_entry['id']] ?? null;
                $chron_entry_value = is_scalar($submitted_chron_value)
                    ? (string) $submitted_chron_value
                    : (string) $chron_entry['entry_text'];
                ?>
                <article class="chron-entry-card">
                    <div class="chron-entry-meta">
                        <div>
                            <time datetime="<?php echo htmlspecialchars($created_timestamp['iso']); ?>"><?php echo htmlspecialchars($created_timestamp['display']); ?></time>
                            <span>by <?php echo htmlspecialchars($entry_author); ?></span>
                        </div>
                        <?php if ($was_edited): ?>
                            <small>Last updated <time datetime="<?php echo htmlspecialchars($updated_timestamp['iso']); ?>"><?php echo htmlspecialchars($updated_timestamp['display']); ?></time><?php if (!empty($chron_entry['updated_by_username'])): ?> by <?php echo htmlspecialchars($chron_entry['updated_by_username']); ?><?php endif; ?></small>
                        <?php endif; ?>
                    </div>
                    <div class="chron-entry-editor">
                        <label class="visually-hidden" for="chron-entry-<?php echo (int) $chron_entry['id']; ?>">Edit Chron Log Entry From <?php echo htmlspecialchars($created_timestamp['display']); ?></label>
                        <textarea name="chron_entries[<?php echo (int) $chron_entry['id']; ?>]" id="chron-entry-<?php echo (int) $chron_entry['id']; ?>" rows="6" maxlength="100000" required form="engagement-edit-form"><?php echo htmlspecialchars($chron_entry_value); ?></textarea>
                        <form method="post" action="edit_engagement.php?id=<?php echo $engagement_id; ?>#chron-log" class="chron-entry-management">
                            <?php echo csrfInput(); ?>
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($record_edit_return, ENT_QUOTES, 'UTF-8'); ?>">
                            <input type="hidden" name="chron_entry_id" value="<?php echo (int) $chron_entry['id']; ?>">
                            <div class="chron-entry-actions">
                                <button type="submit" name="chron_action" value="archive" class="archive-button">Archive</button>
                                <?php if ($user_role === 'admin'): ?>
                                    <button type="submit" name="chron_action" value="delete" class="delete-button" data-admin-unlock-required data-confirm="Permanently delete this Chron entry? This cannot be undone.">Delete</button>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </article>
            <?php endforeach; ?>
            <?php if (!$chron_entries): ?>
                <p class="chron-empty-state">No Chron entries have been added yet.</p>
            <?php endif; ?>
        </div>
        <?php renderPagination($chron_entry_count, $chron_page, $chron_page_size, recordUrlWithQuery('edit_engagement.php?id=' . $engagement_id, ['return_to' => $record_edit_return]) . '#chron-log', 'entries', 'Chron log pages', 'chron_page', 'chron_per_page'); ?>
    </section>

</div>

<script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>" type="application/json" id="address-region-data"><?php echo json_encode(
    addressRegionClientData(),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
); ?></script>
<?php
$hide_app_footer = true;
include 'templates/footer.php';
?>

<?php renderScript('assets/js/presentation-form.min.js', false); ?>
<?php renderScript('assets/js/presentation-upload.min.js', false); ?>
<?php renderScript('assets/js/engagement-contacts.min.js', false); ?>
<?php renderScript('assets/js/engagement-lifecycle.min.js', false); ?>

</body>
</html>
