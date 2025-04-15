<?php
session_start(); // Start session to access session variables
include 'config.php';
include 'functions.php';
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';

// Handle delete action if user is admin
if ($user_role === 'admin' && isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $engagement_id = intval($_GET['delete']);
    $conn->query("UPDATE engagements SET is_deleted = 1 WHERE id = $engagement_id");
    // Redirect to remove delete parameter from URL
    header("Location: engagements.php");
    exit();
}

// Retrieve engagements with organization name
$date_sort = isset($_GET['date_sort']) ? $_GET['date_sort'] : 'asc';
$status_sort = isset($_GET['status_sort']) ? $_GET['status_sort'] : 'asc';
$org_sort = isset($_GET['org_sort']) ? $_GET['org_sort'] : 'asc';

// Determine which column to sort by based on which button was clicked
$sort_column = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'date';

// Determine sort order based on column
if ($sort_column === 'date') {
    $sort_order = $date_sort;
} elseif ($sort_column === 'status') {
    $sort_order = $status_sort;
} elseif ($sort_column === 'org') {
    $sort_order = $org_sort;
} else {
    $sort_order = 'asc';
}

// Build the ORDER BY clause safely
if ($sort_column === 'date') {
    $order_by = 'e.event_start_date';
} elseif ($sort_column === 'status') {
    $order_by = 'e.confirmation_status';
} elseif ($sort_column === 'org') {
    $order_by = 'o.organization_name';
} else {
    $order_by = 'e.event_start_date';
}
$order_direction = ($sort_order === 'asc' ? 'ASC' : 'DESC');

// Prepare and execute the query
$query = "SELECT e.*, o.organization_name 
          FROM engagements e 
          LEFT JOIN organizations o ON e.organization_id = o.id 
          WHERE e.is_deleted = 0
          ORDER BY {$order_by} {$order_direction}";

$result = $conn->query($query);
if (!$result) {
    die("Database error: " . $conn->error);
}
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
            display: inline-block;
        }
        .sort-button:hover {
            background-color: #FF9800;
        }
        .action-buttons {
            display: flex;
            gap: 5px;
        }
        .action-button {
            padding: 4px 8px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            color: white;
        }
        .edit-button {
            background-color: #357abd;
        }
        .edit-button:hover {
            background-color: #2d6a9d;
        }
        .delete-button {
            background-color: #d32f2f;
        }
        .delete-button:hover {
            background-color: #b71c1c;
        }
        .view-button {
            background-color: #4CAF50;
        }
        .view-button:hover {
            background-color: #388E3C;
        }
        /* Status colors */
        .status-work-in-progress {
            color: #4CAF50; /* Green */
        }
        .status-under-review {
            color: #2196F3; /* Blue */
        }
        .status-confirmed {
            color: #FF9800; /* Orange */
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h1>Engagements</h1>
    <div class="sort-buttons">
        <a href="?sort_by=org&org_sort=<?php echo $org_sort === 'asc' ? 'desc' : 'asc'; ?>&date_sort=<?php echo $date_sort; ?>&status_sort=<?php echo $status_sort; ?>" class="sort-button">
            Organization <?php echo $org_sort === 'asc' ? '↑' : '↓'; ?>
        </a>
        <a href="?sort_by=date&date_sort=<?php echo $date_sort === 'asc' ? 'desc' : 'asc'; ?>&status_sort=<?php echo $status_sort; ?>&org_sort=<?php echo $org_sort; ?>" class="sort-button">
            Date <?php echo $date_sort === 'asc' ? '↑' : '↓'; ?>
        </a>
        <a href="?sort_by=status&status_sort=<?php echo $status_sort === 'asc' ? 'desc' : 'asc'; ?>&date_sort=<?php echo $date_sort; ?>&org_sort=<?php echo $org_sort; ?>" class="sort-button">
            Status <?php echo $status_sort === 'asc' ? '↑' : '↓'; ?>
        </a>
    </div>
    <table border="1" cellpadding="5">
        <tr>
            <th>Organization</th>
            <th>Event Dates</th>
            <th>Type</th>
            <th>Status</th>
            <?php if ($user_role === 'admin' || $user_role === 'editor'): ?>
            <th>Actions</th>
            <?php endif; ?>
        </tr>
        <?php while ($row = $result->fetch_assoc()): ?>
        <tr>
            <td><?php echo htmlspecialchars($row['organization_name']); ?></td>
            <td><?php echo htmlspecialchars($row['event_start_date'] . ' to ' . $row['event_end_date']); ?></td>
            <td><?php echo htmlspecialchars($row['event_type']); ?></td>
            <td><?php 
                $status = $row['confirmation_status'];
                $status_class = 'status-' . str_replace('_', '-', $status);
                $display_status = str_replace('_', ' ', $status);
                echo "<span class='{$status_class}'>" . htmlspecialchars($display_status) . "</span>";
            ?></td>
            <?php if ($user_role === 'admin' || $user_role === 'editor'): ?>
            <td class="action-buttons">
                <a href="view_engagement.php?id=<?php echo $row['id']; ?>" class="action-button view-button">View</a>
                <?php if ($user_role === 'admin' || $user_role === 'editor'): ?>
                <a href="edit_engagement.php?id=<?php echo $row['id']; ?>" class="action-button edit-button">Edit</a>
                <?php endif; ?>
                <?php if ($user_role === 'admin'): ?>
                <a href="?delete=<?php echo $row['id']; ?>" class="action-button delete-button" onclick="return confirm('Are you sure you want to delete this engagement?');">Delete</a>
                <?php endif; ?>
            </td>
            <?php endif; ?>
        </tr>
        <?php endwhile; ?>
    </table>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>

