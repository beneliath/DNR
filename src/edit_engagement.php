<?php
session_start();
include 'config.php';
include 'functions.php';
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_engagement'])) {
    error_log("Processing form submission for engagement ID: " . $engagement_id);
    
    $organization_id = intval($_POST['organization_id'] ?? 0);
    $engagement_notes = trim($_POST['engagement_notes'] ?? '');
    $event_start_date = $_POST['event_start_date'] ?? null;
    $event_end_date = $_POST['event_end_date'] ?? null;
    $event_type_raw = $_POST['event_type'] ?? '';
    $event_type_other = trim($_POST['event_type_other'] ?? '');
    $event_type = $event_type_raw === 'other' ? $event_type_other : $event_type_raw;
    $book_table = isset($_POST['book_table']) ? 1 : 0;
    $brochures = isset($_POST['brochures']) ? 1 : 0;
    $caller_name = trim($_POST['caller_name'] ?? '');
    $confirmation_status = $_POST['confirmation_status'] ?? 'work_in_progress';
    error_log("Confirmation status being set to: " . $confirmation_status);
    
    // Validate confirmation status
    $valid_statuses = ['work_in_progress', 'under_review', 'confirmed'];
    if (!in_array($confirmation_status, $valid_statuses)) {
        $confirmation_status = 'work_in_progress';
    }
    
    $travel_covered = $_POST['travel_covered'] ?? 'unknown';
    $travel_amount = !empty($_POST['travel_amount']) ? floatval($_POST['travel_amount']) : null;
    
    // Strict validation for compensation_type
    $valid_compensation_types = ['Unknown', 'Honorarium', 'Offering', 'Honorarium and Offering', 'Other'];
    $submitted_compensation_type = $_POST['compensation_type'] ?? 'Unknown';
    $compensation_type = in_array($submitted_compensation_type, $valid_compensation_types, true) ? $submitted_compensation_type : 'Unknown';
    error_log("Compensation type before: " . $engagement['compensation_type']);
    error_log("Compensation type submitted: " . $submitted_compensation_type);
    error_log("Compensation type after validation: " . $compensation_type);
    
    $other_compensation = trim($_POST['other_compensation'] ?? '');
    $housing_type = $_POST['housing_type'] ?? 'Unknown';
    $other_housing = trim($_POST['other_housing'] ?? '');
    $housing_amount = !empty($_POST['housing_amount']) ? floatval($_POST['housing_amount']) : null;
    
    // Event location fields
    $event_address_line_1 = trim($_POST['event_address_line_1'] ?? '');
    $event_address_line_2 = trim($_POST['event_address_line_2'] ?? '');
    $event_city = trim($_POST['event_city'] ?? '');
    $event_state = trim($_POST['event_state'] ?? '');
    $event_zipcode = trim($_POST['event_zipcode'] ?? '');
    $event_country = trim($_POST['event_country'] ?? '');

    error_log("Status being set to: " . $confirmation_status);
    
    // Validate required fields
    if (
        !$organization_id ||
        !$event_start_date ||
        !$event_end_date ||
        ($event_type_raw === 'other' && $event_type_other === '') ||
        ($compensation_type === 'Other' && empty($other_compensation)) ||
        ($housing_type === 'Other' && empty($other_housing))
    ) {
        $error_message = "Please fill in all required fields.";
        error_log("Validation failed: " . $error_message);
    } else {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Temporarily modify update query to focus on confirmation_status
            $update_query = "UPDATE engagements SET confirmation_status = ? WHERE id = ?";
            
            error_log("Update query: " . $update_query);
            error_log("Confirmation status value being set: " . $confirmation_status);
            error_log("Engagement ID: " . $engagement_id);

            $stmt = $conn->prepare($update_query);
            if ($stmt) {
                $stmt->bind_param("si", $confirmation_status, $engagement_id);

                if ($stmt->execute()) {
                    error_log("Successfully updated engagement status");
                    $conn->commit();
                    header("Location: engagements.php");
                    exit();
                } else {
                    throw new Exception("Error updating engagement: " . $stmt->error);
                }
            } else {
                throw new Exception("Database error: " . $conn->error);
            }
        } catch (Exception $e) {
            error_log("Error in update: " . $e->getMessage());
            $conn->rollback();
            $error_message = $e->getMessage();
        }
    }
}

// Get default speaker name from environment variable
$DEFAULT_SPEAKER = getenv('DEFAULT_SPEAKER') ? getenv('DEFAULT_SPEAKER') : 'Unknown Speaker';

// Get presentations for this engagement
$presentations_query = "SELECT * FROM presentations WHERE engagement_id = ? ORDER BY presentation_date, presentation_time";
$stmt = $conn->prepare($presentations_query);
$stmt->bind_param("i", $engagement_id);
$stmt->execute();
$presentations_result = $stmt->get_result();
$presentations = [];
while ($row = $presentations_result->fetch_assoc()) {
    $presentations[] = $row;
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Engagement - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h2>Edit Engagement</h2>
    <?php if (!empty($error_message)): ?>
        <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    
    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $engagement_id); ?>" onsubmit="return validateDates();">
        <div class="organization-container">
            <label for="organization_id">Organization</label>
            <select name="organization_id" id="organization_id" required>
                <?php
                // Fetch and display organizations in the dropdown
                $orgs = $conn->query("SELECT id, organization_name FROM organizations ORDER BY organization_name");
                while ($row = $orgs->fetch_assoc()) {
                    $selected = ($row['id'] == $engagement['organization_id']) ? 'selected' : '';
                    echo "<option value='" . htmlspecialchars($row['id']) . "' {$selected}>" . htmlspecialchars($row['organization_name']) . "</option>";
                }
                ?>
            </select>
        </div>

        <label for="engagement_notes" style="vertical-align: top;">Chron</label>
        <textarea name="engagement_notes" id="engagement_notes" rows="6" style="width: calc(100% - 0px);"><?php echo htmlspecialchars($engagement['engagement_notes'] ?? ''); ?></textarea>

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
                <div class="label-container">Other Event Type<span class="required">*</span></div>
                <input type="text" name="event_type_other" id="event_type_other" value="<?php echo htmlspecialchars($engagement['event_type_other'] ?? ''); ?>">
            </div>
        </div>

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

            <div class="form-group" style="padding-left: 0; margin-left: 0;">
                <input type="submit" name="save_engagement" value="SAVE CHANGES" class="save-button" style="margin-left: 0;">
                <a href="engagements.php" class="cancel-button">Cancel</a>
            </div>
        </div>
    </form>
</div>

<?php include 'templates/footer.php'; ?>

<script>
    // Validate that the event end date is on or after the event start date
    function validateDates() {
        const startDate = document.getElementById("event_start_date").value;
        const endDate = document.getElementById("event_end_date").value;
        
        if (startDate && endDate && endDate < startDate) {
            alert("End date must be on or after the start date");
            return false;
        }
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
        background-color: #666;
        color: white;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        text-decoration: none;
        margin-left: 10px;
    }
    .cancel-button:hover {
        background-color: #555;
    }
    .save-button {
        background-color: #357abd;
    }
    .save-button:hover {
        background-color: #2d6a9d;
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
    .label-container {
        position: absolute;
        top: -30px;
        color: var(--text-color);
        font-size: 16px;
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
        font-size: 14px;
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