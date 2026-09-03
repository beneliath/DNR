<?php
require_once __DIR__ . '/bootstrap.php';
include 'chron_log_helpers.php';
include 'presentation_helpers.php';
include 'map_helpers.php';
include 'two_factor_helpers.php';
include 'follow_up_task_helpers.php';
include 'engagement_contact_helpers.php';
include 'engagement_lifecycle_helpers.php';
startSecureSession();
requireLogin();

// Check if user has appropriate role
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['admin', 'editor'])) {
    header("Location: index.php");
    exit();
}

$engagement_id = \Dnr\Http\RequestInput::positiveInt($_GET, 'id');
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
$DEFAULT_SPEAKER = applicationDefaultSpeaker();
$submitted_engagement_contacts = null;

// Archive or permanently delete one saved presentation without submitting
// unrelated edits in the engagement form.
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['presentation_id'], $_POST['action'])) {
    requireValidCsrfToken();
    $presentation_id = filter_input(INPUT_POST, 'presentation_id', FILTER_VALIDATE_INT);
    $presentation_action = (string) $_POST['action'];

    if (!$presentation_id || !in_array($presentation_action, ['archive', 'delete'], true)) {
        $_SESSION['presentation_action_error'] = 'Select a valid presentation action.';
        header('Location: edit_engagement.php?id=' . $engagement_id . '#presentations-container');
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
            'SELECT confirmation_status, lifecycle_status
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
                $locked_engagement['lifecycle_status']
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

    header('Location: edit_engagement.php?id=' . $engagement_id . '#presentations-container');
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

    header('Location: edit_engagement.php?' . http_build_query(['id' => $engagement_id]) . '#chron-log');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['save_engagement']) || isset($_POST['save_and_add_chron']))) {
    requireValidCsrfToken();
    applicationLog('info', 'Processing engagement update', ['engagement_id' => $engagement_id]);

    // Start transaction
    $conn->begin_transaction();

    try {
        $engagement_lock_stmt = $conn->prepare(
            'SELECT * FROM engagements WHERE id = ? AND is_deleted = 0 FOR UPDATE'
        );
        if (!$engagement_lock_stmt) {
            throw new RuntimeException('Unable to lock the engagement for editing.');
        }
        $engagement_lock_stmt->bind_param('i', $engagement_id);
        $engagement_lock_stmt->execute();
        $locked_engagement = $engagement_lock_stmt->get_result()->fetch_assoc();
        $engagement_lock_stmt->close();
        if (!$locked_engagement) {
            throw new InvalidArgumentException('That engagement is no longer active.');
        }
        $submitted_version = trim((string) ($_POST['engagement_version'] ?? ''));
        if ($submitted_version === ''
            || !hash_equals((string) $locked_engagement['updated_at'], $submitted_version)
        ) {
            throw new InvalidArgumentException(
                'This engagement changed after you opened it. Reload the page before saving so newer changes are not overwritten.'
            );
        }
        $engagement = array_merge($engagement, $locked_engagement);

        $event_type_raw = is_scalar($_POST['event_type'] ?? null)
            ? trim((string) $_POST['event_type'])
            : '';
        $submitted_engagement_contacts = normalizeEngagementContactAssignments(
            $_POST['engagement_contacts'] ?? null
        );
        $engagement_input = \Dnr\Domain\EngagementInput::normalize($_POST);
        foreach ($engagement_input as $field => $value) {
            ${$field} = $value;
        }
        requireActiveOrganization($conn, $organization_id, true);
        validateEngagementContactAssignments(
            $conn,
            $organization_id,
            $submitted_engagement_contacts
        );
        validateEngagementLifecycleStatus($conn, $engagement_id, $lifecycle_status);
        validateEngagementRescheduleLink(
            $conn,
            $organization_id,
            $lifecycle_status,
            $rescheduled_to_engagement_id,
            $engagement_id
        );
        $caller = $caller_user_id !== null
            && $caller_user_id === (int) ($locked_engagement['caller_user_id'] ?? 0)
            ? [
                'id' => $caller_user_id,
                'username' => (string) ($locked_engagement['caller_name'] ?? ''),
            ]
            : \Dnr\Domain\EngagementInput::resolveCaller($conn, $caller_user_id);
        $caller_user_id = $caller['id'];
        $caller_name = $caller['username'];
        $engagement_input['caller_user_id'] = $caller_user_id;
        $engagement_input['caller_name'] = $caller_name;
        $new_chron_entry = trim((string) ($_POST['new_chron_entry'] ?? ''));

        $presentation_files = is_array($_FILES['presentations'] ?? null)
            ? $_FILES['presentations']
            : [];
        $presentation_asset_uploads = presentationAssetUploadMap($presentation_files);
        $presentations = normalizeEngagementPresentations(
            $_POST['presentations'] ?? null,
            $event_start_date,
            $event_end_date,
            $DEFAULT_SPEAKER,
            true,
            array_fill_keys(array_keys($presentation_asset_uploads), true)
        );
        $presentations = attachPresentationAssetChanges(
            $presentations,
            $_POST['presentations'] ?? null,
            $presentation_files
        );
        requirePresentationForConfirmedEngagement(
            $confirmation_status,
            $presentations,
            $lifecycle_status
        );

        $submitted_chron_entries = normalizeSubmittedChronLogEntries(
            $_POST['chron_entries'] ?? null
        );
        $submitted_chron_versions = normalizeSubmittedChronLogVersions(
            $_POST['chron_entry_versions'] ?? null
        );

        // Validate required fields
        if (
            ($compensation_type === 'Other' && empty($other_compensation)) ||
            ($housing_type === 'Other' && empty($other_housing)) ||
            (isset($_POST['save_and_add_chron']) && $new_chron_entry === '')
        ) {
            throw new InvalidArgumentException("Please provide valid required fields, dates, and non-negative amounts.");
        }

        $current_user_id = (int) $_SESSION['user_id'];
        $lifecycle_changed = $lifecycle_status
            !== (string) ($locked_engagement['lifecycle_status'] ?? 'active');
        $update_plan = \Dnr\Service\EngagementUpdatePlan::build($engagement, $engagement_input);
        $update_fields = $update_plan['assignments'];
        $update_values = $update_plan['values'];
        $types = $update_plan['types'];
        if ($lifecycle_changed) {
            $update_fields[] = 'lifecycle_changed_by = ?, lifecycle_changed_at = UTC_TIMESTAMP(6)';
            $update_values[] = $current_user_id;
            $types .= 'i';
        }

        $update_values[] = $engagement_id;
        $types .= "i";

        if (!empty($update_fields)) {
            $update_query = "UPDATE engagements SET " . implode(", ", $update_fields) . " WHERE id = ?";
            $stmt = $conn->prepare($update_query);

            // Bind parameters dynamically
            $bind_params = array_merge([$types], $update_values);
            $stmt->bind_param(...$bind_params);

            if (!$stmt->execute()) {
                applicationLog('error', 'Engagement update query failed', ['error' => $stmt->error]);
                throw new Exception("Failed to update engagement.");
            }
        }

        $engagement_dates_changed = $update_plan['date_range_changed'];
        if ($engagement_dates_changed) {
            rescheduleGeneratedEngagementTasks(
                $conn,
                $engagement_id,
                $event_start_date,
                $event_end_date
            );
        }

        $canceled_task_count = 0;
        if ($lifecycle_changed && $lifecycle_status === 'canceled') {
            $canceled_task_count = cancelEngagementFollowUpTasks($conn, $engagement_id);
        }

        $presentations_changed = syncEngagementPresentations($conn, $engagement_id, $presentations);
        if ($presentations_changed) {
            $touch_engagement_stmt = $conn->prepare(
                'UPDATE engagements SET updated_at = CURRENT_TIMESTAMP(6) WHERE id = ?'
            );
            if (!$touch_engagement_stmt) {
                throw new RuntimeException('Unable to update the engagement calendar timestamp.');
            }
            $touch_engagement_stmt->bind_param('i', $engagement_id);
            if (!$touch_engagement_stmt->execute()) {
                $touch_engagement_stmt->close();
                throw new RuntimeException('Unable to update the engagement calendar timestamp.');
            }
            $touch_engagement_stmt->close();
        }

        syncEngagementContacts(
            $conn,
            $engagement_id,
            $submitted_engagement_contacts,
            $current_user_id
        );
        if ($submitted_chron_entries) {
            $current_chron_stmt = $conn->prepare(
                'SELECT id, entry_text, updated_at
                 FROM engagement_chron_entries
                 WHERE engagement_id = ? AND is_archived = 0
                 FOR UPDATE'
            );
            if (!$current_chron_stmt) {
                throw new RuntimeException('Unable to prepare the Chron entry update.');
            }
            $current_chron_stmt->bind_param('i', $engagement_id);
            if (!$current_chron_stmt->execute()) {
                $current_chron_stmt->close();
                throw new RuntimeException('Unable to load the Chron entries for updating.');
            }
            $current_chron_entries = [];
            $current_chron_result = $current_chron_stmt->get_result();
            while ($current_chron_entry = $current_chron_result->fetch_assoc()) {
                $current_chron_entries[(int) $current_chron_entry['id']] = [
                    'entry_text' => (string) $current_chron_entry['entry_text'],
                    'updated_at' => (string) $current_chron_entry['updated_at'],
                ];
            }
            $current_chron_stmt->close();

            $chron_update_stmt = $conn->prepare(
                'UPDATE engagement_chron_entries
                 SET entry_text = ?, updated_by = ?, updated_at = UTC_TIMESTAMP(6)
                 WHERE id = ? AND engagement_id = ? AND is_archived = 0 AND updated_at = ?'
            );
            if (!$chron_update_stmt) {
                throw new RuntimeException('Unable to prepare the Chron entry changes.');
            }
            $updated_chron_text = '';
            $updated_chron_id = 0;
            $expected_chron_version = '';
            $chron_update_stmt->bind_param(
                'siiis',
                $updated_chron_text,
                $current_user_id,
                $updated_chron_id,
                $engagement_id,
                $expected_chron_version
            );
            foreach ($submitted_chron_entries as $submitted_entry_id => $submitted_entry_text) {
                if (!array_key_exists($submitted_entry_id, $current_chron_entries)
                    || $submitted_entry_text === $current_chron_entries[$submitted_entry_id]['entry_text']) {
                    continue;
                }
                $expected_chron_version = (string) (
                    $submitted_chron_versions[$submitted_entry_id] ?? ''
                );
                if ($expected_chron_version === ''
                    || !hash_equals(
                        $current_chron_entries[$submitted_entry_id]['updated_at'],
                        $expected_chron_version
                    )
                ) {
                    $chron_update_stmt->close();
                    throw new InvalidArgumentException(
                        'A Chron entry changed after you opened this page. Reload before saving so newer history is not overwritten.'
                    );
                }
                $updated_chron_id = $submitted_entry_id;
                $updated_chron_text = $submitted_entry_text;
                if (!$chron_update_stmt->execute() || $chron_update_stmt->affected_rows !== 1) {
                    $chron_update_stmt->close();
                    throw new RuntimeException('Unable to save the Chron entry changes.');
                }
            }
            $chron_update_stmt->close();
        }

        if ($new_chron_entry !== '') {
            $chron_stmt = $conn->prepare(
                'INSERT INTO engagement_chron_entries
                    (engagement_id, entry_text, created_by,
                     created_by_username_snapshot, updated_by)
                 VALUES (?, ?, ?, ?, ?)'
            );
            if (!$chron_stmt) {
                throw new RuntimeException('Unable to prepare the new Chron entry.');
            }
            $current_username = (string) $_SESSION['username'];
            $chron_stmt->bind_param(
                'isisi',
                $engagement_id,
                $new_chron_entry,
                $current_user_id,
                $current_username,
                $current_user_id
            );
            if (!$chron_stmt->execute()) {
                $chron_error = $chron_stmt->error;
                $chron_stmt->close();
                throw new RuntimeException('Unable to save the new Chron entry: ' . $chron_error);
            }
            $chron_stmt->close();
        }

        $map_address = engagementMapAddress([
            'event_address_line_1' => $event_address_line_1,
            'event_address_line_2' => $event_address_line_2,
            'event_city' => $event_city,
            'event_state' => $event_state,
            'event_zipcode' => $event_zipcode,
            'event_country' => $event_country,
        ]);
        if ($map_address !== ''
            && !queueEngagementMapAddress($conn, $map_address, true)
        ) {
            throw new RuntimeException('Unable to queue the engagement location.');
        }

        // Commit the engagement and its background lookup request atomically.
        $conn->commit();
        $success_message = 'Engagement updated successfully.';
        if ($canceled_task_count > 0) {
            $success_message .= ' ' . $canceled_task_count . ' open task'
                . ($canceled_task_count === 1 ? ' was' : 's were') . ' canceled.';
        }
        $_SESSION['engagement_action_message'] = $success_message;
        applicationLog('info', 'Engagement updated', ['engagement_id' => $engagement_id]);

        // Redirect to engagements listing
        header("Location: engagements.php");
        exit();

    } catch (Throwable $e) {
        $conn->rollback();
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
        $engagement['event_type'] = in_array($event_type_raw ?? '', \Dnr\Domain\ReferenceData::eventTypes(), true)
            ? $event_type_raw
            : ($engagement['event_type'] ?? 'conference');
        $engagement['event_type_other'] = trim((string) ($_POST['event_type_other'] ?? ''));
        $engagement['book_table'] = isset($_POST['book_table']) ? 1 : 0;
        $engagement['brochures'] = isset($_POST['brochures']) ? 1 : 0;
        if (isset($_POST['engagement_version']) && is_scalar($_POST['engagement_version'])) {
            $engagement['updated_at'] = (string) $_POST['engagement_version'];
        }
    }
}

// Get presentations for this engagement
$presentations_query = "SELECT id, engagement_id, topic_title, presentation_date,
                               presentation_time, speaker_name, duration_minutes,
                               expected_attendance, actual_attendance,
                               slide_deck_pdf IS NOT NULL AS has_slide_deck,
                               slide_deck_filename, slide_deck_size, slide_deck_updated_at,
                               speaker_notes_qr_image IS NOT NULL AS has_speaker_notes_qr,
                               speaker_notes_qr_updated_at,
                               speaker_website_qr_image IS NOT NULL AS has_speaker_website_qr,
                               speaker_website_qr_updated_at,
                               speaker_donation_qr_image IS NOT NULL AS has_speaker_donation_qr,
                               speaker_donation_qr_updated_at
                        FROM presentations
                        WHERE engagement_id = ? AND is_archived = 0
                        ORDER BY presentation_date, presentation_time, id";
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
try {
    $organization_contacts = fetchOrganizationContactOptions(
        $conn,
        $selected_engagement_organization_id
    );
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
    $chron_page_size = 20;
    $chron_entry_count = countActiveChronLogEntries($conn, $engagement_id);
    $chron_total_pages = max(1, (int) ceil($chron_entry_count / $chron_page_size));
    $chron_page = min(
        filter_input(INPUT_GET, 'chron_page', FILTER_VALIDATE_INT) ?: 1,
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
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="engagements.php">Engagements</a><span aria-hidden="true">/</span><span>Edit Engagement</span></nav>
    <div class="page-heading form-page-heading"><div><h1>Edit Engagement</h1><p class="page-intro">Update event details, schedule, presentations, and logistics.</p></div></div>
    <?php if (!empty($error_message)): ?>
        <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    <?php if ($chron_action_message !== ''): ?>
        <div class="success"><?php echo htmlspecialchars($chron_action_message); ?></div>
    <?php endif; ?>
    <?php if ($chron_action_error !== ''): ?>
        <div class="error"><?php echo htmlspecialchars($chron_action_error); ?></div>
    <?php endif; ?>
    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $engagement_id); ?>" class="engagement-form" id="engagement-edit-form" enctype="multipart/form-data">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="engagement_version" value="<?php echo htmlspecialchars((string) $engagement['updated_at'], ENT_QUOTES, 'UTF-8'); ?>">
        <p class="required-fields-note"><span aria-hidden="true">*</span> Required fields</p>
        <section class="form-section">
        <h2>Event Details &amp; Schedule</h2>
        <div class="organization-container">
            <label for="organization_id">Organization</label>
            <select name="organization_id" id="organization_id" required>
                <?php
                // Fetch and display organizations in the dropdown
                $orgs = $conn->query("SELECT id, organization_name FROM organizations WHERE is_deleted = 0 ORDER BY organization_name");
                while ($row = $orgs->fetch_assoc()) {
                    $selected = ($row['id'] == $engagement['organization_id']) ? 'selected' : '';
                    echo "<option value='" . htmlspecialchars($row['id']) . "' {$selected}>" . htmlspecialchars($row['organization_name']) . "</option>";
                }
                ?>
            </select>
        </div>

        <div class="date-fields">
            <div class="date-field">
                <label for="event_start_date">Start<span class="required">*</span></label>
                <input type="date" name="event_start_date" id="event_start_date" required value="<?php echo htmlspecialchars($engagement['event_start_date']); ?>">
            </div>
            <div class="date-field">
                <label for="event_end_date">End<span class="required">*</span></label>
                <input type="date" name="event_end_date" id="event_end_date" required value="<?php echo htmlspecialchars($engagement['event_end_date']); ?>">
            </div>
        </div>

        <div class="event-row">
            <div class="event-group event-title-group">
                <div class="label-container">Event Title</div>
                <input type="text" name="event_title" id="event_title" maxlength="255" value="<?php echo htmlspecialchars($engagement['event_title'] ?? ''); ?>">
            </div>

            <div class="event-group">
                <div class="label-container">Event Type</div>
                <select name="event_type" id="event_type">
                    <?php
                    $event_types = \Dnr\Domain\ReferenceData::eventTypes();
                    foreach ($event_types as $type) {
                        $selected = ($engagement['event_type'] === $type) ? 'selected' : '';
                        echo "<option value='" . htmlspecialchars($type) . "' {$selected}>" . htmlspecialchars($type) . "</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="event-group" id="other_event_type_div" <?php echo $engagement['event_type'] === 'other' ? '' : 'hidden'; ?>>
                <label class="label-container" for="event_type_other">Other Event Type<span class="required">*</span></label>
                <input type="text" name="event_type_other" id="event_type_other" value="<?php echo htmlspecialchars($engagement['event_type_other'] ?? ''); ?>">
            </div>
        </div>

        <label for="event_description">Event Description</label>
        <textarea name="event_description" id="event_description" rows="10"><?php echo htmlspecialchars($engagement['event_description'] ?? ''); ?></textarea>
        </section>

        <?php include 'templates/engagement_contact_form.php'; ?>

        <?php
        $presentation_form_rows = !empty($error_message) && is_array($_POST['presentations'] ?? null)
            ? mergeStoredPresentationAssetMetadata($_POST['presentations'], $presentations)
            : $presentations;
        include 'templates/presentation_form.php';
        ?>

        <section class="form-section">
        <h2>Logistics &amp; Compensation</h2>
        <div class="checkbox-row">
            <div class="checkbox-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="book_table" <?php echo $engagement['book_table'] ? 'checked' : ''; ?>> book table provided
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="brochures" <?php echo $engagement['brochures'] ? 'checked' : ''; ?>> brochures permitted
                </label>
            </div>
            <div class="radio-row">
                <label>All Travel Covered</label>
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
            </div>
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
                        <label for="other_compensation">Describe Other Compensation<span class="required">*</span></label>
                        <input type="text" name="other_compensation" id="other_compensation" value="<?php echo htmlspecialchars($engagement['other_compensation'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <div class="amount-row">
                <div class="form-field">
                    <label>Travel (Not in Compensation)</label>
                    <div class="currency-input">
                        <span>$</span>
                        <input type="number" name="travel_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($engagement['travel_amount'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-field">
                    <label>Lodging (Not in Travel)</label>
                    <div class="currency-input">
                        <span>$</span>
                        <input type="number" name="housing_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($engagement['housing_amount'] ?? ''); ?>">
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
                    <label for="other_housing">Describe Other Lodging<span class="required">*</span></label>
                    <input type="text" name="other_housing" id="other_housing" value="<?php echo htmlspecialchars($engagement['other_housing'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <div class="address-section is-saved-address-section">
            <h3>Event Location</h3>
            <div class="address-fields">
                <div class="form-field">
                    <label for="event_address_line_1">Address Line 1</label>
                    <input type="text" name="event_address_line_1" id="event_address_line_1" value="<?php echo htmlspecialchars($engagement['event_address_line_1'] ?? ''); ?>">
                </div>
                <div class="form-field">
                    <label for="event_address_line_2">Address Line 2</label>
                    <input type="text" name="event_address_line_2" id="event_address_line_2" value="<?php echo htmlspecialchars($engagement['event_address_line_2'] ?? ''); ?>">
                </div>
                <div class="address-row event-address-row">
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
                    <div class="form-field event-address-zipcode-field">
                        <label for="event_zipcode">Zipcode</label>
                        <input type="text" name="event_zipcode" id="event_zipcode" value="<?php echo htmlspecialchars($engagement['event_zipcode'] ?? ''); ?>">
                    </div>
                    <div class="form-field event-address-country-field">
                        <label>Country</label>
                        <?php echo addressCountryPicker(
                            'event_country',
                            $engagement['event_country'] ?: applicationDefaultCountry(),
                            'event'
                        ); ?>
                    </div>
                </div>
            </div>
        </div>
        </section>

        <?php include 'templates/engagement_lifecycle_form.php'; ?>

        <div class="form-row">
            <div class="engagement-inline-row">
                <div class="form-field">
                    <label for="caller_user_id">Caller</label>
                    <select name="caller_user_id" id="caller_user_id">
                        <option value="" <?php echo empty($engagement['caller_user_id']) ? 'selected' : ''; ?>>No caller selected</option>
                        <?php
                        // Fetch and display users in the dropdown
                        $current_caller_id = (int) ($engagement['caller_user_id'] ?? 0);
                        $users = $conn->query(
                            "SELECT id, username FROM users
                             WHERE account_status = 'active' OR id = {$current_caller_id}
                             ORDER BY username"
                        );
                        while ($row = $users->fetch_assoc()) {
                            $selected = (int) ($engagement['caller_user_id'] ?? 0) === (int) $row['id'] ? 'selected' : '';
                            echo "<option value='" . (int) $row['id'] . "' {$selected}>" . htmlspecialchars($row['username']) . "</option>";
                        }
                        ?>
                    </select>
                </div>

            </div>

        </div>
    </form>

    <?php foreach ($presentations as $presentation_management_row): ?>
        <?php $presentation_management_id = (int) $presentation_management_row['id']; ?>
        <?php if (canArchiveEntries($user_role)): ?>
            <form id="archive-presentation-<?php echo $presentation_management_id; ?>" method="post" action="edit_engagement.php?id=<?php echo $engagement_id; ?>#presentations-container" hidden>
                <?php echo csrfInput(); ?>
                <input type="hidden" name="presentation_id" value="<?php echo $presentation_management_id; ?>">
                <input type="hidden" name="action" value="archive">
            </form>
        <?php endif; ?>
        <?php if (canDeleteEntries($user_role)): ?>
            <form id="delete-presentation-<?php echo $presentation_management_id; ?>" method="post" action="edit_engagement.php?id=<?php echo $engagement_id; ?>#presentations-container"
                  data-delete-confirmation="Permanently delete this presentation?"
                  data-archive-action="archive" hidden>
                <?php echo csrfInput(); ?>
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
                <a href="restore_chron_entries.php?engagement_id=<?php echo $engagement_id; ?>" class="restore-button">Restore archived entries (<?php echo $archived_chron_count; ?>)</a>
            <?php endif; ?>
        </div>

        <div class="chron-add-form">
            <label for="new-chron-entry">New Chron entry</label>
            <textarea name="new_chron_entry" id="new-chron-entry" rows="6" maxlength="100000" form="engagement-edit-form" placeholder="Add scheduling notes, important information, or reminders."><?php echo htmlspecialchars($_POST['new_chron_entry'] ?? ''); ?></textarea>
            <button type="submit" name="save_and_add_chron" value="1" class="save-button" form="engagement-edit-form" data-add-chron-entry>Add entry</button>
        </div>

        <div class="chron-entry-list">
            <?php
$submitted_chron_values = is_array($_POST['chron_entries'] ?? null)
    ? $_POST['chron_entries']
    : [];
$submitted_chron_versions = is_array($_POST['chron_entry_versions'] ?? null)
    ? $_POST['chron_entry_versions']
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
                $submitted_chron_version = $submitted_chron_versions[(string) $chron_entry['id']] ?? null;
                $chron_entry_version = is_scalar($submitted_chron_version)
                    ? (string) $submitted_chron_version
                    : (string) $chron_entry['updated_at'];
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
                        <label class="visually-hidden" for="chron-entry-<?php echo (int) $chron_entry['id']; ?>">Edit Chron entry from <?php echo htmlspecialchars($created_timestamp['display']); ?></label>
                        <input type="hidden" name="chron_entry_versions[<?php echo (int) $chron_entry['id']; ?>]" value="<?php echo htmlspecialchars($chron_entry_version); ?>" form="engagement-edit-form">
                        <textarea name="chron_entries[<?php echo (int) $chron_entry['id']; ?>]" id="chron-entry-<?php echo (int) $chron_entry['id']; ?>" rows="6" maxlength="100000" required form="engagement-edit-form"><?php echo htmlspecialchars($chron_entry_value); ?></textarea>
                        <form method="post" action="edit_engagement.php?id=<?php echo $engagement_id; ?>#chron-log" class="chron-entry-management">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="chron_entry_id" value="<?php echo (int) $chron_entry['id']; ?>">
                            <div class="chron-entry-actions">
                                <button type="submit" name="chron_action" value="archive" class="archive-button">Archive</button>
                                <?php if ($user_role === 'admin'): ?>
                                    <button type="submit" name="chron_action" value="delete" class="delete-button" data-confirm="Permanently delete this Chron entry? This cannot be undone.">Delete</button>
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
        <?php if ($chron_total_pages > 1): ?>
            <nav class="pagination" aria-label="Chron log pages">
                <span>Page <?php echo $chron_page; ?> of <?php echo $chron_total_pages; ?> · <?php echo $chron_entry_count; ?> entries</span>
                <div class="pagination-actions">
                    <?php if ($chron_page > 1): ?><a href="edit_engagement.php?id=<?php echo $engagement_id; ?>&amp;chron_page=<?php echo $chron_page - 1; ?>#chron-log">Newer</a><?php endif; ?>
                    <?php if ($chron_page < $chron_total_pages): ?><a href="edit_engagement.php?id=<?php echo $engagement_id; ?>&amp;chron_page=<?php echo $chron_page + 1; ?>#chron-log">Older</a><?php endif; ?>
                </div>
            </nav>
        <?php endif; ?>
    </section>

    <div class="engagement-page-actions" aria-label="Engagement form actions">
        <a href="engagements.php" class="cancel-button">Cancel</a>
        <button type="submit" name="save_engagement" value="1" class="save-button" form="engagement-edit-form">Save Changes</button>
    </div>
</div>

<script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>" type="application/json" id="address-region-data"><?php echo json_encode(
    addressRegionClientData(),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
); ?></script>
<?php include 'templates/footer.php'; ?>

<?php renderScript('assets/js/presentation-form.min.js', false); ?>
<?php renderScript('assets/js/engagement-contacts.min.js', false); ?>
<?php renderScript('assets/js/engagement-lifecycle.min.js', false); ?>

</body>
</html>
