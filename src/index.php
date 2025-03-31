<?php
// This file serves as the main dashboard for managing speaking engagements.
// It handles form submissions for adding engagements and displays the dashboard interface.

include 'config.php';
include 'functions.php';

session_start();
session_regenerate_id(true);
// Redirect to login page if the user is not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle form submission for adding a new engagement
$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_engagement'])) {
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

    // Validate required fields
    if (
        !$organization_id ||
        !$event_start_date ||
        !$event_end_date ||
        ($event_type_raw === 'other' && $event_type_other === '')
    ) {
$error_message = htmlspecialchars("Please fill in all required fields, including 'other event type' if selected.");
    } else {
        // Prepare SQL statement to insert engagement data
        $stmt = $conn->prepare("INSERT INTO engagements (
            organization_id, engagement_notes, event_start_date, event_end_date,
            event_type, book_table, brochures, caller_name, confirmation_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");

        if ($stmt) {
            $stmt->bind_param(
                "isssssiss",
                $organization_id,
                $engagement_notes,
                $event_start_date,
                $event_end_date,
                $event_type,
                $book_table,
                $brochures,
                $caller_name,
                $confirmation_status
            );

            if ($stmt->execute()) {
                $success_message = "Engagement saved successfully!";
            } else {
                $error_message = "Database error: " . $stmt->error;
            }

            $stmt->close();
        } else {
            $error_message = "Database error: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<!-- HTML structure for the dashboard interface -->
<html>
<head>
    <title>DNR dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/main.js"></script>
</head>
<body>
<?php include 'templates/header.php'; ?>
<!-- Main container for the dashboard content -->
<div class="container">
    <?php if (!empty($error_message)): ?>
        <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <h2>Add Engagement</h2>
    <!-- Form for adding a new speaking engagement -->
    <form method="post" action="index.php" onsubmit="return validateDates();">
<div class="organization-container">
    <label for="organization_id">organization:</label>
    <select name="organization_id" id="organization_id" required>
        <option value="" disabled selected>select an organization</option>
        <?php
        // Fetch and display organizations in the dropdown
        $orgs = $conn->query("SELECT id, organization_name FROM organizations");
        while ($row = $orgs->fetch_assoc()) {
echo "<option value='" . htmlspecialchars($row['id']) . "'>" . htmlspecialchars($row['organization_name']) . "</option>";
        }
        ?>
    </select>
                    <a href="organizations.php" class="add-org-button">Add New Organization</a>
</div>


        <label for="engagement_notes" style="vertical-align: top;">chron:</label>
        <textarea name="engagement_notes" id="engagement_notes" rows="6" style="width: calc(100% - 0px);"></textarea>
        <br><br>

        <div class="date-fields">
            <div class="date-field">
                <label for="event_start_date">start:</label>
                <input type="date" name="event_start_date" id="event_start_date" required>
            </div>
            <div class="date-field">
                <label for="event_end_date">end:</label>
                <input type="date" name="event_end_date" id="event_end_date" required>
            </div>
        </div>
        <br>

        <!-- FIXED: Event type and other field inline -->
        <div class="event-type-container">
            <div class="form-field">
                <label for="event_type">event type:</label>
                <select name="event_type" id="event_type" onchange="toggleOtherEventType(this)">
                    <option value="conference">conference</option>
                    <option value="service">service</option>
                    <option value="study or teaching">study or teaching</option>
                    <option value="Passover Seder">Passover Seder</option>
                    <option value="other">other</option>
                </select>
            </div>

            <div class="event-type-field" id="other_event_type_div">
                <label for="event_type_other" class="required">other event type</label>
                <input type="text" name="event_type_other" id="event_type_other">
            </div>
        </div>

        <br>

        <div class="checkbox-row">
            <div class="checkbox-group">
                <label class="checkbox-label">
                    <input type="checkbox" name="book_table"> book table provided
                </label>
                <label class="checkbox-label">
                    <input type="checkbox" name="brochures"> brochures permitted
                </label>
            </div>
            <div class="radio-row">
                <label>All Travel Covered?</label>
                <div class="radio-options">
                    <label><input type="radio" name="travel_covered" value="unknown" checked> Unknown</label>
                    <label><input type="radio" name="travel_covered" value="yes"> Yes</label>
                    <label><input type="radio" name="travel_covered" value="no"> No</label>
                </div>
            </div>
        </div>
<br>
        <div class="compensation-grid">
            <div class="compensation-type-row">
                <div class="form-field">
                    <div class="field-group">
                        <label for="compensation_type">Type of Compensation</label>
                        <select name="compensation_type" id="compensation_type" class="narrow-select" onchange="toggleOtherCompensation()">
                            <option value="Unknown">Unknown</option>
                            <option value="Honorarium">Honorarium</option>
                            <option value="Offering">Offering</option>
                            <option value="Honorarium and Offering">Honorarium and Offering</option>
                            <option value="Other">Other</option>
                        </select>
                    </div>
                </div>

                <div class="form-field" id="other_compensation_div" style="display: none;">
                    <div class="field-group">
                        <label for="other_compensation" class="required">Describe Other Compensation</label>
                        <input type="text" name="other_compensation" id="other_compensation">
                    </div>
                </div>
            </div>
<br>
            <div class="amount-row">
                <div class="form-field">
                    <label>Travel (not in Compensation)</label>
                    <div class="currency-input">
                        <span>$</span>
                        <input type="number" name="travel_amount" step="0.01" min="0">
                    </div>
                </div>
                <div class="form-field">
                    <label>Lodging (not in Travel)</label>
                    <div class="currency-input">
                        <span>$</span>
                        <input type="number" name="housing_amount" step="0.01" min="0">
                    </div>
                </div>
            </div>
        </div>
<br>
        <div class="compensation-type-row">
            <div class="form-field">
                <div class="field-group">
                    <label for="housing_type">Lodging Type</label>
                    <select name="housing_type" id="housing_type" class="narrow-select" onchange="toggleOtherHousing()">
                        <option value="Unknown">Unknown</option>
                        <option value="Provided">Provided</option>
                        <option value="Not Provided">Not Provided</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
            </div>

            <div class="form-field" id="other_housing_div" style="display: none;">
                <div class="field-group">
                    <label for="other_housing" class="required">Describe Other Lodging</label>
                    <input type="text" name="other_housing" id="other_housing">
                </div>
            </div>
        </div>
<br>
        <div class="form-row">
            <div class="form-field">
                <label for="caller_name">caller:</label>
                <select name="caller_name" id="caller_name">
                    <option value="" disabled selected>select a caller</option>
                    <?php
                    // Fetch and display users in the dropdown
                    $users = $conn->query("SELECT username FROM users ORDER BY username");
                    while ($row = $users->fetch_assoc()) {
                        echo "<option value='" . htmlspecialchars($row['username']) . "'>" . htmlspecialchars($row['username']) . "</option>";
                    }
                    ?>
                </select>
            </div>

            <div class="form-field" style="margin-left: 20px;">
                <label for="confirmation_status">status:</label>
                <select name="confirmation_status" id="confirmation_status">
                    <option value="work_in_progress">work in progress</option>
                    <option value="under_review">under review</option>
                    <option value="confirmed">confirmed</option>
                </select>
            </div>

            <div class="save-button-container">
                <input type="submit" name="save_engagement" value="SAVE ENGAGEMENT" class="save-event-button">
            </div>
        </div>
    </form>
</div>
<?php include 'templates/footer.php'; ?>
<!-- Display success message as an alert if present -->

<?php if (!empty($success_message)): ?>
<script>
    alert("<?php echo addslashes($success_message); ?>");
</script>
<?php endif; ?>

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
</script>

<style>
    .form-row {
        margin-bottom: 15px;
        display: flex;
        align-items: baseline;
        max-width: calc(100% - 5px);
    }
    .form-field {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .form-field label {
        min-width: 60px;
        margin-top: -8px;
    }
    /* Make event type label 20% wider */
    .event-type-container .form-field label {
        min-width: 90px;  /* 60px + 50% */
    }
    .form-field select {
        width: 200px;
        padding: 8px;
        border: 1px solid #ddd;
        border-radius: 4px;
        height: 35px;
        outline: none;
    }
    #caller_name {
        width: 180px;
    }
    #confirmation_status {
        width: 200px;
    }
    /* Dark mode styles */
    .dark-mode .form-field select {
        background-color: #1e1e1e;
        color: #fff;
        border-color: #333;
    }
    .save-button-container {
        margin-left: auto;
        padding-left: 20px;
        transform: translateY(-10px);  /* Increased negative value to move the button up 2 more pixels */
    }
    .save-event-button {
        padding: 5px 15px;
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
</style>

</body>
</html>

