<?php
session_start();
include 'config.php';
include 'functions.php';
requireLogin();

// Retrieve engagements with organization name
$engagements = $conn->query("SELECT e.*, o.organization_name FROM engagements e LEFT JOIN organizations o ON e.organization_id = o.id ORDER BY e.event_start_date DESC");
?>
<!DOCTYPE html>
<html>
<head>
    <title>Engagements - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h1>Engagements</h1>
    <table border="1" cellpadding="5">
        <tr>
            <th>ID</th>
            <th>Organization</th>
            <th>Event Dates</th>
            <th>Type</th>
            <th>Confirmation</th>
        </tr>
        <?php while ($row = $engagements->fetch_assoc()): ?>
        <tr>
            <td><?php echo $row['id']; ?></td>
            <td><?php echo htmlspecialchars($row['organization_name']); ?></td>
            <td><?php echo htmlspecialchars($row['event_start_date'] . ' to ' . $row['event_end_date']); ?></td>
            <td><?php echo htmlspecialchars($row['event_type']); ?></td>
            <td><?php echo htmlspecialchars($row['confirmation_status']); ?></td>
        </tr>
        <?php endwhile; ?>
    </table>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>

