<?php
include 'config.php';
include 'functions.php';
startSecureSession();
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';

// Handle soft deletion through an authenticated POST request.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_engagement'])) {
    if ($user_role !== 'admin') {
        http_response_code(403);
        exit('Forbidden.');
    }
    requireValidCsrfToken();
    $engagement_id = filter_input(INPUT_POST, 'engagement_id', FILTER_VALIDATE_INT);
    if ($engagement_id) {
        $delete_stmt = $conn->prepare("UPDATE engagements SET is_deleted = 1 WHERE id = ?");
        $delete_stmt->bind_param("i", $engagement_id);
        $delete_stmt->execute();
    }
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
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.2.2">
    <style>
        .engagement-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .engagement-table th,
        .engagement-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .dark-mode .engagement-table th,
        .dark-mode .engagement-table td {
            border-bottom-color: #444;
        }
        .engagement-table th {
            background-color: #f5f5f5;
            font-weight: bold;
        }
        .dark-mode .engagement-table th {
            background-color: #2d2d2d;
        }
        .engagement-table tr:hover {
            background-color: #f9f9f9;
        }
        .dark-mode .engagement-table tr:hover {
            background-color: #333;
        }
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
            background-color: var(--button-hover-color);
        }
        .action-buttons {
            display: inline-flex;
            gap: 5px;
            background-color: transparent !important;
        }
        .action-button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            box-sizing: border-box;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-family: inherit;
            font-size: 0.9em;
            line-height: 1.6;
            text-decoration: none;
            color: white;
            white-space: nowrap;
        }
        .edit-button {
            background-color: #2196F3;
        }
        .delete-button {
            background-color: #f44336;
        }
        .view-button {
            background-color: #4CAF50;
        }
        .action-button:hover {
            background-color: var(--button-hover-color);
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
    <table class="engagement-table">
        <thead>
            <tr>
                <th>Organization</th>
                <th>Event Dates</th>
                <th>Type</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
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
                    <td>
                        <div class="action-buttons">
                            <a href="view_engagement.php?id=<?php echo $row['id']; ?>" class="action-button view-button">View</a>
                            <?php if ($user_role === 'admin' || $user_role === 'editor'): ?>
                                <a href="edit_engagement.php?id=<?php echo $row['id']; ?>" class="action-button edit-button">Edit</a>
                            <?php endif; ?>
                            <?php if ($user_role === 'admin'): ?>
                                <form method="post" action="engagements.php" onsubmit="return confirm('Are you sure you want to delete this engagement?');">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="engagement_id" value="<?php echo (int) $row['id']; ?>">
                                    <button type="submit" name="delete_engagement" class="action-button delete-button">Delete</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endwhile; ?>
        </tbody>
    </table>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
