<?php
include 'config.php';
include 'functions.php';

session_start();
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Handle form submission
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

    if (
        !$organization_id ||
        !$event_start_date ||
        !$event_end_date ||
        ($event_type_raw === 'other' && $event_type_other === '')
    ) {
        $error_message = "Please fill in all required fields, including 'other event type' if selected.";
    } else {
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

    <?php if (!empty($error_message)): ?>
        <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>

    <h2>Add Speaking Engagement</h2>
    <form method="post" action="index.php">
        <label for="organization_id">organization:</label><br>
        <select name="organization_id" id="organization_id" required>
            <option value="" disabled selected>select an organization</option>
            <?php
            $orgs = $conn->query("SELECT id, organization_name FROM organizations");
            while ($row = $orgs->fetch_assoc()) {
                echo "<option value='{$row['id']}'>" . htmlspecialchars($row['organization_name']) . "</option>";
            }
            ?>
        </select>
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
                <input type="date" name="event_end_date" id="event_end_date" required>
            </div>
        </div>
        <br>

        <!-- FIXED: Event type and other field inline -->
        <div class="event-type-container">
            <div class="event-type-field">
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
                <label for="event_type_other">other event type:</label>
                <input type="text" name="event_type_other" id="event_type_other">
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

        <input type="submit" name="save_engagement" value="Save Engagement" class="add-org-button" style="width: 50%; margin: 0 0 0 auto; display: block;">
    </form>
</div>
<?php include 'templates/footer.php'; ?>

<?php if (!empty($success_message)): ?>
<script>
    alert("<?php echo addslashes($success_message); ?>");
</script>
<?php endif; ?>

<script>
    function toggleOtherEventType(selectElement) {
        const otherDiv = document.getElementById("other_event_type_div");
        const otherInput = document.getElementById("event_type_other");

        if (selectElement.value === "other") {
            otherDiv.style.display = "block";
            otherInput.setAttribute("required", "required");
        } else {
            otherDiv.style.display = "none";
            otherInput.removeAttribute("required");
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        toggleOtherEventType(document.getElementById("event_type"));
    });
</script>

</body>
</html>

