<?php
require_once __DIR__ . '/bootstrap.php';
$conn = applicationDatabaseConnection();
include 'engagement_search_helpers.php';
include 'two_factor_helpers.php';
include 'engagement_lifecycle_helpers.php';
startSecureSession();
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';
$allowed_page_sizes = [20, 50, 100];

$list_status = \Dnr\Http\RequestInput::string(
    $_POST,
    'list_status',
    \Dnr\Http\RequestInput::string($_GET, 'status')
) === 'archived'
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
    if ($action === 'delete') {
        requireRecentAdminElevation('engagements.php?' . http_build_query(['status' => $list_status]));
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

// Retrieve engagements with organization name. Every value is allowlisted
// before it is used in SQL or reflected into a link.
$date_sort = \Dnr\Http\RequestInput::string($_GET, 'date_sort') === 'desc' ? 'desc' : 'asc';
$status_sort = \Dnr\Http\RequestInput::string($_GET, 'status_sort') === 'desc' ? 'desc' : 'asc';
$lifecycle_sort = \Dnr\Http\RequestInput::string($_GET, 'lifecycle_sort') === 'desc'
    ? 'desc'
    : 'asc';
$org_sort = \Dnr\Http\RequestInput::string($_GET, 'org_sort') === 'desc' ? 'desc' : 'asc';
$lifecycle_filter = \Dnr\Http\RequestInput::enum(
    $_GET,
    'lifecycle',
    ['all', 'active', 'postponed', 'canceled', 'completed'],
    'all'
);

// Determine which column to sort by based on which button was clicked
$sort_column = \Dnr\Http\RequestInput::enum(
    $_GET,
    'sort_by',
    ['date', 'status', 'lifecycle', 'org'],
    'date'
);

// Determine sort order based on column
if ($sort_column === 'date') {
    $sort_order = $date_sort;
} elseif ($sort_column === 'status') {
    $sort_order = $status_sort;
} elseif ($sort_column === 'lifecycle') {
    $sort_order = $lifecycle_sort;
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
} elseif ($sort_column === 'lifecycle') {
    $order_by = 'e.lifecycle_status';
} elseif ($sort_column === 'org') {
    $order_by = 'o.organization_name';
} else {
    $order_by = 'e.event_start_date';
}
$order_direction = ($sort_order === 'asc' ? 'ASC' : 'DESC');

$search = \Dnr\Http\RequestInput::string($_GET, 'q', '', 256);
$search_plan = buildEngagementSearchPlan($search);
$search = $search_plan['search'];
$has_search_terms = $search_plan['sql'] !== '';
if (!$has_search_terms) {
    $search = '';
}
$requested_page_size = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT);
$page_size = in_array($requested_page_size, $allowed_page_sizes, true) ? $requested_page_size : 20;
$cursor = decodePaginationCursor(
    \Dnr\Http\RequestInput::string($_GET, 'cursor'),
    ['value', 'id']
);
$list_url = static function (array $overrides = []) use (
    $list_status,
    $sort_column,
    $date_sort,
    $status_sort,
    $lifecycle_sort,
    $org_sort,
    $lifecycle_filter,
    $search,
    $page_size
) {
    return '?' . http_build_query(array_merge([
        'status' => $list_status,
        'sort_by' => $sort_column,
        'date_sort' => $date_sort,
        'status_sort' => $status_sort,
        'lifecycle_sort' => $lifecycle_sort,
        'org_sort' => $org_sort,
        'lifecycle' => $lifecycle_filter,
        'q' => $search,
        'per_page' => $page_size,
    ], $overrides));
};

$summary = [
    'active' => 0,
    'postponed' => 0,
    'canceled' => 0,
    'completed' => 0,
    'archived' => 0,
];
$summary_result = $conn->query(
    "SELECT
        SUM(is_deleted = 0 AND lifecycle_status = 'active') AS active_count,
        SUM(is_deleted = 0 AND lifecycle_status = 'postponed') AS postponed_count,
        SUM(is_deleted = 0 AND lifecycle_status = 'canceled') AS canceled_count,
        SUM(is_deleted = 0 AND lifecycle_status = 'completed') AS completed_count,
        SUM(is_deleted = 1) AS archived_count
     FROM engagements"
);
if ($summary_result) {
    $summary_row = $summary_result->fetch_assoc();
    $summary = [
        'active' => (int) ($summary_row['active_count'] ?? 0),
        'postponed' => (int) ($summary_row['postponed_count'] ?? 0),
        'canceled' => (int) ($summary_row['canceled_count'] ?? 0),
        'completed' => (int) ($summary_row['completed_count'] ?? 0),
        'archived' => (int) ($summary_row['archived_count'] ?? 0),
    ];
}

// Prepare and execute the query
$archive_value = $show_archived ? 1 : 0;
$where = "e.is_deleted = {$archive_value}";
$query_values = [];
$bind_types = '';
if ($lifecycle_filter !== 'all') {
    $where .= ' AND e.lifecycle_status = ?';
    $query_values[] = $lifecycle_filter;
    $bind_types .= 's';
}
if ($has_search_terms) {
    $where .= ' AND (' . $search_plan['sql'] . ')';
    $query_values = array_merge($query_values, $search_plan['patterns']);
    $bind_types .= str_repeat('s', count($search_plan['patterns']));
}
if ($cursor !== null && ctype_digit((string) $cursor['id'])) {
    $comparison = $order_direction === 'ASC' ? '>' : '<';
    $where .= " AND ({$order_by} {$comparison} ?
        OR ({$order_by} = ? AND e.id {$comparison} ?))";
    $query_values[] = (string) $cursor['value'];
    $query_values[] = (string) $cursor['value'];
    $query_values[] = (int) $cursor['id'];
    $bind_types .= 'ssi';
} else {
    $cursor = null;
}
$query_limit = $page_size + 1;

$query = "SELECT e.id, e.event_title, e.event_start_date, e.event_end_date,
                 e.event_type, e.event_type_other, e.confirmation_status,
                 e.lifecycle_status,
                 o.organization_name,
                 financial_report.engagement_id IS NOT NULL AS financially_closed
          FROM engagements e
          LEFT JOIN organizations o ON e.organization_id = o.id
          LEFT JOIN engagement_financial_reports financial_report
              ON financial_report.engagement_id = e.id
          WHERE {$where}";
$query .= "
          ORDER BY {$order_by} {$order_direction}, e.id {$order_direction}
          LIMIT ?";

$query_stmt = $conn->prepare($query);
if (!$query_stmt) {
    applicationLog('error', 'Unable to prepare the engagement list', ['error' => $conn->error]);
    http_response_code(503);
    exit('Engagements are temporarily unavailable.');
}
$query_values[] = $query_limit;
$bind_types .= 'i';
$bind_params = [$bind_types];
foreach ($query_values as &$query_value) $bind_params[] = &$query_value;
unset($query_value);
$query_stmt->bind_param(...$bind_params);
if (!$query_stmt->execute()) {
    applicationLog('error', 'Unable to run the engagement list', ['error' => $query_stmt->error]);
    http_response_code(500);
    exit('Unable to retrieve engagements.');
}
$engagement_rows = $query_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$has_more_engagements = count($engagement_rows) > $page_size;
if ($has_more_engagements) {
    array_pop($engagement_rows);
}
$next_cursor = null;
if ($has_more_engagements && $engagement_rows !== []) {
    $last_engagement = $engagement_rows[array_key_last($engagement_rows)];
    $cursor_field = match ($sort_column) {
        'status' => 'confirmation_status',
        'lifecycle' => 'lifecycle_status',
        'org' => 'organization_name',
        default => 'event_start_date',
    };
    $next_cursor = encodePaginationCursor([
        'value' => (string) $last_engagement[$cursor_field],
        'id' => (int) $last_engagement['id'],
    ]);
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
<?php renderPageHead('Engagements - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/engagements.min.css',
    3 => 'assets/css/pages/engagement_lifecycle.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <div class="page-heading">
        <div>
            <h1><?php echo $show_archived ? 'Archived Engagements' : 'Engagements'; ?></h1>
            <p class="page-intro">Manage event lifecycle, confirmation, schedules, and speaking commitments.</p>
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
        <div class="summary-card summary-confirmed"><span class="summary-icon" aria-hidden="true">◆</span><span><small>Active</small><strong><?php echo $summary['active']; ?></strong></span></div>
        <div class="summary-card summary-review"><span class="summary-icon" aria-hidden="true">Ⅱ</span><span><small>Postponed</small><strong><?php echo $summary['postponed']; ?></strong></span></div>
        <div class="summary-card summary-archived"><span class="summary-icon" aria-hidden="true">×</span><span><small>Canceled</small><strong><?php echo $summary['canceled']; ?></strong></span></div>
        <div class="summary-card summary-work-in-progress"><span class="summary-icon" aria-hidden="true">✓</span><span><small>Completed</small><strong><?php echo $summary['completed']; ?></strong></span></div>
    </div>

    <div class="list-controls engagement-controls">
        <form method="get" action="engagements.php" class="list-search-form" role="search">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($list_status, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="sort_by" value="<?php echo htmlspecialchars($sort_column, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="date_sort" value="<?php echo htmlspecialchars($date_sort, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="status_sort" value="<?php echo htmlspecialchars($status_sort, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="lifecycle_sort" value="<?php echo htmlspecialchars($lifecycle_sort, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="org_sort" value="<?php echo htmlspecialchars($org_sort, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="lifecycle" value="<?php echo htmlspecialchars($lifecycle_filter, ENT_QUOTES, 'UTF-8'); ?>">
            <label class="visually-hidden" for="engagement-search">title, organization, contact, chron log text, "and"/or user</label>
            <span class="search-icon" aria-hidden="true">⌕</span>
            <input type="search" id="engagement-search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="title, organization, contact, chron log text, &quot;and&quot;/or user">
            <?php if ($search !== ''): ?><a href="<?php echo htmlspecialchars($list_url(['q' => '', 'cursor' => null]), ENT_QUOTES, 'UTF-8'); ?>" class="clear-search">Clear</a><?php endif; ?>
        </form>
        <div class="control-group" aria-label="Engagement archive status">
            <a href="<?php echo htmlspecialchars($list_url(['status' => 'active']), ENT_QUOTES, 'UTF-8'); ?>"
               class="sort-button<?php echo !$show_archived ? ' active' : ''; ?>">Current</a>
            <a href="<?php echo htmlspecialchars($list_url(['status' => 'archived']), ENT_QUOTES, 'UTF-8'); ?>"
               class="sort-button<?php echo $show_archived ? ' active' : ''; ?>">Archived (<?php echo $summary['archived']; ?>)</a>
        </div>

        <div class="control-group" aria-label="Engagement lifecycle filter">
            <span class="control-label">Lifecycle:</span>
            <?php foreach (['all' => 'All'] + engagementLifecycleStatuses() as $lifecycle_value => $lifecycle_label): ?>
                <a href="<?php echo htmlspecialchars($list_url(['lifecycle' => $lifecycle_value, 'cursor' => null]), ENT_QUOTES, 'UTF-8'); ?>"
                   class="sort-button<?php echo $lifecycle_filter === $lifecycle_value ? ' active' : ''; ?>"
                   <?php echo $lifecycle_filter === $lifecycle_value ? 'aria-current="true"' : ''; ?>><?php echo htmlspecialchars($lifecycle_label, ENT_QUOTES, 'UTF-8'); ?></a>
            <?php endforeach; ?>
        </div>

        <div class="control-group" aria-label="Engagement sort order">
            <span class="control-label">Sort:</span>
            <div class="sort-buttons">
                <a href="<?php echo htmlspecialchars($list_url(['sort_by' => 'org', 'org_sort' => $org_sort === 'asc' ? 'desc' : 'asc']), ENT_QUOTES, 'UTF-8'); ?>"
                   class="sort-button<?php echo $sort_column === 'org' ? ' active' : ''; ?>"
                   <?php echo $sort_column === 'org' ? 'aria-current="true"' : ''; ?>>
                    Organization <?php echo $org_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
                <a href="<?php echo htmlspecialchars($list_url(['sort_by' => 'date', 'date_sort' => $date_sort === 'asc' ? 'desc' : 'asc']), ENT_QUOTES, 'UTF-8'); ?>"
                   class="sort-button<?php echo $sort_column === 'date' ? ' active' : ''; ?>"
                   <?php echo $sort_column === 'date' ? 'aria-current="true"' : ''; ?>>
                    Date <?php echo $date_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
                <a href="<?php echo htmlspecialchars($list_url(['sort_by' => 'status', 'status_sort' => $status_sort === 'asc' ? 'desc' : 'asc']), ENT_QUOTES, 'UTF-8'); ?>"
                   class="sort-button<?php echo $sort_column === 'status' ? ' active' : ''; ?>"
                   <?php echo $sort_column === 'status' ? 'aria-current="true"' : ''; ?>>
                    Confirmation <?php echo $status_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
                <a href="<?php echo htmlspecialchars($list_url(['sort_by' => 'lifecycle', 'lifecycle_sort' => $lifecycle_sort === 'asc' ? 'desc' : 'asc']), ENT_QUOTES, 'UTF-8'); ?>"
                   class="sort-button<?php echo $sort_column === 'lifecycle' ? ' active' : ''; ?>"
                   <?php echo $sort_column === 'lifecycle' ? 'aria-current="true"' : ''; ?>>
                    Lifecycle <?php echo $lifecycle_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
            </div>
        </div>
    </div>
    <?php if ($search !== ''): ?>
        <p class="result-context">Showing engagements matching “<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>”.</p>
    <?php endif; ?>
    <table class="engagement-table">
        <thead>
            <tr>
                <th>Event Title</th>
                <th>Event Date(s)</th>
                <th>Lifecycle</th>
                <th>Confirmation</th>
                <th>Closeout</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($engagement_rows === []): ?>
                <tr><td colspan="6" class="empty-state">No engagements match the current view.</td></tr>
            <?php endif; ?>
            <?php foreach ($engagement_rows as $row): ?>
                <tr>
                    <td class="engagement-title"><a class="record-link" href="view_engagement.php?id=<?php echo (int) $row['id']; ?>"><?php echo htmlspecialchars($row['event_title'] ?: $row['organization_name']); ?></a></td>
                    <td class="engagement-dates"><?php echo htmlspecialchars($format_date_range($row['event_start_date'], $row['event_end_date'])); ?></td>
                    <td class="engagement-lifecycle"><span class="lifecycle-badge lifecycle-<?php echo htmlspecialchars((string) $row['lifecycle_status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(engagementLifecycleLabel($row['lifecycle_status']), ENT_QUOTES, 'UTF-8'); ?></span></td>
                    <td class="engagement-status"><?php
                        $status = $row['confirmation_status'];
                        $status_class = 'status-' . str_replace('_', '-', $status);
                        $display_status = str_replace('_', ' ', $status);
                        echo "<span class='{$status_class}'>" . htmlspecialchars($display_status) . "</span>";
                    ?></td>
                    <?php $is_financially_closed = !empty($row['financially_closed']); ?>
                    <?php $closeout_applicable = !in_array((string) $row['lifecycle_status'], ['postponed', 'canceled'], true); ?>
                    <td class="engagement-closeout">
                        <span class="event-closeout-badge <?php echo $is_financially_closed ? 'is-closed' : ($closeout_applicable ? 'is-open' : 'is-not-applicable'); ?>"
                              aria-label="Financial closeout: <?php echo $is_financially_closed ? 'Closed' : ($closeout_applicable ? 'Open' : 'Not applicable'); ?>">
                            <?php echo $is_financially_closed ? 'Closed' : ($closeout_applicable ? 'Open' : 'N/A'); ?>
                        </span>
                    </td>
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
                                      data-delete-confirmation="Permanently delete this event, its presentations, and its Chron entries?"
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
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php if ($cursor !== null || $next_cursor !== null): ?>
        <nav class="pagination pagination-with-size" aria-label="Engagement pages">
            <span class="pagination-status">Showing up to <?php echo $page_size; ?> engagements</span>
            <div class="pagination-actions">
                <?php if ($cursor !== null): ?><a class="filter-button" href="<?php echo htmlspecialchars($list_url(), ENT_QUOTES, 'UTF-8'); ?>">First page</a><?php endif; ?>
                <?php if ($next_cursor !== null): ?><a class="filter-button" href="<?php echo htmlspecialchars($list_url(['cursor' => $next_cursor]), ENT_QUOTES, 'UTF-8'); ?>">Next</a><?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
<?php if ($query_stmt) $query_stmt->close(); ?>
