<?php
session_start(); // Start session to access session variables
include 'config.php';
include 'functions.php';
requireLogin();

// Retrieve engagements with organization name
$sort_order = isset($_GET['sort']) ? $_GET['sort'] : 'asc';
$engagements = $conn->query("SELECT e.*, o.organization_name FROM engagements e LEFT JOIN organizations o ON e.organization_id = o.id ORDER BY e.event_start_date " . ($sort_order === 'asc' ? 'ASC' : 'DESC'));
?>
<!DOCTYPE html>
<html>
<head>
    <title>Engagements - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .sort-buttons {
            margin: 15px 0;
            display: flex;
            gap: 10px;
        }
        .sort-button {
            padding: 8px 15px;
            background-color: #666;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        .sort-button:hover {
            background-color: #FF9800;
        }
        .sort-button.active {
            background-color: #FF9800;
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h1>Engagements</h1>
    <div class="sort-buttons">
        <a href="?sort=asc" class="sort-button <?php echo $sort_order === 'asc' ? 'active' : ''; ?>">Ascending Date</a>
        <a href="?sort=desc" class="sort-button <?php echo $sort_order === 'desc' ? 'active' : ''; ?>">Descending Date</a>
    </div>
    <table border="1" cellpadding="5">
        <tr>
            <th>Organization</th>
            <th>Event Dates</th>
            <th>Type</th>
            <th>Confirmation</th>
        </tr>
        <?php while ($row = $engagements->fetch_assoc()): ?>
        <tr>
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

