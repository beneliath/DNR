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

    // Handle presentations data
    $presentations = [];
    if (isset($_POST['presentations']) && is_array($_POST['presentations'])) {
        foreach ($_POST['presentations'] as $index => $presentation) {
            if (!empty($presentation['topic_title'])) {
                $presentations[] = [
                    'topic_title' => trim($presentation['topic_title']),
                    'presentation_date' => $presentation['presentation_date'],
                    'presentation_time' => $presentation['presentation_time'],
                    'speaker_name' => trim($presentation['speaker_name']),
                    'expected_attendance' => intval($presentation['expected_attendance'])
                ];
            }
        }
    }

    // Validate required fields
    if (
        !$organization_id ||
        !$event_start_date ||
        !$event_end_date ||
        ($event_type_raw === 'other' && $event_type_other === '') ||
        empty($presentations)
    ) {
        $error_message = htmlspecialchars("Please fill in all required fields, including at least one presentation.");
    } else {
        // Start transaction
        $conn->begin_transaction();
        
        try {
            // Insert engagement data
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
                    $engagement_id = $conn->insert_id;
                    
                    // Insert presentations
                    $presentation_stmt = $conn->prepare("INSERT INTO presentations (
                        engagement_id, topic_title, presentation_date, presentation_time,
                        speaker_name, expected_attendance
                    ) VALUES (?, ?, ?, ?, ?, ?)");

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
                            throw new Exception("Error saving presentation: " . $presentation_stmt->error);
                        }
                    }
                    
                    $conn->commit();
                    $success_message = "Engagement and presentations saved successfully!";
                } else {
                    throw new Exception("Error saving engagement: " . $stmt->error);
                }

                $stmt->close();
            } else {
                throw new Exception("Database error: " . $conn->error);
            }
        } catch (Exception $e) {
            $conn->rollback();
            $error_message = $e->getMessage();
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

        <!-- Event type fields -->
        <div class="event-row">
            <div class="event-group">
                <div class="label-container">event type</div>
                <select name="event_type" id="event_type" onchange="toggleOtherEventType(this)">
                    <option value="conference">conference</option>
                    <option value="service">service</option>
                    <option value="study or teaching">study or teaching</option>
                    <option value="Passover Seder">Passover Seder</option>
                    <option value="other">other</option>
                </select>
            </div>

            <div class="event-group" id="other_event_type_div">
                <div class="label-container">other event type<span class="required">*</span></div>
                <input type="text" name="event_type_other" id="event_type_other">
            </div>
        </div>



        <!-- Presentation(s) Section -->
        <div id="presentations-container">
            <h3>Presentation(s)</h3>
            <div class="presentation-entry" id="presentation-1">
                <div class="presentation-fields">
                    <div class="form-field topic">
                        <label for="presentation_topic_1">Topic/Title</label>
                        <input type="text" name="presentations[0][topic_title]" id="presentation_topic_1">
                    </div>
                    <div class="datetime-row">
                        <div class="form-field">
                            <label for="presentation_date_1">Date</label>
                            <input type="date" name="presentations[0][presentation_date]" id="presentation_date_1">
                        </div>
                        <div class="form-field">
                            <label for="presentation_time_1">Time</label>
                            <div class="time-input-container">
                                <input type="text" name="presentation_time_1" id="presentation_time_1" pattern="[0-9]{1,2}:[0-9]{2}" placeholder="HH:MM">
                                <div class="ampm-radio">
                                    <label><input type="radio" name="presentation_ampm_1" value="AM" checked> AM</label>
                                    <label><input type="radio" name="presentation_ampm_1" value="PM"> PM</label>
                                </div>
                            </div>
                            <input type="hidden" name="presentations[0][presentation_time]" id="presentation_time_hidden_1">
                        </div>
                    </div>
                    <div class="speaker-row">
                        <div class="form-field speaker">
                            <label for="speaker_name_1">Speaker Name</label>
                            <input type="text" name="presentations[0][speaker_name]" id="speaker_name_1" value="Olivier Melnick">
                        </div>
                        <div class="form-field attendance">
                            <label for="expected_attendance_1">Expected Attendance</label>
                            <input type="number" name="presentations[0][expected_attendance]" id="expected_attendance_1" min="1">
                        </div>
                    </div>
                </div>
            </div>
            <button type="button" onclick="addPresentation()" class="add-presentation-btn">Add Another Presentation</button>
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

    let presentationCount = 1;

    function addPresentation() {
        presentationCount++;
        const container = document.getElementById('presentations-container');
        const newPresentation = document.createElement('div');
        newPresentation.className = 'presentation-entry';
        newPresentation.id = `presentation-${presentationCount}`;
        
        newPresentation.innerHTML = `
            <div class="presentation-fields">
                <div class="form-field topic">
                    <label for="presentation_topic_${presentationCount}">Topic/Title<span>*</span></label>
                    <input type="text" name="presentations[${presentationCount-1}][topic_title]" id="presentation_topic_${presentationCount}" required>
                </div>
                <div class="datetime-row">
                    <div class="form-field">
                        <label for="presentation_date_${presentationCount}">Date<span>*</span></label>
                        <input type="date" name="presentations[${presentationCount-1}][presentation_date]" id="presentation_date_${presentationCount}" required>
                    </div>
                    <div class="form-field">
                        <label for="presentation_time_${presentationCount}">Time<span>*</span></label>
                        <div class="time-input-container">
                            <input type="text" name="presentation_time_${presentationCount}" id="presentation_time_${presentationCount}" pattern="[0-9]{1,2}:[0-9]{2}" placeholder="HH:MM" required>
                            <div class="ampm-radio">
                                <label><input type="radio" name="presentation_ampm_${presentationCount}" value="AM" checked> AM</label>
                                <label><input type="radio" name="presentation_ampm_${presentationCount}" value="PM"> PM</label>
                            </div>
                        </div>
                        <input type="hidden" name="presentations[${presentationCount-1}][presentation_time]" id="presentation_time_hidden_${presentationCount}">
                    </div>
                </div>
                <div class="speaker-row">
                    <div class="form-field speaker">
                        <label for="speaker_name_${presentationCount}">Speaker Name<span>*</span></label>
                        <input type="text" name="presentations[${presentationCount-1}][speaker_name]" id="speaker_name_${presentationCount}" value="Olivier Melnick" required>
                    </div>
                    <div class="form-field attendance">
                        <label for="expected_attendance_${presentationCount}">Expected Attendance<span>*</span></label>
                        <input type="number" name="presentations[${presentationCount-1}][expected_attendance]" id="expected_attendance_${presentationCount}" min="1" required>
                    </div>
                </div>
                <div class="remove-btn-container">
                    <button type="button" onclick="removePresentation(${presentationCount})" class="remove-presentation-btn">Remove</button>
                </div>
            </div>
        `;
        
        container.insertBefore(newPresentation, document.querySelector('.add-presentation-btn'));
    }

    function removePresentation(id) {
        if (id === 1) return; // Prevent removing the first presentation
        const presentation = document.getElementById(`presentation-${id}`);
        if (presentation) {
            presentation.remove();
        }
    }

    // Update time input validation and formatting
    document.addEventListener('DOMContentLoaded', function() {
        // Function to validate time format
        function validateTimeFormat(timeStr) {
            const timeRegex = /^([0-9]{1,2}):([0-9]{2})$/;
            const match = timeStr.match(timeRegex);
            if (!match) return false;
            
            const hours = parseInt(match[1]);
            const minutes = parseInt(match[2]);
            
            return hours >= 1 && hours <= 12 && minutes >= 0 && minutes <= 59;
        }

        // Function to format time input
        function formatTime(timeStr, ampm) {
            const [hours, minutes] = timeStr.split(':');
            return `${hours.padStart(2, '0')}:${minutes} ${ampm}`;
        }

        // Function to update hidden time input
        function updateTimeInput(presentationId) {
            const timeInput = document.getElementById(`presentation_time_${presentationId}`);
            const ampmInputs = document.getElementsByName(`presentation_ampm_${presentationId}`);
            const hiddenInput = document.getElementById(`presentation_time_hidden_${presentationId}`);
            
            if (validateTimeFormat(timeInput.value)) {
                const selectedAmpm = Array.from(ampmInputs).find(input => input.checked).value;
                hiddenInput.value = formatTime(timeInput.value, selectedAmpm);
            }
        }

        // Add event listeners for time inputs
        for (let i = 1; i <= presentationCount; i++) {
            const timeInput = document.getElementById(`presentation_time_${i}`);
            const ampmInputs = document.getElementsByName(`presentation_ampm_${i}`);

            if (timeInput && ampmInputs.length > 0) {
                timeInput.addEventListener('input', () => updateTimeInput(i));
                ampmInputs.forEach(input => {
                    input.addEventListener('change', () => updateTimeInput(i));
                });
            }
        }

        // Add form submit handler to ensure all time inputs are formatted
        document.querySelector('form').addEventListener('submit', function(e) {
            for (let i = 1; i <= presentationCount; i++) {
                updateTimeInput(i);
            }
        });
    });

    // Update CSS for time input container
    const style = document.createElement('style');
    style.textContent = `
        .time-input-container {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .time-input-container input[type="text"] {
            width: 80px;
            padding: 5px;
            background-color: #333;
            color: #fff;
            border: 1px solid #666;
            border-radius: 4px;
        }
        .time-input-container input[type="text"]::placeholder {
            color: #888;
        }
        .ampm-radio {
            display: flex;
            gap: 15px;
            color: #fff;
        }
        .ampm-radio label {
            display: flex;
            align-items: center;
            gap: 5px;
            color: #fff;
            cursor: pointer;
        }
        .ampm-radio input[type="radio"] {
            appearance: none;
            -webkit-appearance: none;
            width: 16px;
            height: 16px;
            border: 2px solid #666;
            border-radius: 50%;
            margin: 0;
            cursor: pointer;
            position: relative;
        }
        .ampm-radio input[type="radio"]:checked {
            border-color: #357abd;
            background-color: transparent;
        }
        .ampm-radio input[type="radio"]:checked::after {
            content: '';
            position: absolute;
            width: 8px;
            height: 8px;
            background-color: #357abd;
            border-radius: 50%;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }
        .ampm-radio input[type="radio"]:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(53, 122, 189, 0.3);
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
        .presentation-fields {
            display: grid;
            grid-template-columns: 1fr;
            gap: 15px;
        }
        .presentation-fields .form-field {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .presentation-fields .form-field.topic {
            grid-column: 1 / -1;
            display: flex;
            align-items: center;
        }
        .presentation-fields .form-field.topic label {
            margin-right: 15px;
        }
        .presentation-fields .form-field.topic input {
            flex: 1;
            max-width: calc(100% - 20px);
        }
        .presentation-fields .datetime-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        .presentation-fields .datetime-row .form-field {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .presentation-fields .speaker-row {
            display: flex;
            gap: 30px;
            align-items: center;
            margin-top: 10px;
        }
        .speaker-row .form-field {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }
        .speaker-row .form-field.speaker {
            flex: 1;
        }
        .speaker-row .form-field.speaker input {
            width: 300px;
        }
        .speaker-row .form-field.attendance {
            width: auto;
            min-width: unset;
            white-space: nowrap;
        }
        .speaker-row .form-field.attendance input {
            width: 70px !important;
        }
        .form-field {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 0;
        }
        .form-field label {
            color: #fff;
            white-space: nowrap;
            margin: 0;
            padding: 0;
            display: flex;
            align-items: center;
            height: 100%;
        }
        .form-field label > span {
            color: #f44336;
            margin-left: 2px;
        }
        .form-field input[type="text"],
        .form-field input[type="number"],
        .form-field input[type="date"] {
            padding: 8px;
            background-color: #333;
            color: #fff;
            border: 1px solid #666;
            border-radius: 4px;
            margin: 0;
        }
        .add-presentation-btn {
            background-color: #666;
            color: white;
            padding: 5px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-bottom: 20px;
            font-family: inherit;
            font-size: inherit;
        }
        .add-presentation-btn:hover {
            background-color: #FF9800;
        }
        .add-presentation-btn:focus {
            outline: none;
        }
        .remove-presentation-btn {
            background-color: #f44336;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            align-self: end;
        }
        .remove-presentation-btn:hover {
            background-color: #da190b;
        }
        .radio-options {
            display: flex;
            align-items: center;
            gap: 20px;
            margin: 0;
        }

        .radio-options label {
            display: flex;
            align-items: center;
            gap: 5px;
            margin: 0;
            color: #fff;
            cursor: pointer;
        }

        .radio-options input[type="radio"] {
            appearance: none;
            -webkit-appearance: none;
            width: 16px;
            height: 16px;
            border: 2px solid #666;
            border-radius: 50%;
            margin: 0;
            cursor: pointer;
            position: relative;
        }

        .radio-options input[type="radio"]:checked {
            border-color: #357abd;
            background-color: transparent;
        }

        .radio-options input[type="radio"]:checked::after {
            content: '';
            position: absolute;
            width: 8px;
            height: 8px;
            background-color: #357abd;
            border-radius: 50%;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .radio-options input[type="radio"]:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(53, 122, 189, 0.3);
        }
    `;
    document.head.appendChild(style);
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
        color: #fff;
        font-size: 16px;
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
        background-color: #1e1e1e;
        color: #fff;
        border: 1px solid #333;
        border-radius: 4px;
        box-sizing: border-box;
        font-size: 14px;
    }

    .event-group select {
        width: 20ch;
    }

    #other_event_type_div {
        display: none;
    }

    #other_event_type_div input[type="text"] {
        width: 300px;
        background-color: #333;
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
        grid-template-columns: repeat(2, 1fr);
        gap: 15px;
    }

    .presentation-fields .speaker-row {
        display: flex;
        gap: 30px;
        align-items: center;
        margin-top: 10px;
    }

    .speaker-row .form-field {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .speaker-row .form-field.speaker {
        flex: 1;
    }

    .speaker-row .form-field.speaker input {
        width: 300px;
    }

    .speaker-row .form-field.attendance {
        width: auto;
        min-width: unset;
        white-space: nowrap;
    }

    .speaker-row .form-field.attendance input {
        width: 70px !important;
    }

    .form-field label {
        color: #fff;
        white-space: nowrap;
        margin: 0;
        padding: 0;
    }

    .form-field input[type="text"],
    .form-field input[type="number"],
    .form-field input[type="date"] {
        padding: 8px;
        background-color: #333;
        color: #fff;
        border: 1px solid #666;
        border-radius: 4px;
    }
</style>

</body>
</html>

