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
    requireValidCsrfToken();
    $engagement_id = filter_input(INPUT_POST, 'engagement_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $action_succeeded = false;

    if (in_array($action, ['archive', 'restore'], true) && !canArchiveEntries($user_role)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    if ($action === 'delete' && !canDeleteEntries($user_role)) {
        http_response_code(403);
        exit('Forbidden.');
    }

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

$search = trim($_GET['q'] ?? '');

$summary = [
    'upcoming' => 0,
    'review' => 0,
    'confirmed' => 0,
    'archived' => 0,
];
$summary_result = $conn->query(
    "SELECT
        SUM(is_deleted = 0 AND event_end_date >= CURDATE()) AS upcoming_count,
        SUM(is_deleted = 0 AND confirmation_status = 'under_review') AS review_count,
        SUM(is_deleted = 0 AND confirmation_status = 'confirmed') AS confirmed_count,
        SUM(is_deleted = 1) AS archived_count
     FROM engagements"
);
if ($summary_result) {
    $summary_row = $summary_result->fetch_assoc();
    $summary = [
        'upcoming' => (int) ($summary_row['upcoming_count'] ?? 0),
        'review' => (int) ($summary_row['review_count'] ?? 0),
        'confirmed' => (int) ($summary_row['confirmed_count'] ?? 0),
        'archived' => (int) ($summary_row['archived_count'] ?? 0),
    ];
}

// Prepare and execute the query
$archive_value = $show_archived ? 1 : 0;
$query = "SELECT e.*, o.organization_name
          FROM engagements e 
          LEFT JOIN organizations o ON e.organization_id = o.id 
          WHERE e.is_deleted = {$archive_value}";
if ($search !== '') {
    $query .= " AND (
        e.event_title LIKE ?
        OR o.organization_name LIKE ?
        OR e.event_type LIKE ?
        OR e.confirmation_status LIKE ?
    )";
}
$query .= "
          ORDER BY {$order_by} {$order_direction}";

$query_stmt = null;
if ($search !== '') {
    $query_stmt = $conn->prepare($query);
    $search_pattern = '%' . $search . '%';
    $query_stmt->bind_param('ssss', $search_pattern, $search_pattern, $search_pattern, $search_pattern);
    $query_stmt->execute();
    $result = $query_stmt->get_result();
} else {
    $result = $conn->query($query);
}
if (!$result) {
    die("Database error: " . $conn->error);
}

$format_date_range = static function ($start, $end) {
    $start_timestamp = strtotime((string) $start);
    $end_timestamp = strtotime((string) $end);
    if (!$start_timestamp || !$end_timestamp) return trim((string) $start . ' – ' . (string) $end);
    $formatted_start = date('Y.m.d', $start_timestamp);
    if ($start === $end) return $formatted_start;
    return $formatted_start . ' – ' . date('Y.m.d', $end_timestamp);
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Engagements - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.17">
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
        .list-controls {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
            margin: 15px 0 20px;
        }
        .control-group {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
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
            font-weight: var(--font-weight-bold);
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
        <div>
            <h1><?php echo $show_archived ? 'Archived Engagements' : 'Engagements'; ?></h1>
            <p class="page-intro">Manage upcoming events and speaking commitments.</p>
        </div>
        <?php if (!$show_archived && ($user_role === 'admin' || $user_role === 'editor')): ?>
            <a href="index.php" class="button-add">+ New engagement</a>
        <?php endif; ?>
    </div>

    <?php if ($action_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($action_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($action_error !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($action_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <div class="summary-grid" aria-label="Engagement summary">
        <div class="summary-card summary-upcoming"><span class="summary-icon" aria-hidden="true">📅</span><span><small>Upcoming</small><strong><?php echo $summary['upcoming']; ?></strong></span></div>
        <div class="summary-card summary-review"><span class="summary-icon" aria-hidden="true">◷</span><span><small>Needs review</small><strong><?php echo $summary['review']; ?></strong></span></div>
        <div class="summary-card summary-confirmed"><span class="summary-icon" aria-hidden="true">✓</span><span><small>Confirmed</small><strong><?php echo $summary['confirmed']; ?></strong></span></div>
        <div class="summary-card summary-archived"><span class="summary-icon" aria-hidden="true">□</span><span><small>Archived</small><strong><?php echo $summary['archived']; ?></strong></span></div>
    </div>

    <div class="list-controls engagement-controls">
        <form method="get" action="engagements.php" class="list-search-form" role="search">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($list_status, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sort_column, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="date_sort" value="<?php echo htmlspecialchars($date_sort, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="status_sort" value="<?php echo htmlspecialchars($status_sort, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="org_sort" value="<?php echo htmlspecialchars($org_sort, ENT_QUOTES, 'UTF-8'); ?>">
            <label class="visually-hidden" for="engagement-search">Search engagements</label>
            <span class="search-icon" aria-hidden="true">⌕</span>
            <input type="search" id="engagement-search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search engagements">
            <?php if ($search !== ''): ?><a href="engagements.php?status=<?php echo urlencode($list_status); ?>" class="clear-search">Clear</a><?php endif; ?>
        </form>
        <div class="control-group" aria-label="Engagement archive status">
            <a href="?status=active&amp;sort_by=<?php echo $sort_column; ?>&amp;date_sort=<?php echo $date_sort; ?>&amp;status_sort=<?php echo $status_sort; ?>&amp;org_sort=<?php echo $org_sort; ?>&amp;q=<?php echo urlencode($search); ?>"
               class="sort-button<?php echo !$show_archived ? ' active' : ''; ?>">Active</a>
            <a href="?status=archived&amp;sort_by=<?php echo $sort_column; ?>&amp;date_sort=<?php echo $date_sort; ?>&amp;status_sort=<?php echo $status_sort; ?>&amp;org_sort=<?php echo $org_sort; ?>&amp;q=<?php echo urlencode($search); ?>"
               class="sort-button<?php echo $show_archived ? ' active' : ''; ?>">Archived</a>
        </div>

        <div class="control-group" aria-label="Engagement sort order">
            <span class="control-label">Sort:</span>
            <div class="sort-buttons">
                <a href="?status=<?php echo $list_status; ?>&sort_by=org&org_sort=<?php echo $org_sort === 'asc' ? 'desc' : 'asc'; ?>&date_sort=<?php echo $date_sort; ?>&status_sort=<?php echo $status_sort; ?>&q=<?php echo urlencode($search); ?>"
                   class="sort-button<?php echo $sort_column === 'org' ? ' active' : ''; ?>"
                   <?php echo $sort_column === 'org' ? 'aria-current="true"' : ''; ?>>
                    Organization <?php echo $org_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
                <a href="?status=<?php echo $list_status; ?>&sort_by=date&date_sort=<?php echo $date_sort === 'asc' ? 'desc' : 'asc'; ?>&status_sort=<?php echo $status_sort; ?>&org_sort=<?php echo $org_sort; ?>&q=<?php echo urlencode($search); ?>"
                   class="sort-button<?php echo $sort_column === 'date' ? ' active' : ''; ?>"
                   <?php echo $sort_column === 'date' ? 'aria-current="true"' : ''; ?>>
                    Date <?php echo $date_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
                <a href="?status=<?php echo $list_status; ?>&sort_by=status&status_sort=<?php echo $status_sort === 'asc' ? 'desc' : 'asc'; ?>&date_sort=<?php echo $date_sort; ?>&org_sort=<?php echo $org_sort; ?>&q=<?php echo urlencode($search); ?>"
                   class="sort-button<?php echo $sort_column === 'status' ? ' active' : ''; ?>"
                   <?php echo $sort_column === 'status' ? 'aria-current="true"' : ''; ?>>
                    Status <?php echo $status_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
            </div>
        </div>
    </div>
    <?php if ($search !== ''): ?>
        <p class="result-context">Showing results for “<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>”.</p>
    <?php endif; ?>
    <table class="engagement-table">
        <thead>
            <tr>
                <th>Event Title</th>
                <th>Organization</th>
                <th>Event Dates</th>
                <th>Type</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($result->num_rows === 0): ?>
                <tr><td colspan="6" class="empty-state">No engagements match the current view.</td></tr>
            <?php endif; ?>
            <?php while ($row = $result->fetch_assoc()): ?>
                <tr>
                    <td><a class="record-link" href="view_engagement.php?id=<?php echo (int) $row['id']; ?>"><?php echo htmlspecialchars($row['event_title'] ?: $row['organization_name']); ?></a></td>
                    <td><?php echo htmlspecialchars($row['organization_name']); ?></td>
                    <td class="engagement-dates"><?php echo htmlspecialchars($format_date_range($row['event_start_date'], $row['event_end_date'])); ?></td>
                    <td><?php echo htmlspecialchars(ucwords($row['event_type'])); ?></td>
                    <td class="engagement-status"><?php
                        $status = $row['confirmation_status'];
                        $status_class = 'status-' . str_replace('_', '-', $status);
                        $display_status = str_replace('_', ' ', $status);
                        echo "<span class='{$status_class}'>" . htmlspecialchars($display_status) . "</span>";
                    ?></td>
                    <td>
                        <div class="action-buttons">
                            <a href="view_engagement.php?id=<?php echo $row['id']; ?>" class="action-button action-icon-button view-button" aria-label="View event" title="View" data-tooltip="View"><?php echo actionIconSvg('view'); ?></a>
                            <?php if (!$show_archived && ($user_role === 'admin' || $user_role === 'editor')): ?>
                                <a href="edit_engagement.php?id=<?php echo $row['id']; ?>" class="action-button action-icon-button edit-button" aria-label="Edit event" title="Edit" data-tooltip="Edit"><?php echo actionIconSvg('edit'); ?></a>
                            <?php endif; ?>
                            <?php if (canArchiveEntries($user_role)): ?>
                                <?php if ($show_archived): ?>
                                    <form method="post" action="engagements.php">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="engagement_id" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="list_status" value="archived">
                                        <input type="hidden" name="action" value="restore">
                                        <button type="submit" class="action-button action-icon-button restore-button" aria-label="Restore event" title="Restore" data-tooltip="Restore"><?php echo actionIconSvg('restore'); ?></button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="engagements.php">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="engagement_id" value="<?php echo (int) $row['id']; ?>">
                                        <input type="hidden" name="list_status" value="active">
                                        <input type="hidden" name="action" value="archive">
                                        <button type="submit" class="action-button action-icon-button archive-button" aria-label="Archive event" title="Archive" data-tooltip="Archive"><?php echo actionIconSvg('archive'); ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (canDeleteEntries($user_role)): ?>
                                <form method="post" action="engagements.php"
                                      data-delete-confirmation="Permanently delete this event and its presentations?"
                                      <?php if ($show_archived): ?>data-archive-button-label="Keep archived"<?php else: ?>data-archive-action="archive"<?php endif; ?>>
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="engagement_id" value="<?php echo (int) $row['id']; ?>">
                                    <input type="hidden" name="list_status" value="<?php echo $list_status; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="action-button action-icon-button delete-button" aria-label="Delete event" title="Delete" data-tooltip="Delete"><?php echo actionIconSvg('delete'); ?></button>
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
<?php if ($query_stmt) $query_stmt->close(); ?>
