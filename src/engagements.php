<?php
include 'config.php';
include 'functions.php';
startSecureSession();
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';

$list_status = ($_POST['list_status'] ?? $_GET['status'] ?? '') === 'archived'
    ? 'archived'
    : 'active';
$show_archived = $list_status === 'archived';

// Handle archive, restore, and permanent deletion through authenticated POST requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($user_role !== 'admin') {
        http_response_code(403);
        exit('Forbidden.');
    }

    requireValidCsrfToken();
    $engagement_id = filter_input(INPUT_POST, 'engagement_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $action_succeeded = false;

    if ($engagement_id && $action === 'archive') {
        $action_succeeded = archiveEntity($conn, 'engagement', $engagement_id);
        $action_message = 'Event archived.';
    } elseif ($engagement_id && $action === 'restore') {
        $action_succeeded = restoreEntity($conn, 'engagement', $engagement_id);
        $action_message = 'Event restored.';
    } elseif ($engagement_id && $action === 'delete') {
        $action_succeeded = permanentlyDeleteEntity($conn, 'engagement', $engagement_id);
        $action_message = 'Event permanently deleted.';
    } else {
        http_response_code(400);
        exit('Invalid event action.');
    }

    if ($action_succeeded) {
        $_SESSION['engagement_action_message'] = $action_message;
    } else {
        $_SESSION['engagement_action_error'] = 'Unable to update the event. Please try again.';
    }

    header('Location: engagements.php?' . http_build_query(['status' => $list_status]));
    exit();
}

$action_message = $_SESSION['engagement_action_message'] ?? '';
$action_error = $_SESSION['engagement_action_error'] ?? '';
unset($_SESSION['engagement_action_message'], $_SESSION['engagement_action_error']);

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
$archive_value = $show_archived ? 1 : 0;
$query = "SELECT e.*, o.organization_name 
          FROM engagements e 
          LEFT JOIN organizations o ON e.organization_id = o.id 
          WHERE e.is_deleted = {$archive_value}
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
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.3.5">
    <style>
        .engagement-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        .page-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 15px;
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
            background-color: var(--button-neutral-color);
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
        .sort-button.active {
            background-color: var(--button-edit-color) !important;
        }
        .action-buttons {
            display: inline-flex;
            gap: 5px;
            background-color: transparent !important;
        }
        .action-buttons form {
            margin: 0;
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
            background-color: var(--button-edit-color);
        }
        .delete-button {
            background-color: var(--button-delete-color);
        }
        .view-button {
            background-color: var(--button-view-color);
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
    <div class="page-heading">
        <h1><?php echo $show_archived ? 'Archived Engagements' : 'Engagements'; ?></h1>
        <?php if (!$show_archived && ($user_role === 'admin' || $user_role === 'editor')): ?>
            <a href="index.php" class="button-add">Add Engagement</a>
        <?php endif; ?>
    </div>

    <?php if ($action_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($action_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($action_error !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($action_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <div class="sort-buttons">
        <a href="?status=active&amp;sort_by=<?php echo $sort_column; ?>&amp;date_sort=<?php echo $date_sort; ?>&amp;status_sort=<?php echo $status_sort; ?>&amp;org_sort=<?php echo $org_sort; ?>"
           class="sort-button<?php echo !$show_archived ? ' active' : ''; ?>">Active</a>
        <a href="?status=archived&amp;sort_by=<?php echo $sort_column; ?>&amp;date_sort=<?php echo $date_sort; ?>&amp;status_sort=<?php echo $status_sort; ?>&amp;org_sort=<?php echo $org_sort; ?>"
           class="sort-button<?php echo $show_archived ? ' active' : ''; ?>">Archived</a>
        <a href="?status=<?php echo $list_status; ?>&sort_by=org&org_sort=<?php echo $org_sort === 'asc' ? 'desc' : 'asc'; ?>&date_sort=<?php echo $date_sort; ?>&status_sort=<?php echo $status_sort; ?>"
           class="sort-button<?php echo $sort_column === 'org' ? ' active' : ''; ?>"
           <?php echo $sort_column === 'org' ? 'aria-current="true"' : ''; ?>>
            Organization <?php echo $org_sort === 'asc' ? '↑' : '↓'; ?>
        </a>
        <a href="?status=<?php echo $list_status; ?>&sort_by=date&date_sort=<?php echo $date_sort === 'asc' ? 'desc' : 'asc'; ?>&status_sort=<?php echo $status_sort; ?>&org_sort=<?php echo $org_sort; ?>"
           class="sort-button<?php echo $sort_column === 'date' ? ' active' : ''; ?>"
           <?php echo $sort_column === 'date' ? 'aria-current="true"' : ''; ?>>
            Date <?php echo $date_sort === 'asc' ? '↑' : '↓'; ?>
        </a>
        <a href="?status=<?php echo $list_status; ?>&sort_by=status&status_sort=<?php echo $status_sort === 'asc' ? 'desc' : 'asc'; ?>&date_sort=<?php echo $date_sort; ?>&org_sort=<?php echo $org_sort; ?>"
           class="sort-button<?php echo $sort_column === 'status' ? ' active' : ''; ?>"
           <?php echo $sort_column === 'status' ? 'aria-current="true"' : ''; ?>>
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
                            <?php if (!$show_archived && ($user_role === 'admin' || $user_role === 'editor')): ?>
                                <a href="edit_engagement.php?id=<?php echo $row['id']; ?>" class="action-button edit-button">Edit</a>
                            <?php endif; ?>
                            <?php if ($user_role === 'admin'): ?>
                                <?php if ($show_archived): ?>
                                    <form method="post" action="engagements.php">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="engagement_id" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="list_status" value="archived">
                                        <input type="hidden" name="action" value="restore">
                                        <button type="submit" class="action-button restore-button">Restore</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="engagements.php">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="engagement_id" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="list_status" value="active">
                                        <input type="hidden" name="action" value="archive">
                                        <button type="submit" class="action-button archive-button">Archive</button>
                                    </form>
                                <?php endif; ?>
                                <form method="post" action="engagements.php"
                                      data-delete-confirmation="Permanently delete this event and its presentations?"
                                      <?php if ($show_archived): ?>data-archive-button-label="Keep archived"<?php else: ?>data-archive-action="archive"<?php endif; ?>>
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="engagement_id" value="<?php echo (int) $row['id']; ?>">
                                    <input type="hidden" name="list_status" value="<?php echo $list_status; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="action-button delete-button">Delete</button>
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
