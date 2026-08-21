<?php
// This file serves as the main dashboard for managing speaking engagements.
// It handles form submissions for adding engagements and displays the dashboard interface.

include 'config.php';
include 'functions.php';
include 'presentation_helpers.php';
include 'map_helpers.php';
startSecureSession();
requireLogin();

// The bare application URL is the engagement list; index.php remains the add form.
$request_method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
if (in_array($request_method, ['GET', 'HEAD'], true) && isApplicationRootRequest($_SERVER)) {
    header('Location: engagements.php');
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_engagement'])) {
    requireValidCsrfToken();

    $organization_id = intval($_POST['organization_id'] ?? 0);
    $event_title = trim($_POST['event_title'] ?? '');
    $event_description = trim($_POST['event_description'] ?? '');
    $chron_entry = trim($_POST['chron_entry'] ?? '');
    $event_start_date = $_POST['event_start_date'] ?? null;
    $event_end_date = $_POST['event_end_date'] ?? null;
    $event_type_raw = $_POST['event_type'] ?? '';
    $event_type_other = trim($_POST['event_type_other'] ?? '');
    $event_type = '';
    $book_table = isset($_POST['book_table']) ? 1 : 0;
    $brochures = isset($_POST['brochures']) ? 1 : 0;
    $caller_name = trim($_POST['caller_name'] ?? '');
    $confirmation_status = $_POST['confirmation_status'] ?? 'work_in_progress';
    $travel_covered = $_POST['travel_covered'] ?? 'unknown';
    $travel_amount = null;
    $compensation_type = $_POST['compensation_type'] ?? 'Unknown';
    $other_compensation = trim($_POST['other_compensation'] ?? '');
    $housing_type = $_POST['housing_type'] ?? 'Unknown';
    $other_housing = trim($_POST['other_housing'] ?? '');
    $housing_amount = null;
    $event_address_line_1 = trim($_POST['event_address_line_1'] ?? '');
    $event_address_line_2 = trim($_POST['event_address_line_2'] ?? '');
    $event_city = trim($_POST['event_city'] ?? '');
    $event_state = trim($_POST['event_state'] ?? '');
    $event_zipcode = trim($_POST['event_zipcode'] ?? '');
    $event_country = trim($_POST['event_country'] ?? '');

    $valid_statuses = ['work_in_progress', 'under_review', 'confirmed'];
    $valid_travel_values = ['unknown', 'yes', 'no'];
    $valid_compensation_types = ['Unknown', 'Honorarium', 'Offering', 'Honorarium and Offering', 'Other'];
    $valid_housing_types = ['Unknown', 'Provided', 'Not Provided', 'Other'];

    if (!in_array($confirmation_status, $valid_statuses, true)) {
        $confirmation_status = 'work_in_progress';
    }
    if (!in_array($travel_covered, $valid_travel_values, true)) {
        $travel_covered = 'unknown';
    }
    if (!in_array($compensation_type, $valid_compensation_types, true)) {
        $compensation_type = 'Unknown';
    }
    if (!in_array($housing_type, $valid_housing_types, true)) {
        $housing_type = 'Unknown';
    }

    try {
        requireValidDateRange($event_start_date, $event_end_date);
        [$event_type, $event_type_other] = normalizeEventType($event_type_raw, $event_type_other);
        $travel_amount = nullableNonNegativeAmount($_POST['travel_amount'] ?? '', 'travel');
        $housing_amount = nullableNonNegativeAmount($_POST['housing_amount'] ?? '', 'lodging');
        $presentations = normalizeEngagementPresentations(
            $_POST['presentations'] ?? null,
            $event_start_date,
            $event_end_date,
            $DEFAULT_SPEAKER
        );
        requirePresentationForConfirmedEngagement($confirmation_status, $presentations);
        if ($organization_id < 1) {
            throw new InvalidArgumentException('Please select an active organization.');
        }
        if ($compensation_type === 'Other' && $other_compensation === '') {
            throw new InvalidArgumentException('Describe the other compensation.');
        }
        if ($housing_type === 'Other' && $other_housing === '') {
            throw new InvalidArgumentException('Describe the other lodging arrangement.');
        }
    } catch (InvalidArgumentException $exception) {
        $presentations = [];
        $error_message = $exception->getMessage();
    }

    if (empty($error_message)) {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            requireActiveOrganization($conn, $organization_id, true);
            // Insert engagement data
            $stmt = $conn->prepare("INSERT INTO engagements (
                organization_id, event_title, event_description, event_start_date, event_end_date,
                event_type, event_type_other, book_table, brochures, caller_name, confirmation_status,
                event_address_line_1, event_address_line_2, event_city, event_state,
                event_zipcode, event_country,
                travel_covered, travel_amount, compensation_type, other_compensation,
                housing_type, other_housing, housing_amount
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

            if ($stmt) {
                $stmt->bind_param(
                    "issssssiisssssssssdssssd",
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
                    $confirmation_status,
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
                    
                    // Insert presentations
                    if (!empty($presentations)) {
                        $presentation_stmt = $conn->prepare("INSERT INTO presentations (
                            engagement_id, topic_title, presentation_date, presentation_time,
                            speaker_name, expected_attendance
                        ) VALUES (?, ?, ?, ?, ?, ?)");

                        if (!$presentation_stmt) {
                            throw new Exception("Unable to prepare presentations: " . $conn->error);
                        }

                        foreach ($presentations as $presentation) {
                            $presentation_stmt->bind_param(
                                "issssi",
                                $engagement_id,
                                $presentation['topic_title'],
                                $presentation['presentation_date'],
                                $presentation['presentation_time'],
                                $presentation['speaker_name'],
                                $presentation['expected_attendance']
                            );
                            
                            if (!$presentation_stmt->execute()) {
                                throw new Exception("Unable to save presentation: " . $presentation_stmt->error);
                            }
                        }
                        $presentation_stmt->close();
                    }

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
                        $current_user_id = (int) $_SESSION['user_id'];
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
                    
                    $conn->commit();
                    queueEngagementMapAddress($conn, engagementMapAddress([
                        'event_address_line_1' => $event_address_line_1,
                        'event_address_line_2' => $event_address_line_2,
                        'event_city' => $event_city,
                        'event_state' => $event_state,
                        'event_zipcode' => $event_zipcode,
                        'event_country' => $event_country,
                    ]));
                    $_SESSION['engagement_action_message'] = 'Engagement saved successfully.';
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
            error_log($e->getMessage());
            $error_message = $e instanceof InvalidArgumentException
                ? $e->getMessage()
                : "Unable to save the engagement. Please try again.";
        }
    }
}
?>

<!DOCTYPE html>
<!-- HTML structure for the dashboard interface -->
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>DNR dashboard</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
    <script src="assets/js/main.min.js"></script>
</head>
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

    <form method="post" action="index.php" class="engagement-form">
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
                    $event_types = ['conference', 'service', 'study or teaching', 'Passover Seder', 'other'];
                    $selected_type = $_POST['event_type'] ?? '';
                    foreach ($event_types as $type) {
                        $selected = ($selected_type === $type) ? 'selected' : '';
                        echo "<option value='" . htmlspecialchars($type) . "' {$selected}>" . htmlspecialchars($type) . "</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="event-group" id="other_event_type_div" style="display: <?php echo isset($_POST['event_type']) && $_POST['event_type'] === 'other' ? 'block' : 'none'; ?>">
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
                    $options = ['unknown', 'yes', 'no'];
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
                            $comp_types = ['Unknown', 'Honorarium', 'Offering', 'Honorarium and Offering', 'Other'];
                            $selected_comp = $_POST['compensation_type'] ?? 'Unknown';
                            foreach ($comp_types as $type) {
                                $selected = ($selected_comp === $type) ? 'selected' : '';
                                echo "<option value='{$type}' {$selected}>{$type}</option>";
                            }
                            ?>
                        </select>
                    </div>
                </div>

                <div class="form-field" id="other_compensation_div" style="display: <?php echo isset($_POST['compensation_type']) && $_POST['compensation_type'] === 'Other' ? 'block' : 'none'; ?>">
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
                        $housing_types = ['Unknown', 'Provided', 'Not Provided', 'Other'];
                        $selected_housing = $_POST['housing_type'] ?? 'Unknown';
                        foreach ($housing_types as $type) {
                            $selected = ($selected_housing === $type) ? 'selected' : '';
                            echo "<option value='{$type}' {$selected}>{$type}</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div class="form-field" id="other_housing_div" style="display: <?php echo isset($_POST['housing_type']) && $_POST['housing_type'] === 'Other' ? 'block' : 'none'; ?>">
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

        <section class="form-section chron-log-section" id="chron-log">
            <h2>Chron Log</h2>
            <p class="field-help">Add an optional first entry. The system will timestamp it when the engagement is created.</p>
            <label for="chron_entry">Initial Chron entry</label>
            <textarea name="chron_entry" id="chron_entry" rows="6" maxlength="100000" placeholder="Add scheduling notes, important information, or reminders."><?php echo !empty($error_message) ? htmlspecialchars($_POST['chron_entry'] ?? '') : ''; ?></textarea>
        </section>

        <div class="form-row">
            <div style="display: flex; gap: 20px;">
                <div class="form-field">
                    <label for="caller_name">Caller</label>
                    <select name="caller_name" id="caller_name">
                        <option value="" disabled <?php echo !isset($_POST['caller_name']) ? 'selected' : ''; ?>>select a caller</option>
                        <?php
                        // Fetch and display users in the dropdown
                        $users = $conn->query("SELECT username FROM users ORDER BY username");
                        while ($row = $users->fetch_assoc()) {
                            $selected = isset($_POST['caller_name']) && $_POST['caller_name'] === $row['username'] ? 'selected' : '';
                            echo "<option value='" . htmlspecialchars($row['username']) . "' {$selected}>" . htmlspecialchars($row['username']) . "</option>";
                        }
                        ?>
                    </select>
                </div>

                <div class="form-field">
                    <label for="confirmation_status">Status</label>
                    <select name="confirmation_status" id="confirmation_status">
                        <?php
                        $statuses = ['work_in_progress', 'under_review', 'confirmed'];
                        $selected_status = $_POST['confirmation_status'] ?? 'work_in_progress';
                        foreach ($statuses as $status) {
                            $selected = ($selected_status === $status) ? 'selected' : '';
                            echo "<option value='{$status}' {$selected}>" . str_replace('_', ' ', $status) . "</option>";
                        }
                        ?>
                    </select>
                </div>
            </div>

            <div class="form-group create-form-actions" style="padding-left: 0; margin-left: 0;">
                <a href="engagements.php" class="cancel-button">Cancel</a>
                <input type="submit" name="save_engagement" value="Create engagement" class="save-button" style="margin-left: 0;">
            </div>
        </div>
    </form>
</div>
<?php include 'templates/footer.php'; ?>

<script src="assets/js/presentation-form.min.js?v=0.1.4"></script>
<script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>">
    // Validate that the event end date is on or after the event start date
    // and that all presentation dates are within range
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
            otherInput.value = ""; // Clear the input when hidden
        }
    }

    // Toggle visibility of other compensation field
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
            otherInput.value = ""; // Clear the input when hidden
        }
    }

    // Toggle visibility of other housing field
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

    // Update the JavaScript to scroll to success message if present
    document.addEventListener('DOMContentLoaded', function() {
        const form = document.querySelector('.engagement-form');
        const eventType = document.getElementById('event_type');
        document.getElementById('compensation_type').addEventListener('change', toggleOtherCompensation);
        document.getElementById('housing_type').addEventListener('change', toggleOtherHousing);
        eventType.addEventListener('change', function () { toggleOtherEventType(eventType); });
        form.addEventListener('submit', function (event) {
            if (!validateDates()) event.preventDefault();
        });
        const successMessage = document.querySelector('.success');
        if (successMessage) {
            successMessage.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
</script>

<style>
    /* Reset and contain all event field styles in a single block */
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

    .label-container {
        position: absolute;
        top: -30px;
        color: var(--text-color);
        white-space: nowrap;
    }

    /* Remove the calculated left position for the second label */
    .event-group:nth-child(2) .label-container {
        left: 0;
    }

    .required {
        color: #f44336;
        margin-left: 4px;
    }

    /* Remove any pseudo-elements that might add asterisks */
    .required::before,
    .required::after {
        content: none;
    }

    .event-group select,
    .event-group input[type="text"] {
        height: 35px;
        padding: 0 8px;
        background-color: var(--bg-color);
        color: var(--text-color);
        border: 1px solid #ccc;
        border-radius: 4px;
        box-sizing: border-box;
    }

    .dark-mode .event-group select,
    .dark-mode .event-group input[type="text"] {
        background-color: var(--dark-input-bg);
        color: var(--dark-text-color);
        border-color: #333;
    }

    .event-group select {
        width: 20ch;
    }

    #other_event_type_div {
        display: none;
    }

    #other_event_type_div input[type="text"] {
        width: 300px;
    }

    .dark-mode #other_event_type_div input[type="text"] {
        background-color: var(--dark-input-bg);
        border-color: #666;
    }

    .checkbox-row {
        display: flex;
        align-items: center;
        margin-bottom: 15px;
    }

    .checkbox-label {
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .presentation-entry {
        border: 1px solid #ddd;
        padding: 15px;
        margin-bottom: 15px;
        border-radius: 4px;
    }

    .dark-mode .presentation-entry {
        border-color: #333;
    }

    .presentation-fields {
        display: grid;
        grid-template-columns: 1fr;
        gap: 15px;
    }

    .presentation-fields .form-field.topic {
        grid-column: 1 / -1;
    }

    .presentation-fields .datetime-row {
        display: grid;
        grid-template-columns: 0.5fr 1fr;
        gap: 15px;
    }

    .presentation-fields .datetime-row .form-field {
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        gap: 10px !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .presentation-fields .datetime-row .form-field label {
        margin: 0 !important;
        padding: 0 !important;
        white-space: nowrap !important;
    }

    .presentation-fields .datetime-row .form-field input[type="date"] {
        width: 180px !important;
        margin: 0 !important;
    }

    .presentation-fields .speaker-row {
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        gap: 30px !important;
        margin-top: 10px !important;
        width: 100% !important;
    }

    .speaker-row .form-field {
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        gap: 10px !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    .speaker-row .form-field.speaker {
        flex: 1 !important;
        min-width: 0 !important;
    }

    .speaker-row .form-field.speaker input {
        width: 300px !important;
        min-width: 0 !important;
    }

    .speaker-row .form-field.attendance {
        width: auto !important;
        min-width: unset !important;
        white-space: nowrap !important;
        flex: 0 0 auto !important;
    }

    .speaker-row .form-field.attendance input {
        width: 70px !important;
        min-width: 70px !important;
    }

    .speaker-row .form-field label {
        margin: 0 !important;
        padding: 0 !important;
        white-space: nowrap !important;
        width: auto !important;
        min-width: 0 !important;
    }

    .form-field label {
        color: var(--text-color);
        white-space: nowrap;
        margin: 0;
        padding: 0;
    }

    .form-field input[type="text"],
    .form-field input[type="number"],
    .form-field input[type="date"] {
        padding: 8px;
        background-color: var(--bg-color);
        color: var(--text-color);
        border: 1px solid #ccc;
        border-radius: 4px;
    }

    .dark-mode .form-field input[type="text"],
    .dark-mode .form-field input[type="number"],
    .dark-mode .form-field input[type="date"] {
        background-color: var(--dark-input-bg);
        color: var(--dark-text-color);
        border-color: #666;
    }

    /* Reset and contain all event field styles in a single block */
    .form-row {
        display: flex;
        align-items: flex-end !important;
        gap: 20px;
        position: relative;
        justify-content: space-between !important;
        width: 100% !important;
    }

    .form-row .form-field {
        display: flex !important;
        flex-direction: row !important;
        align-items: center !important;
        gap: 10px !important;
        margin: 0 !important;
    }

    .form-row .form-field label {
        margin: 0 !important;
        padding: 0 !important;
        white-space: nowrap !important;
        min-width: fit-content !important;
    }

    .form-row .form-field select {
        width: 200px !important;
        height: 35px !important;
        margin: 0 !important;
        padding: 0 8px !important;
    }

    .form-row .form-group {
        margin: 0 !important;
        padding: 0 !important;
        display: flex !important;
        align-items: flex-end !important;
    }

    .form-row .save-button {
        margin: 0 !important;
        height: auto !important;
        padding: var(--button-padding) !important;
        display: flex !important;
        align-items: center !important;
        justify-content: center !important;
    }

    /* Reset and contain all event field styles in a single block */
    .section-heading {
        font-size: var(--font-size-large);
        margin: 1em 0;
        color: var(--text-color);
        font-weight: var(--font-weight-normal);
    }

    /* Add new styles for presentations outer box */
    .presentations-outer-box {
        border: 1px solid #444;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        background-color: rgba(255, 255, 255, 0.02);
    }

    .presentations-inner-container {
        margin-bottom: 15px;
    }

    .presentation-entry {
        background-color: rgba(255, 255, 255, 0.05);
        border: 1px solid #444;
        padding: 20px;
        margin-bottom: 15px;
        border-radius: 4px;
        max-width: 100%;
        overflow: visible;
    }

    /* Update the last presentation entry to remove bottom margin */
    .presentation-entry:last-child {
        margin-bottom: 0;
    }

    .add-presentation-btn {
        background-color: var(--button-add-color);
        color: white;
        padding: 8px 15px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        display: block;
        width: fit-content;
    }
</style>

</body>
</html>
