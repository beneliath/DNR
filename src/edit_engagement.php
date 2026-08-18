<?php
include 'config.php';
include 'functions.php';
include 'chron_log_helpers.php';
include 'presentation_helpers.php';
startSecureSession();
requireLogin();

// Check if user has appropriate role
$user_role = $_SESSION['role'] ?? '';
if (!in_array($user_role, ['admin', 'editor'])) {
    header("Location: index.php");
    exit();
}

// Check if ID is provided and valid
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: engagements.php");
    exit();
}

$engagement_id = intval($_GET['id']);

// Get engagement data
$query = "SELECT e.*, o.organization_name
          FROM engagements e
          LEFT JOIN organizations o ON e.organization_id = o.id
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
$DEFAULT_SPEAKER = getenv('DEFAULT_SPEAKER') ? getenv('DEFAULT_SPEAKER') : 'Unknown Speaker';

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

    $conn->begin_transaction();
    try {
        $engagement_lock_stmt = $conn->prepare(
            'SELECT confirmation_status FROM engagements WHERE id = ? FOR UPDATE'
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
                $active_count
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
               SET confirmation_status = 'under_review', updated_at = CURRENT_TIMESTAMP
               WHERE id = ?"
            : 'UPDATE engagements SET updated_at = CURRENT_TIMESTAMP WHERE id = ?';
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
    error_log("Processing form submission for engagement ID: " . $engagement_id);

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

        // Get organization data
        $organization_id = intval($_POST['organization_id'] ?? 0);
        requireActiveOrganization($conn, $organization_id, true);

        // Continue with existing engagement update code
        $event_title = trim($_POST['event_title'] ?? '');
        $event_description = trim($_POST['event_description'] ?? '');
        $new_chron_entry = trim((string) ($_POST['new_chron_entry'] ?? ''));
        $event_start_date = $_POST['event_start_date'] ?? null;
        $event_end_date = $_POST['event_end_date'] ?? null;
        $event_type_raw = $_POST['event_type'] ?? '';
        $event_type_other = trim($_POST['event_type_other'] ?? '');
        [$event_type, $event_type_other] = normalizeEventType($event_type_raw, $event_type_other);
        $book_table = isset($_POST['book_table']) ? 1 : 0;
        $brochures = isset($_POST['brochures']) ? 1 : 0;
        $caller_name = trim($_POST['caller_name'] ?? '');
        $confirmation_status = $_POST['confirmation_status'] ?? 'work_in_progress';

        // Validate confirmation status
        $valid_statuses = ['work_in_progress', 'under_review', 'confirmed'];
        if (!in_array($confirmation_status, $valid_statuses)) {
            $confirmation_status = 'work_in_progress';
        }

        // Ensure travel_covered has a valid ENUM value
        $valid_travel_covered = ['unknown', 'yes', 'no'];
        $travel_covered = isset($_POST['travel_covered']) ? $_POST['travel_covered'] : $engagement['travel_covered'];
        if (!in_array($travel_covered, $valid_travel_covered)) {
            $travel_covered = 'unknown';
        }
        error_log("Using travel_covered value: " . $travel_covered);

        $travel_amount = nullableNonNegativeAmount($_POST['travel_amount'] ?? '', 'travel');

        // Strict validation for compensation_type
        $valid_compensation_types = ['Unknown', 'Honorarium', 'Offering', 'Honorarium and Offering', 'Other'];
        $submitted_compensation_type = $_POST['compensation_type'] ?? 'Unknown';
        $compensation_type = in_array($submitted_compensation_type, $valid_compensation_types, true) ? $submitted_compensation_type : 'Unknown';

        $other_compensation = trim($_POST['other_compensation'] ?? '');
        $housing_type = $_POST['housing_type'] ?? 'Unknown';
        $valid_housing_types = ['Unknown', 'Provided', 'Not Provided', 'Other'];
        if (!in_array($housing_type, $valid_housing_types, true)) {
            $housing_type = 'Unknown';
        }
        $other_housing = trim($_POST['other_housing'] ?? '');
        $housing_amount = nullableNonNegativeAmount($_POST['housing_amount'] ?? '', 'lodging');

        // Event location fields
        $event_address_line_1 = trim($_POST['event_address_line_1'] ?? '');
        $event_address_line_2 = trim($_POST['event_address_line_2'] ?? '');
        $event_city = trim($_POST['event_city'] ?? '');
        $event_state = trim($_POST['event_state'] ?? '');
        $event_zipcode = trim($_POST['event_zipcode'] ?? '');
        $event_country = trim($_POST['event_country'] ?? '');

        $presentations = normalizeEngagementPresentations(
            $_POST['presentations'] ?? null,
            $event_start_date,
            $event_end_date,
            $DEFAULT_SPEAKER,
            true
        );
        requirePresentationForConfirmedEngagement($confirmation_status, $presentations);
        requireValidDateRange($event_start_date, $event_end_date);

        $submitted_chron_entries = [];
        foreach ((array) ($_POST['chron_entries'] ?? []) as $submitted_entry_id => $submitted_entry_text) {
            if (!ctype_digit((string) $submitted_entry_id) || !is_scalar($submitted_entry_text)) {
                throw new InvalidArgumentException('Invalid Chron entry submission.');
            }
            $submitted_entry_text = trim((string) $submitted_entry_text);
            if ($submitted_entry_text === '') {
                throw new InvalidArgumentException('Chron entries cannot be empty.');
            }
            $submitted_chron_entries[(int) $submitted_entry_id] = $submitted_entry_text;
        }

        // Validate required fields
        if (
            ($compensation_type === 'Other' && empty($other_compensation)) ||
            ($housing_type === 'Other' && empty($other_housing)) ||
            (isset($_POST['save_and_add_chron']) && $new_chron_entry === '')
        ) {
            throw new InvalidArgumentException("Please provide valid required fields, dates, and non-negative amounts.");
        }

        // Update engagement
        $update_fields = [];
        $update_values = [];
        $types = '';

        // Only include fields that have changed
        if ($organization_id != $engagement['organization_id']) {
            $update_fields[] = "organization_id = ?";
            $update_values[] = $organization_id;
            $types .= "i";
        }

        if ($event_title !== ($engagement['event_title'] ?? '')) {
            $update_fields[] = "event_title = ?";
            $update_values[] = $event_title;
            $types .= "s";
        }

        if ($event_description !== ($engagement['event_description'] ?? '')) {
            $update_fields[] = "event_description = ?";
            $update_values[] = $event_description;
            $types .= "s";
        }

        if ($event_start_date !== $engagement['event_start_date']) {
            $update_fields[] = "event_start_date = ?";
            $update_values[] = $event_start_date;
            $types .= "s";
        }

        if ($event_end_date !== $engagement['event_end_date']) {
            $update_fields[] = "event_end_date = ?";
            $update_values[] = $event_end_date;
            $types .= "s";
        }

        if ($event_type !== $engagement['event_type']) {
            $update_fields[] = "event_type = ?";
            $update_values[] = $event_type;
            $types .= "s";
        }

        if (($event_type_other ?? '') !== ($engagement['event_type_other'] ?? '')) {
            $update_fields[] = "event_type_other = ?";
            $update_values[] = $event_type_other;
            $types .= "s";
        }

        if ($book_table != $engagement['book_table']) {
            $update_fields[] = "book_table = ?";
            $update_values[] = $book_table;
            $types .= "i";
        }

        if ($brochures != $engagement['brochures']) {
            $update_fields[] = "brochures = ?";
            $update_values[] = $brochures;
            $types .= "i";
        }

        if ($caller_name !== $engagement['caller_name']) {
            $update_fields[] = "caller_name = ?";
            $update_values[] = $caller_name;
            $types .= "s";
        }

        if ($confirmation_status !== $engagement['confirmation_status']) {
            $update_fields[] = "confirmation_status = ?";
            $update_values[] = $confirmation_status;
            $types .= "s";
        }

        if ($travel_covered !== $engagement['travel_covered']) {
            $update_fields[] = "travel_covered = ?";
            $update_values[] = $travel_covered;
            $types .= "s";
        }

        if (!nullableAmountsEqual($travel_amount, $engagement['travel_amount'])) {
            $update_fields[] = "travel_amount = ?";
            $update_values[] = $travel_amount;
            $types .= "d";
        }

        if ($compensation_type !== $engagement['compensation_type']) {
            $update_fields[] = "compensation_type = ?";
            $update_values[] = $compensation_type;
            $types .= "s";
        }

        if ($other_compensation !== $engagement['other_compensation']) {
            $update_fields[] = "other_compensation = ?";
            $update_values[] = $other_compensation;
            $types .= "s";
        }

        if ($housing_type !== $engagement['housing_type']) {
            $update_fields[] = "housing_type = ?";
            $update_values[] = $housing_type;
            $types .= "s";
        }

        if ($other_housing !== $engagement['other_housing']) {
            $update_fields[] = "other_housing = ?";
            $update_values[] = $other_housing;
            $types .= "s";
        }

        if (!nullableAmountsEqual($housing_amount, $engagement['housing_amount'])) {
            $update_fields[] = "housing_amount = ?";
            $update_values[] = $housing_amount;
            $types .= "d";
        }

        if ($event_address_line_1 !== $engagement['event_address_line_1']) {
            $update_fields[] = "event_address_line_1 = ?";
            $update_values[] = $event_address_line_1;
            $types .= "s";
        }

        if ($event_address_line_2 !== $engagement['event_address_line_2']) {
            $update_fields[] = "event_address_line_2 = ?";
            $update_values[] = $event_address_line_2;
            $types .= "s";
        }

        if ($event_city !== $engagement['event_city']) {
            $update_fields[] = "event_city = ?";
            $update_values[] = $event_city;
            $types .= "s";
        }

        if ($event_state !== $engagement['event_state']) {
            $update_fields[] = "event_state = ?";
            $update_values[] = $event_state;
            $types .= "s";
        }

        if ($event_zipcode !== $engagement['event_zipcode']) {
            $update_fields[] = "event_zipcode = ?";
            $update_values[] = $event_zipcode;
            $types .= "s";
        }

        if ($event_country !== $engagement['event_country']) {
            $update_fields[] = "event_country = ?";
            $update_values[] = $event_country;
            $types .= "s";
        }

        // Add engagement_id to the values array
        $update_values[] = $engagement_id;
        $types .= "i";

        if (!empty($update_fields)) {
            $update_query = "UPDATE engagements SET " . implode(", ", $update_fields) . " WHERE id = ?";
            $stmt = $conn->prepare($update_query);

            // Bind parameters dynamically
            $bind_params = array_merge([$types], $update_values);
            $stmt->bind_param(...$bind_params);

            if (!$stmt->execute()) {
                error_log("SQL Error: " . $stmt->error);
                throw new Exception("Failed to update engagement.");
            }
        }

        $presentations_changed = syncEngagementPresentations($conn, $engagement_id, $presentations);
        if ($presentations_changed) {
            $touch_engagement_stmt = $conn->prepare(
                'UPDATE engagements SET updated_at = CURRENT_TIMESTAMP WHERE id = ?'
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

        $current_user_id = (int) $_SESSION['user_id'];
        if ($submitted_chron_entries) {
            $current_chron_stmt = $conn->prepare(
                'SELECT id, entry_text
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
                $current_chron_entries[(int) $current_chron_entry['id']]
                    = (string) $current_chron_entry['entry_text'];
            }
            $current_chron_stmt->close();

            $chron_update_stmt = $conn->prepare(
                'UPDATE engagement_chron_entries
                 SET entry_text = ?, updated_by = ?, updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND engagement_id = ? AND is_archived = 0'
            );
            if (!$chron_update_stmt) {
                throw new RuntimeException('Unable to prepare the Chron entry changes.');
            }
            $updated_chron_text = '';
            $updated_chron_id = 0;
            $chron_update_stmt->bind_param(
                'siii',
                $updated_chron_text,
                $current_user_id,
                $updated_chron_id,
                $engagement_id
            );
            foreach ($submitted_chron_entries as $submitted_entry_id => $submitted_entry_text) {
                if (!array_key_exists($submitted_entry_id, $current_chron_entries)
                    || $submitted_entry_text === $current_chron_entries[$submitted_entry_id]) {
                    continue;
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

        // Commit transaction
        $conn->commit();
        $success_message = "Engagement updated successfully.";
        error_log("Engagement updated successfully for ID: " . $engagement_id);

        // Redirect to engagements listing
        header("Location: engagements.php");
        exit();

    } catch (Throwable $e) {
        $conn->rollback();
        error_log("Error updating engagement: " . $e->getMessage());
        $error_message = $e instanceof InvalidArgumentException
            ? $e->getMessage()
            : "Unable to update the engagement. Please try again.";

        $rehydrated_fields = [
            'organization_id', 'event_title', 'event_description', 'event_start_date', 'event_end_date',
            'caller_name', 'confirmation_status', 'travel_covered', 'travel_amount',
            'compensation_type', 'other_compensation', 'housing_type', 'other_housing',
            'housing_amount', 'event_address_line_1', 'event_address_line_2',
            'event_city', 'event_state', 'event_zipcode', 'event_country',
        ];
        foreach ($rehydrated_fields as $rehydrated_field) {
            if (array_key_exists($rehydrated_field, $_POST) && is_scalar($_POST[$rehydrated_field])) {
                $engagement[$rehydrated_field] = trim((string) $_POST[$rehydrated_field]);
            }
        }
        $engagement['event_type'] = in_array($event_type_raw ?? '', ['conference', 'service', 'study or teaching', 'Passover Seder', 'other'], true)
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
$presentations_query = "SELECT * FROM presentations
                        WHERE engagement_id = ? AND is_archived = 0
                        ORDER BY presentation_date,
                                 STR_TO_DATE(presentation_time, '%h:%i %p'), id";
$stmt = $conn->prepare($presentations_query);
$stmt->bind_param("i", $engagement_id);
$stmt->execute();
$presentations_result = $stmt->get_result();
$presentations = [];
while ($row = $presentations_result->fetch_assoc()) {
    $presentations[] = $row;
}

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
    $chron_entries = fetchChronLogEntries($conn, $engagement_id);
    $archived_chron_count = countArchivedChronLogEntries($conn, $engagement_id);
    $archived_presentation_count = countArchivedEngagementPresentations($conn, $engagement_id);
} catch (Throwable $exception) {
    http_response_code(503);
    exit('The engagement details are temporarily unavailable while DNR is being upgraded.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Engagement - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
</head>
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
    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $engagement_id); ?>" onsubmit="return validateDates();" class="engagement-form" id="engagement-edit-form">
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
                <select name="event_type" id="event_type" onchange="toggleOtherEventType(this)">
                    <?php
                    $event_types = ['conference', 'service', 'study or teaching', 'Passover Seder', 'other'];
                    foreach ($event_types as $type) {
                        $selected = ($engagement['event_type'] === $type) ? 'selected' : '';
                        echo "<option value='" . htmlspecialchars($type) . "' {$selected}>" . htmlspecialchars($type) . "</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="event-group" id="other_event_type_div" style="display: <?php echo $engagement['event_type'] === 'other' ? 'block' : 'none'; ?>">
                <label class="label-container" for="event_type_other">Other Event Type<span class="required">*</span></label>
                <input type="text" name="event_type_other" id="event_type_other" value="<?php echo htmlspecialchars($engagement['event_type_other'] ?? ''); ?>">
            </div>
        </div>

        <label for="event_description">Event Description</label>
        <textarea name="event_description" id="event_description" rows="5"><?php echo htmlspecialchars($engagement['event_description'] ?? ''); ?></textarea>
        </section>

        <?php
        $presentation_form_rows = !empty($error_message) && is_array($_POST['presentations'] ?? null)
            ? $_POST['presentations']
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
                    $options = ['unknown', 'yes', 'no'];
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
                        <select name="compensation_type" id="compensation_type" class="narrow-select" onchange="toggleOtherCompensation()">
                            <?php
                            $valid_compensation_types = ['Unknown', 'Honorarium', 'Offering', 'Honorarium and Offering', 'Other'];
                            $selected_comp = $engagement['compensation_type'] ?? 'Unknown';
                            foreach ($valid_compensation_types as $type) {
                                $selected = ($selected_comp === $type) ? 'selected' : '';
                                echo "<option value='" . htmlspecialchars($type) . "' {$selected}>" . htmlspecialchars($type) . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="form-field" id="other_compensation_div" style="display: <?php echo $engagement['compensation_type'] === 'Other' ? 'block' : 'none'; ?>">
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
                    <select name="housing_type" id="housing_type" class="narrow-select" onchange="toggleOtherHousing()">
                        <?php
                        $housing_types = ['Unknown', 'Provided', 'Not Provided', 'Other'];
                        $selected_housing = $engagement['housing_type'] ?? 'Unknown';
                        foreach ($housing_types as $type) {
                            $selected = ($selected_housing === $type) ? 'selected' : '';
                            echo "<option value='{$type}' {$selected}>{$type}</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div class="form-field" id="other_housing_div" style="display: <?php echo $engagement['housing_type'] === 'Other' ? 'block' : 'none'; ?>">
                <div class="field-group">
                    <label for="other_housing">Describe Other Lodging<span class="required">*</span></label>
                    <input type="text" name="other_housing" id="other_housing" value="<?php echo htmlspecialchars($engagement['other_housing'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <div class="address-section">
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
                <div class="address-row">
                    <div class="form-field">
                        <label for="event_city">City</label>
                        <input type="text" name="event_city" id="event_city" value="<?php echo htmlspecialchars($engagement['event_city'] ?? ''); ?>">
                    </div>
                    <div class="form-field">
                        <label for="event_state">State</label>
                        <input type="text" name="event_state" id="event_state" value="<?php echo htmlspecialchars($engagement['event_state'] ?? ''); ?>">
                    </div>
                    <div class="form-field">
                        <label for="event_zipcode">Zipcode</label>
                        <input type="text" name="event_zipcode" id="event_zipcode" value="<?php echo htmlspecialchars($engagement['event_zipcode'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-field">
                    <label for="event_country">Country</label>
                    <input type="text" name="event_country" id="event_country" value="<?php echo htmlspecialchars($engagement['event_country'] ?? ''); ?>">
                </div>
            </div>
        </div>
        </section>

        <div class="form-row">
            <div style="display: flex; gap: 20px;">
                <div class="form-field">
                    <label for="caller_name">Caller</label>
                    <select name="caller_name" id="caller_name">
                        <option value="" disabled>select a caller</option>
                        <?php
                        // Fetch and display users in the dropdown
                        $users = $conn->query("SELECT username FROM users ORDER BY username");
                        while ($row = $users->fetch_assoc()) {
                            $selected = ($engagement['caller_name'] === $row['username']) ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars($row['username']) . "' {$selected}>" . htmlspecialchars($row['username']) . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="confirmation_status" style="margin-right: 10px;">Status</label>
                    <select name="confirmation_status" id="confirmation_status" style="width: auto;">
                        <?php
                        $statuses = ['work_in_progress', 'under_review', 'confirmed'];
                        foreach ($statuses as $status) {
                            $selected = ($engagement['confirmation_status'] === $status) ? 'selected' : '';
                            echo "<option value='{$status}' {$selected}>" . str_replace('_', ' ', $status) . "</option>";
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
            <textarea name="new_chron_entry" id="new-chron-entry" rows="5" maxlength="100000" form="engagement-edit-form" placeholder="Add scheduling notes, important information, or reminders." oninput="this.setCustomValidity('');"><?php echo htmlspecialchars($_POST['new_chron_entry'] ?? ''); ?></textarea>
            <button type="submit" name="save_and_add_chron" value="1" class="save-button" form="engagement-edit-form" onclick="return validateNewChronEntry();">Add entry</button>
        </div>

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
                        <label class="visually-hidden" for="chron-entry-<?php echo (int) $chron_entry['id']; ?>">Edit Chron entry from <?php echo htmlspecialchars($created_timestamp['display']); ?></label>
                        <textarea name="chron_entries[<?php echo (int) $chron_entry['id']; ?>]" id="chron-entry-<?php echo (int) $chron_entry['id']; ?>" rows="5" maxlength="100000" required form="engagement-edit-form"><?php echo htmlspecialchars($chron_entry_value); ?></textarea>
                        <form method="post" action="edit_engagement.php?id=<?php echo $engagement_id; ?>#chron-log" class="chron-entry-management">
                            <?php echo csrfInput(); ?>
                            <input type="hidden" name="chron_entry_id" value="<?php echo (int) $chron_entry['id']; ?>">
                            <div class="chron-entry-actions">
                                <button type="submit" name="chron_action" value="archive" class="archive-button">Archive</button>
                                <?php if ($user_role === 'admin'): ?>
                                    <button type="submit" name="chron_action" value="delete" class="delete-button" onclick="return confirm('Permanently delete this Chron entry? This cannot be undone.');">Delete</button>
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
    </section>

    <div class="engagement-page-actions" aria-label="Engagement form actions">
        <a href="engagements.php" class="cancel-button">Cancel</a>
        <button type="submit" name="save_engagement" value="1" class="save-button" form="engagement-edit-form">Save Changes</button>
    </div>
</div>

<?php include 'templates/footer.php'; ?>

<script src="assets/js/presentation-form.min.js?v=0.1.4"></script>
<script>
    // Validate that the event end date is on or after the event start date
    function validateDates() {
        const startDate = document.getElementById("event_start_date").value;
        const endDate = document.getElementById("event_end_date").value;

        if (startDate && endDate && endDate < startDate) {
            alert("End date must be on or after the start date");
            return false;
        }
        return typeof validateEngagementPresentations !== 'function'
            || validateEngagementPresentations();
    }

    function validateNewChronEntry() {
        const entry = document.getElementById("new-chron-entry");
        if (!entry.value.trim()) {
            entry.setCustomValidity("Enter a Chron entry before adding it.");
            entry.reportValidity();
            entry.focus();
            return false;
        }
        entry.setCustomValidity("");
        return true;
    }

    // Toggle visibility of other event type field
    function toggleOtherEventType(select) {
        const otherDiv = document.getElementById("other_event_type_div");
        const otherInput = document.getElementById("event_type_other");

        if (select.value === "other") {
            otherDiv.style.display = "block";
            otherInput.required = true;
        } else {
            otherDiv.style.display = "none";
            otherInput.required = false;
            otherInput.value = "";
        }
    }

    function toggleOtherCompensation() {
        const select = document.getElementById("compensation_type");
        const otherDiv = document.getElementById("other_compensation_div");
        const otherInput = document.getElementById("other_compensation");

        if (select.value === "Other") {
            otherDiv.style.display = "block";
            otherInput.required = true;
        } else {
            otherDiv.style.display = "none";
            otherInput.required = false;
            otherInput.value = "";
        }
    }

    function toggleOtherHousing() {
        const housingType = document.getElementById('housing_type');
        const otherHousingDiv = document.getElementById('other_housing_div');
        const otherHousingInput = document.getElementById('other_housing');

        if (housingType.value === 'Other') {
            otherHousingDiv.style.display = 'block';
            otherHousingInput.required = true;
        } else {
            otherHousingDiv.style.display = 'none';
            otherHousingInput.required = false;
            otherHousingInput.value = '';
        }
    }
</script>

<style>
    .cancel-button {
        padding: 8px 15px;
        background-color: var(--button-cancel-color);
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        text-decoration: none;
        margin-left: 10px;
    }
    .save-button {
        background-color: var(--button-save-color);
    }
    /* Include all the existing styles from index.php for consistency */
    .event-row {
        display: flex;
        gap: 10px;
        position: relative;
        padding-top: 35px;
        margin-bottom: 8px;
    }
    .event-group {
        display: flex;
        flex-direction: column;
        gap: 8px;
        position: relative;
    }
    .event-title-group {
        flex: 1;
        min-width: 240px;
    }
    .label-container {
        position: absolute;
        top: -30px;
        color: var(--text-color);
        white-space: nowrap;
    }
    .required {
        color: #f44336;
        margin-left: 4px;
    }
    .event-group select,
    .event-group input[type="text"] {
        height: 35px;
        padding: 0 8px;
        background-color: #333;
        color: white;
        border: 1px solid #666;
        border-radius: 4px;
        box-sizing: border-box;
    }
    .checkbox-row {
        display: flex;
        align-items: center;
        margin: 15px 0;
    }
    .checkbox-group {
        display: flex;
        gap: 20px;
    }
    .checkbox-label {
        display: flex;
        align-items: center;
        gap: 5px;
        color: white;
    }
    .date-fields {
        display: flex;
        gap: 20px;
        margin: 15px 0;
    }
    .date-field {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .date-field input[type="date"] {
        padding: 8px;
        background-color: #333;
        color: white;
        border: 1px solid #666;
        border-radius: 4px;
    }
    textarea {
        background-color: #333;
        color: white;
        border: 1px solid #666;
        border-radius: 4px;
        padding: 8px;
        margin: 5px 0 15px;
    }
    select {
        background-color: #333;
        color: white;
        border: 1px solid #666;
        border-radius: 4px;
        padding: 8px;
    }
    .form-field {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .form-field label {
        white-space: nowrap;
        color: white;
    }
    .radio-row {
        display: flex;
        align-items: center;
        gap: 20px;
        margin-left: 30px;
    }
    .radio-options {
        display: flex;
        gap: 20px;
    }
    .radio-options label {
        display: flex;
        align-items: center;
        gap: 5px;
        color: white;
        cursor: pointer;
    }
    .compensation-grid {
        margin: 20px 0;
    }
    .compensation-type-row {
        display: flex;
        gap: 20px;
        margin-bottom: 15px;
    }
    .amount-row {
        display: flex;
        gap: 30px;
    }
    .currency-input {
        display: flex;
        align-items: center;
        gap: 5px;
    }
    .currency-input span {
        color: white;
    }
    .currency-input input {
        width: 100px;
        padding: 8px;
        background-color: #333;
        color: white;
        border: 1px solid #666;
        border-radius: 4px;
    }
    .address-section {
        margin: 20px 0;
    }
    .address-section h3 {
        color: white;
        margin-bottom: 15px;
    }
    .address-fields {
        display: flex;
        flex-direction: column;
        gap: 15px;
    }
    .address-row {
        display: flex;
        gap: 20px;
    }
    .address-row .form-field input {
        width: 150px;
    }
    .narrow-select {
        width: 200px;
    }
    input[type="text"] {
        background-color: #333;
        color: white;
        border: 1px solid #666;
        border-radius: 4px;
        padding: 8px;
        width: 100%;
    }
</style>

</body>
</html>
