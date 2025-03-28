<?php
include 'config.php';
include 'functions.php';

// Ensure the user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); // Redirect to login page if not logged in
    exit();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <script src="assets/js/main.js"></script>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h1>DNR Dashboard</h1>

    <h2>Add Speaking Engagement</h2>
    <form method="post" action="index.php">
        <label for="organization_id">organization:</label><br>
        <select name="organization_id" id="organization_id" required>
            <option value="" disabled selected>select an organization</option> <!-- Default null option -->
            <?php
            // Fetch organizations from the database
            $orgs = $conn->query("SELECT id, organization_name FROM organizations");
            while ($row = $orgs->fetch_assoc()) {
                echo "<option value='{$row['id']}'>"
                     . htmlspecialchars($row['organization_name'])
                     . "</option>";
            }
            ?>
        </select>
        <!-- New button/link to add a new organization -->
        <a href="organizations.php" class="add-org-button">Add Organization</a>
        <br><br>

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
                <input type="date" name="event_end_date" id="event_end_date">
            </div>
        </div>
        <br>

        <label for="event_type">event type:</label>
        <div class="event-type-container">
            <select name="event_type" id="event_type" onchange="toggleOtherEventType(this)">
                <option value="conference">conference</option>
                <option value="service">service</option>
                <option value="study or teaching">study or teaching</option>
                <option value="Passover Seder">Passover Seder</option>
                <option value="other">other</option>
            </select>

            <div id="other_event_type_div">
                <label>other event type:</label>
                    <input type="text" name="event_type_other">
            </div>
        </div>

        <br><br>

        <label>
            <input type="checkbox" name="book_table"> book table
        </label><br>
        <label>
            <input type="checkbox" name="brochures"> brochures permitted
        </label><br><br>

        <label for="caller_name">caller:</label>
        <input type="text" name="caller_name" id="caller_name"><br><br>

        <label for="confirmation_status">status:</label>
        <select name="confirmation_status" id="confirmation_status">
            <option value="work_in_progress">work in progress</option>
            <option value="under_review">under review</option>
            <option value="confirmed">confirmed</option>
        </select>
        <br><br>

        <!-- Button aligned to the right -->
        <input type="submit" name="save_engagement" value="Save Engagement" class="add-org-button" style="width: 50%; margin: 0 0 0 auto; display: block;">
    </form>
</div>
<?php include 'templates/footer.php'; ?>

<script>
    // Hide the "other event type" field initially
    document.addEventListener('DOMContentLoaded', function() {
        toggleOtherEventType(document.getElementById("event_type"));
    });

    function toggleOtherEventType(selectElement) {
        var otherEventTypeDiv = document.getElementById("other_event_type_div");

        if (selectElement.value === "other") {
            // Show
            otherEventTypeDiv.style.display = "inline-block";
        } else {
            // Hide
            otherEventTypeDiv.style.display = "none";
        }
    }
</script>

</body>
</html>

