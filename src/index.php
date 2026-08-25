<?php
// This file serves as the main dashboard for managing speaking engagements.
// It handles form submissions for adding engagements and displays the dashboard interface.

require_once __DIR__ . '/bootstrap.php';
include 'presentation_helpers.php';
include 'map_helpers.php';
include 'follow_up_task_helpers.php';
include 'engagement_contact_helpers.php';
include 'engagement_lifecycle_helpers.php';
startSecureSession();
requireLogin();
requireFollowUpTaskSchema($conn);

// The bare application URL is the daily dashboard; index.php remains the add form.
$request_method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (in_array($request_method, ['GET', 'HEAD'], true) && isApplicationRootRequest($_SERVER)) {
    header('Location: dashboard.php');
    exit();
}

// Reviewers are read-only; only admins and editors may create engagements.
if (!hasRole(['admin', 'editor'])) {
    header("Location: engagements.php");
    exit();
}

// Get default speaker name from environment variable
$DEFAULT_SPEAKER = getenv('DEFAULT_SPEAKER') ? getenv('DEFAULT_SPEAKER') : 'Unknown Speaker';

// Handle form submission for adding a new engagement
$success_message = '';
$error_message = '';
$submitted_engagement_contacts = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_engagement'])) {
    requireValidCsrfToken();

    $chron_entry = trim($_POST['chron_entry'] ?? '');

    try {
        $submitted_engagement_contacts = normalizeEngagementContactAssignments(
            $_POST['engagement_contacts'] ?? null
        );
        $engagement_input = \Dnr\Domain\EngagementInput::normalize($_POST);
        foreach ($engagement_input as $field => $value) {
            ${$field} = $value;
        }
        $caller = \Dnr\Domain\EngagementInput::resolveCaller($conn, $caller_user_id);
        $caller_user_id = $caller['id'];
        $caller_name = $caller['username'];
        $presentation_files = is_array($_FILES['presentations'] ?? null)
            ? $_FILES['presentations']
            : [];
        $presentation_asset_uploads = presentationAssetUploadMap($presentation_files);
        $presentations = normalizeEngagementPresentations(
            $_POST['presentations'] ?? null,
            $event_start_date,
            $event_end_date,
            $DEFAULT_SPEAKER,
            false,
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
    } catch (Throwable $exception) {
        $presentations = [];
        if (!$exception instanceof InvalidArgumentException) {
            applicationLog('error', 'Engagement input validation failed unexpectedly', [
                'error' => $exception->getMessage(),
            ]);
        }
        $error_message = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Unable to validate the engagement. Please try again.';
    }

    if (empty($error_message)) {
        // Start transaction
        $conn->begin_transaction();

        try {
            requireActiveOrganization($conn, $organization_id, true);
            validateEngagementContactAssignments(
                $conn,
                $organization_id,
                $submitted_engagement_contacts
            );
            validateEngagementRescheduleLink(
                $conn,
                $organization_id,
                $lifecycle_status,
                $rescheduled_to_engagement_id
            );
            $current_user_id = (int) $_SESSION['user_id'];
            // Insert engagement data
            $stmt = $conn->prepare("INSERT INTO engagements (
                organization_id, event_title, event_description, event_start_date, event_end_date,
                event_type, event_type_other, book_table, brochures, caller_name, caller_user_id,
                confirmation_status, lifecycle_status, cancellation_reason,
                rescheduled_to_engagement_id, lifecycle_changed_by,
                event_address_line_1, event_address_line_2, event_city, event_state,
                event_zipcode, event_country,
                travel_covered, travel_amount, compensation_type, other_compensation,
                housing_type, other_housing, housing_amount
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            if ($stmt) {
                $stmt->bind_param(
                    "issssssiisisssiisssssssdssssd",
                    $organization_id,
                    $event_title,
                    $event_description,
                    $event_start_date,
                    $event_end_date,
                    $event_type,
                    $event_type_other,
                    $book_table,
                    $brochures,
                    $caller_name,
                    $caller_user_id,
                    $confirmation_status,
                    $lifecycle_status,
                    $cancellation_reason,
                    $rescheduled_to_engagement_id,
                    $current_user_id,
                    $event_address_line_1,
                    $event_address_line_2,
                    $event_city,
                    $event_state,
                    $event_zipcode,
                    $event_country,
                    $travel_covered,
                    $travel_amount,
                    $compensation_type,
                    $other_compensation,
                    $housing_type,
                    $other_housing,
                    $housing_amount
                );

                if ($stmt->execute()) {
                    $engagement_id = $conn->insert_id;

                    syncEngagementContacts(
                        $conn,
                        $engagement_id,
                        $submitted_engagement_contacts,
                        $current_user_id,
                        false
                    );

                    syncEngagementPresentations($conn, $engagement_id, $presentations);

                    if ($chron_entry !== '') {
                        $chron_stmt = $conn->prepare(
                            "INSERT INTO engagement_chron_entries
                                (engagement_id, entry_text, created_by,
                                 created_by_username_snapshot, updated_by)
                             VALUES (?, ?, ?, ?, ?)"
                        );
                        if (!$chron_stmt) {
                            throw new Exception("Unable to prepare the initial Chron entry.");
                        }
                        $current_username = (string) $_SESSION['username'];
                        $chron_stmt->bind_param(
                            "isisi",
                            $engagement_id,
                            $chron_entry,
                            $current_user_id,
                            $current_username,
                            $current_user_id
                        );
                        if (!$chron_stmt->execute()) {
                            throw new Exception("Unable to save the initial Chron entry: " . $chron_stmt->error);
                        }
                        $chron_stmt->close();
                    }

                    $standard_task_count = $lifecycle_status === 'active'
                        ? generateEngagementFollowUpChecklist(
                            $conn,
                            $engagement_id,
                            $current_user_id,
                            $current_user_id,
                            false
                        )
                        : 0;

                    $map_address = engagementMapAddress([
                        'event_address_line_1' => $event_address_line_1,
                        'event_address_line_2' => $event_address_line_2,
                        'event_city' => $event_city,
                        'event_state' => $event_state,
                        'event_zipcode' => $event_zipcode,
                        'event_country' => $event_country,
                    ]);
                    if ($map_address !== '' && !queueEngagementMapAddress($conn, $map_address)) {
                        throw new RuntimeException('Unable to queue the engagement location.');
                    }

                    $conn->commit();
                    $_SESSION['engagement_action_message'] = 'Engagement saved successfully.'
                        . ($standard_task_count > 0
                            ? ' ' . $standard_task_count . ' standard task'
                                . ($standard_task_count === 1 ? ' was' : 's were')
                                . ' added and assigned to you.'
                            : '');
                    header('Location: engagements.php');
                    exit();
                } else {
                    throw new Exception("Unable to save engagement: " . $stmt->error);
                }

                $stmt->close();
            } else {
                throw new Exception("Unable to prepare engagement insert: " . $conn->error);
            }
        } catch (Throwable $e) {
            $conn->rollback();
            applicationLog('error', 'Engagement creation failed', ['error' => $e->getMessage()]);
            $error_message = $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : "Unable to save the engagement. Please try again.";
        }
    }
}

$engagement_contact_role_options = engagementContactRoles();
$selected_engagement_organization_id = !empty($error_message)
    ? (int) ($_POST['organization_id'] ?? 0)
    : 0;
try {
    $organization_contacts = fetchOrganizationContactOptions(
        $conn,
        $selected_engagement_organization_id
    );
} catch (Throwable $exception) {
    abortApplication(503, 'Organization contacts are temporarily unavailable.', [
        'error' => $exception->getMessage(),
    ]);
}
$engagement_contact_assignment_map = engagementContactAssignmentMap(
    $submitted_engagement_contacts
);
$engagement_lifecycle_options = engagementLifecycleStatuses();
$engagement_confirmation_statuses = \Dnr\Domain\ReferenceData::engagementStatuses();
$selected_lifecycle_status = !empty($error_message)
    ? (string) ($_POST['lifecycle_status'] ?? 'active')
    : 'active';
$selected_confirmation_status = !empty($error_message)
    ? (string) ($_POST['confirmation_status'] ?? 'work_in_progress')
    : 'work_in_progress';
$selected_cancellation_reason = !empty($error_message)
    ? (string) ($_POST['cancellation_reason'] ?? '')
    : '';
$selected_rescheduled_to_engagement_id = !empty($error_message)
    ? (int) ($_POST['rescheduled_to_engagement_id'] ?? 0)
    : 0;
$current_engagement_id = 0;
try {
    $reschedule_candidates = fetchEngagementRescheduleCandidates(
        $conn,
        $selected_engagement_organization_id
    );
} catch (Throwable $exception) {
    abortApplication(503, 'Rescheduled-event options are temporarily unavailable.', [
        'error' => $exception->getMessage(),
    ]);
}
?>

<!DOCTYPE html>
<!-- HTML structure for the dashboard interface -->
<html lang="en">
<?php renderPageHead('DNR dashboard', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/index.min.css',
    3 => 'assets/css/pages/engagement_contacts.min.css',
    4 => 'assets/css/pages/engagement_lifecycle.min.css',
  ),
  'scripts' =>
  array (
    0 =>
    array (
      'path' => 'assets/js/main.min.js',
    ),
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<!-- Main container for the dashboard content -->
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="engagements.php">Engagements</a><span aria-hidden="true">/</span><span>New Engagement</span></nav>
    <div class="page-heading form-page-heading">
        <div>
            <h1>New Engagement</h1>
            <p class="page-intro">Create the event, schedule, presentations, and logistics in one place.</p>
        </div>
    </div>
    <!-- Form for adding a new speaking engagement -->
    <?php if (!empty($error_message)): ?>
        <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
        <div class="success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <form method="post" action="index.php" class="engagement-form" enctype="multipart/form-data">
        <?php echo csrfInput(); ?>
        <p class="required-fields-note"><span aria-hidden="true">*</span> Required fields</p>
        <section class="form-section">
        <h2>Event Details</h2>
<div class="organization-container">
    <label for="organization_id">Organization</label>
    <select name="organization_id" id="organization_id" required>
        <option value="" disabled <?php echo empty($_POST['organization_id']) || !empty($success_message) ? 'selected' : ''; ?>>select an organization</option>
        <?php
        // Fetch and display organizations in the dropdown
        $orgs = $conn->query("SELECT id, organization_name FROM organizations WHERE is_deleted = 0 ORDER BY organization_name");
        while ($row = $orgs->fetch_assoc()) {
            $selected = !empty($error_message) && isset($_POST['organization_id']) && $_POST['organization_id'] == $row['id'] ? 'selected' : '';
            echo "<option value='" . htmlspecialchars($row['id']) . "' {$selected}>" . htmlspecialchars($row['organization_name']) . "</option>";
        }
        ?>
    </select>
    <a href="add_organization.php" class="add-org-button">Add New Organization</a>
</div>

        <label for="event_title">Event Title</label>
        <input type="text" name="event_title" id="event_title" maxlength="255" value="<?php echo !empty($error_message) ? htmlspecialchars($_POST['event_title'] ?? '') : ''; ?>">

        <label for="event_description">Event Description</label>
        <textarea name="event_description" id="event_description" rows="5"><?php echo !empty($error_message) ? htmlspecialchars($_POST['event_description'] ?? '') : ''; ?></textarea>

        </section>

        <?php include 'templates/engagement_contact_form.php'; ?>

        <section class="form-section">
        <h2>Schedule</h2>

        <div class="date-fields">
            <div class="date-field">
                <label for="event_start_date">Start<span class="required">*</span></label>
                <input type="date" name="event_start_date" id="event_start_date" required value="<?php echo !empty($error_message) ? htmlspecialchars($_POST['event_start_date'] ?? '') : ''; ?>">
            </div>
            <div class="date-field">
                <label for="event_end_date">End<span class="required">*</span></label>
                <input type="date" name="event_end_date" id="event_end_date" required value="<?php echo !empty($error_message) ? htmlspecialchars($_POST['event_end_date'] ?? '') : ''; ?>">
            </div>
        </div>
        <br>

        <!-- Event type fields -->
        <div class="event-row">
            <div class="event-group">
                <div class="label-container">Event Type</div>
                <select name="event_type" id="event_type">
                    <?php
                    $event_types = \Dnr\Domain\ReferenceData::eventTypes();
                    $selected_type = $_POST['event_type'] ?? '';
                    foreach ($event_types as $type) {
                        $selected = ($selected_type === $type) ? 'selected' : '';
                        echo "<option value='" . htmlspecialchars($type) . "' {$selected}>" . htmlspecialchars($type) . "</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="event-group" id="other_event_type_div" <?php echo isset($_POST['event_type']) && $_POST['event_type'] === 'other' ? '' : 'hidden'; ?>>
                <label class="label-container" for="event_type_other">Other Event Type<span class="required">*</span></label>
                <input type="text" name="event_type_other" id="event_type_other" value="<?php echo htmlspecialchars($_POST['event_type_other'] ?? ''); ?>">
            </div>
        </div>
        </section>



        <?php
        $presentation_form_rows = !empty($error_message) && is_array($_POST['presentations'] ?? null)
            ? $_POST['presentations']
            : [[]];
        include 'templates/presentation_form.php';
        ?>

        <section class="form-section">
        <h2>Logistics &amp; Compensation</h2>

        <div class="checkbox-row">
            <div class="checkbox-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="book_table" <?php echo isset($_POST['book_table']) ? 'checked' : ''; ?>> book table provided
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="brochures" <?php echo isset($_POST['brochures']) ? 'checked' : ''; ?>> brochures permitted
                </label>
            </div>
            <div class="radio-row">
                <label>All Travel Covered</label>
                <div class="radio-options">
                    <?php
                    $travel_covered = $_POST['travel_covered'] ?? 'unknown';
                    $options = \Dnr\Domain\ReferenceData::travelCoverage();
                    foreach ($options as $option) {
                        $checked = ($travel_covered === $option) ? 'checked' : '';
                        echo "<label><input type='radio' name='travel_covered' value='{$option}' {$checked}> " . ucfirst($option) . "</label>";
                    }
                    ?>
                </div>
            </div>
        </div>
<br>
        <div class="compensation-grid">
            <div class="compensation-type-row">
                <div class="form-field">
                    <div class="field-group">
                        <label for="compensation_type">Type of Compensation</label>
                        <select name="compensation_type" id="compensation_type" class="narrow-select">
                            <?php
                            $comp_types = \Dnr\Domain\ReferenceData::compensationTypes();
                            $selected_comp = $_POST['compensation_type'] ?? 'Unknown';
                            foreach ($comp_types as $type) {
                                $selected = ($selected_comp === $type) ? 'selected' : '';
                                echo "<option value='{$type}' {$selected}>{$type}</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="form-field" id="other_compensation_div" <?php echo isset($_POST['compensation_type']) && $_POST['compensation_type'] === 'Other' ? '' : 'hidden'; ?>>
                    <div class="field-group">
                        <label for="other_compensation">Describe Other Compensation<span class="required">*</span></label>
                        <input type="text" name="other_compensation" id="other_compensation" value="<?php echo htmlspecialchars($_POST['other_compensation'] ?? ''); ?>">
                    </div>
                </div>
            </div>
<br>
            <div class="amount-row">
                <div class="form-field">
                    <label>Travel (Not in Compensation)</label>
                    <div class="currency-input">
                        <span>$</span>
                        <input type="number" name="travel_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($_POST['travel_amount'] ?? ''); ?>">
                    </div>
                </div>
                <div class="form-field">
                    <label>Lodging (Not in Travel)</label>
                    <div class="currency-input">
                        <span>$</span>
                        <input type="number" name="housing_amount" step="0.01" min="0" value="<?php echo htmlspecialchars($_POST['housing_amount'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>
<br>
        <div class="compensation-type-row">
            <div class="form-field">
                <div class="field-group">
                    <label for="housing_type">Lodging Type</label>
                    <select name="housing_type" id="housing_type" class="narrow-select">
                        <?php
                        $housing_types = \Dnr\Domain\ReferenceData::housingTypes();
                        $selected_housing = $_POST['housing_type'] ?? 'Unknown';
                        foreach ($housing_types as $type) {
                            $selected = ($selected_housing === $type) ? 'selected' : '';
                            echo "<option value='{$type}' {$selected}>{$type}</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div class="form-field" id="other_housing_div" <?php echo isset($_POST['housing_type']) && $_POST['housing_type'] === 'Other' ? '' : 'hidden'; ?>>
                <div class="field-group">
                    <label for="other_housing">Describe Other Lodging<span class="required">*</span></label>
                    <input type="text" name="other_housing" id="other_housing" value="<?php echo htmlspecialchars($_POST['other_housing'] ?? ''); ?>">
                </div>
            </div>
        </div>

        <div class="address-section">
            <h3>Event Location</h3>
            <div class="address-fields">
                <div class="form-field">
                    <label for="event_address_line_1">Address Line 1</label>
                    <input type="text" name="event_address_line_1" id="event_address_line_1" maxlength="255" value="<?php echo htmlspecialchars($_POST['event_address_line_1'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-field">
                    <label for="event_address_line_2">Address Line 2</label>
                    <input type="text" name="event_address_line_2" id="event_address_line_2" maxlength="255" value="<?php echo htmlspecialchars($_POST['event_address_line_2'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="address-row">
                    <div class="form-field">
                        <label for="event_city">City</label>
                        <input type="text" name="event_city" id="event_city" maxlength="100" value="<?php echo htmlspecialchars($_POST['event_city'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-field">
                        <label for="event_state">State</label>
                        <input type="text" name="event_state" id="event_state" maxlength="100" value="<?php echo htmlspecialchars($_POST['event_state'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="form-field">
                        <label for="event_zipcode">Zipcode</label>
                        <input type="text" name="event_zipcode" id="event_zipcode" maxlength="20" value="<?php echo htmlspecialchars($_POST['event_zipcode'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                </div>
                <div class="form-field">
                    <label for="event_country">Country</label>
                    <input type="text" name="event_country" id="event_country" maxlength="100" value="<?php echo htmlspecialchars($_POST['event_country'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>
        </div>
        </section>

        <?php include 'templates/engagement_lifecycle_form.php'; ?>

        <section class="form-section chron-log-section" id="chron-log">
            <h2>Chron Log</h2>
            <p class="field-help">Add an optional first entry. The system will timestamp it when the engagement is created.</p>
            <label for="chron_entry">Initial Chron entry</label>
            <textarea name="chron_entry" id="chron_entry" rows="6" maxlength="100000" placeholder="Add scheduling notes, important information, or reminders."><?php echo !empty($error_message) ? htmlspecialchars($_POST['chron_entry'] ?? '') : ''; ?></textarea>
        </section>

        <div class="form-row">
            <div class="engagement-inline-row">
                <div class="form-field">
                    <label for="caller_user_id">Caller</label>
                    <select name="caller_user_id" id="caller_user_id">
                        <option value="" <?php echo empty($_POST['caller_user_id']) ? 'selected' : ''; ?>>No caller selected</option>
                        <?php
                        // Fetch and display users in the dropdown
                        $users = $conn->query(
                            "SELECT id, username FROM users WHERE account_status = 'active' ORDER BY username"
                        );
                        while ($row = $users->fetch_assoc()) {
                            $selected = (int) ($_POST['caller_user_id'] ?? 0) === (int) $row['id'] ? 'selected' : '';
                            echo "<option value='" . (int) $row['id'] . "' {$selected}>" . htmlspecialchars($row['username']) . "</option>";
                        }
                        ?>
                    </select>
                </div>

            </div>

            <div class="form-group create-form-actions create-form-actions-flush">
                <a href="engagements.php" class="cancel-button">Cancel</a>
                <input type="submit" name="save_engagement" value="Create engagement" class="save-button save-button-flush">
            </div>
        </div>
    </form>
</div>
<?php include 'templates/footer.php'; ?>

<?php renderScript('assets/js/presentation-form.min.js', false); ?>
<?php renderScript('assets/js/engagement-contacts.min.js', false); ?>
<?php renderScript('assets/js/engagement-lifecycle.min.js', false); ?>

</body>
</html>
