<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/record_workspace_helpers.php';
require_once __DIR__ . '/financial_report_helpers.php';
$conn = applicationDatabaseConnection();
include 'two_factor_helpers.php';
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
    $org_id = filter_input(INPUT_POST, 'organization_id', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $action_succeeded = false;
    $action_error = '';

    if (in_array($action, ['archive', 'restore'], true) && !canArchiveEntries($user_role)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    if ($action === 'delete' && !canDeleteEntries($user_role)) {
        http_response_code(403);
        exit('Forbidden.');
    }
    if ($action === 'delete') {
        requireRecentAdminElevation('organizations.php?' . http_build_query(['status' => $list_status]));
    }

    if ($org_id && $action === 'archive') {
        $dependencies = organizationActiveDependencyCounts($conn, $org_id);
        $dependency_message = $dependencies === null
            ? ''
            : organizationArchiveDependencyMessage($dependencies);
        if ($dependency_message !== '') {
            $action_error = $dependency_message;
        } else {
            $action_succeeded = archiveEntity($conn, 'organization', $org_id);
        }
        $action_message = 'Organization archived.';
    } elseif ($org_id && $action === 'restore') {
        $action_succeeded = restoreEntity($conn, 'organization', $org_id);
        $action_message = 'Organization restored.';
    } elseif ($org_id && $action === 'delete') {
        $action_succeeded = permanentlyDeleteEntity($conn, 'organization', $org_id);
        $action_message = 'Organization permanently deleted.';
    } else {
        http_response_code(400);
        exit('Invalid organization action.');
    }

    if ($action_succeeded) {
        $_SESSION['organization_action_message'] = $action_message;
    } else {
        $_SESSION['organization_action_error'] = $action_error !== ''
            ? $action_error
            : 'Unable to update the organization. Please try again.';
    }

    header('Location: ' . safeRecordReturnUrl($_POST['return_to'] ?? null, 'organizations.php?' . http_build_query(['status' => $list_status])));
    exit();
}

$action_message = $_SESSION['organization_action_message'] ?? '';
$action_error = $_SESSION['organization_action_error'] ?? '';
unset($_SESSION['organization_action_message'], $_SESSION['organization_action_error']);
generateCsrfToken();
releaseApplicationSessionLock();

// Keep sort columns and directions allowlisted before building SQL.
$name_sort = strtolower(\Dnr\Http\RequestInput::string($_GET, 'name_sort')) === 'desc'
    ? 'desc'
    : 'asc';
$last_giving_sort = \Dnr\Http\RequestInput::string($_GET, 'last_giving_sort') === 'asc' ? 'asc' : 'desc';
$lifetime_giving_sort = \Dnr\Http\RequestInput::string($_GET, 'lifetime_giving_sort') === 'asc' ? 'asc' : 'desc';
$sort_column = \Dnr\Http\RequestInput::enum($_GET, 'sort_by', ['name', 'last_giving', 'lifetime_giving'], 'name');
$sort_direction = match ($sort_column) {
    'last_giving' => $last_giving_sort,
    'lifetime_giving' => $lifetime_giving_sort,
    default => $name_sort,
};
$order_direction = $sort_direction === 'asc' ? 'ASC' : 'DESC';
$financial_sort_join = '';
$order_clause = "o.organization_name {$order_direction}, o.id {$order_direction}";
if ($sort_column !== 'name') {
    // Match the displayed financial summaries: only finalized reports count,
    // and the latest event is determined by event dates, not closeout entry time.
    $financial_sort_join = ' LEFT JOIN (
        SELECT engagement.organization_id, report.giving_income_received AS last_event_giving,
               SUM(report.giving_income_received) OVER (PARTITION BY engagement.organization_id) AS lifetime_giving,
               ROW_NUMBER() OVER (
                   PARTITION BY engagement.organization_id
                   ORDER BY engagement.event_end_date DESC, engagement.event_start_date DESC, engagement.id DESC
               ) AS financial_position
        FROM engagement_financial_reports report
        INNER JOIN engagements engagement ON engagement.id = report.engagement_id
    ) financial ON financial.organization_id = o.id AND financial.financial_position = 1';
    $order_clause = $sort_column === 'last_giving'
        ? "financial.last_event_giving IS NULL ASC, financial.last_event_giving {$order_direction}, o.organization_name ASC, o.id ASC"
        : "COALESCE(financial.lifetime_giving, 0) {$order_direction}, o.organization_name ASC, o.id ASC";
}
$search = \Dnr\Http\RequestInput::string($_GET, 'q', '', 256);
$fulltext_query = fulltextSearchQuery($search);
if ($fulltext_query === '') {
    $search = '';
}
$page_size = paginationPageSizePreference('organizations', $_GET['per_page'] ?? null, 20, $allowed_page_sizes);
$cursor = decodePaginationCursor(
    \Dnr\Http\RequestInput::string($_GET, 'cursor'),
    ['name', 'id']
);

// Prepare and execute the query
$archive_value = $show_archived ? 1 : 0;
$search_filter = $fulltext_query === '' ? '' : " AND (
        MATCH(
            o.organization_name, o.notes, o.affiliation, o.distinctives,
            o.email, o.phone, o.physical_city, o.physical_state,
            o.mailing_city, o.mailing_state
        ) AGAINST (? IN BOOLEAN MODE)
        OR EXISTS (
            SELECT 1 FROM contacts searched_contact
            INNER JOIN contact_organizations searched_affiliation
                ON searched_affiliation.contact_id = searched_contact.id
            WHERE searched_affiliation.organization_id = o.id
              AND searched_contact.is_deleted = 0
              AND MATCH(
                  searched_contact.contact_first_name, searched_contact.contact_last_name,
                  searched_contact.contact_email, searched_contact.contact_phone,
                  searched_contact.contact_role_other, searched_contact.contact_notes
              ) AGAINST (? IN BOOLEAN MODE)
        )
    )";
$cursor_filter = '';
$cursor_values = [];
$cursor_types = '';
if ($sort_column === 'name' && $cursor !== null && ctype_digit((string) $cursor['id'])) {
    $comparison = $order_direction === 'ASC' ? '>' : '<';
    $cursor_filter = " AND (o.organization_name {$comparison} ? OR (o.organization_name = ? AND o.id {$comparison} ?))";
    $cursor_values = [(string) $cursor['name'], (string) $cursor['name'], (int) $cursor['id']];
    $cursor_types = 'ssi';
} else {
    $cursor = null;
}
$query_limit = $page_size;
$organization_where = "WHERE o.is_deleted = {$archive_value}{$search_filter}";
$organization_from = "FROM organizations o {$organization_where}";
$search_values = $fulltext_query !== '' ? [$fulltext_query, $fulltext_query] : [];
$pagination = queryPagination($conn, $organization_from, $fulltext_query !== '' ? 'ss' : '', $search_values, $page_size, $_GET['page'] ?? null, $cursor_filter, $cursor_types, $cursor_values);
$current_page = $pagination['page'];
$page_offset = $pagination['offset'];

$query = "SELECT o.id, o.organization_name, o.physical_city, o.physical_state,
                 '' AS contact_names
          FROM organizations o{$financial_sort_join} {$organization_where}
          ORDER BY {$order_clause}
          LIMIT ? OFFSET ?";
$query_stmt = $conn->prepare($query);
if (!$query_stmt) abortApplication(503, 'Organizations are temporarily unavailable.', ['error' => $conn->error]);
$query_types = ($fulltext_query !== '' ? 'ss' : '') . 'ii';
$query_values = array_merge($search_values, [$query_limit, $page_offset]);
$query_bind = [$query_types];
foreach ($query_values as &$query_value) $query_bind[] = &$query_value;
unset($query_value);
$query_stmt->bind_param(...$query_bind);
$query_stmt->execute();
$organizations = $query_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
if ($organizations !== []) {
    $organization_ids = array_map(static fn($row) => (int) $row['id'], $organizations);
    $placeholders = implode(', ', array_fill(0, count($organization_ids), '?'));
    $contacts_stmt = $conn->prepare(
        "SELECT organization_id, contact_name, contact_count
         FROM (
             SELECT co.organization_id,
                    CONCAT_WS(' ', c.contact_first_name, c.contact_last_name) AS contact_name,
                    ROW_NUMBER() OVER (
                        PARTITION BY co.organization_id
                        ORDER BY c.contact_last_name, c.contact_first_name, c.id
                    ) AS contact_position,
                    COUNT(*) OVER (PARTITION BY co.organization_id) AS contact_count
             FROM contacts c
             INNER JOIN contact_organizations co ON co.contact_id = c.id
             WHERE c.is_deleted = 0 AND co.organization_id IN ({$placeholders})
         ) ranked_contacts
         WHERE contact_position <= 3
         ORDER BY organization_id, contact_position"
    );
    if ($contacts_stmt) {
        $contact_types = str_repeat('i', count($organization_ids));
        $contact_bind = [$contact_types];
        foreach ($organization_ids as &$organization_id) $contact_bind[] = &$organization_id;
        unset($organization_id);
        $contacts_stmt->bind_param(...$contact_bind);
        $contacts_stmt->execute();
        $contact_previews = [];
        foreach ($contacts_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $contact_preview) {
            $organization_id = (int) $contact_preview['organization_id'];
            $contact_previews[$organization_id]['names'][] = $contact_preview['contact_name'];
            $contact_previews[$organization_id]['count'] = (int) $contact_preview['contact_count'];
        }
        $contacts_stmt->close();
        foreach ($organizations as &$organization) {
            $preview = $contact_previews[(int) $organization['id']] ?? ['names' => [], 'count' => 0];
            $organization['contact_names'] = implode(', ', $preview['names']);
            if ($preview['count'] > count($preview['names'])) {
                $organization['contact_names'] .= ' (+'
                    . ($preview['count'] - count($preview['names'])) . ' more)';
            }
        }
        unset($organization);
    }

    try {
        $financial_summaries = fetchOrganizationFinancialSummaries($conn, $organization_ids);
    } catch (Throwable $exception) {
        abortApplication(503, 'Organization financial summaries are temporarily unavailable.', [
            'error' => $exception->getMessage(),
        ]);
    }
    foreach ($organizations as &$organization) {
        $financial_summary = $financial_summaries[(int) $organization['id']];
        $organization['lifetime_giving'] = $financial_summary['lifetime_giving'];
        $organization['last_event_giving'] = $financial_summary['last_event_giving'];
    }
    unset($organization);
}

$list_url = static function (array $overrides = []) use (
    $list_status, $name_sort, $last_giving_sort, $lifetime_giving_sort, $sort_column, $search, $page_size
): string {
    $parameters = array_merge([
        'status' => $list_status,
        'name_sort' => $name_sort,
        'last_giving_sort' => $last_giving_sort,
        'lifetime_giving_sort' => $lifetime_giving_sort,
        'sort_by' => $sort_column,
        'per_page' => $page_size,
        'q' => $search !== '' ? $search : null,
    ], $overrides);
    return 'organizations.php?' . http_build_query($parameters);
};
$list_current_url = paginationUrl($list_url(), $current_page, $page_size);
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Organizations'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/organizations.min.css',
  ),
)); ?>
<body class="organizations-body">
<?php include 'templates/header.php'; ?>
<main class="container organizations-page">
    <div class="page-heading organizations-heading">
        <div><h1><?php echo $show_archived ? 'Archived Organizations' : 'Organizations'; ?></h1><p class="page-intro">Keep organization details, locations, and related contacts together.</p></div>
        <?php if (!$show_archived && ($user_role === 'admin' || $user_role === 'editor')): ?>
            <a href="add_organization.php" class="button-add">+ New Organization</a>
        <?php endif; ?>
    </div>

    <?php if ($action_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($action_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($action_error !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($action_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <div class="list-controls">
        <form method="get" action="organizations.php" class="list-search-form" role="search">
            <input type="hidden" name="status" value="<?php echo htmlspecialchars($list_status, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="name_sort" value="<?php echo htmlspecialchars($name_sort, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="last_giving_sort" value="<?php echo $last_giving_sort; ?>">
            <input type="hidden" name="lifetime_giving_sort" value="<?php echo $lifetime_giving_sort; ?>">
            <input type="hidden" name="sort_by" value="<?php echo $sort_column; ?>">
            <input type="hidden" name="per_page" value="<?php echo $page_size; ?>">
            <label class="visually-hidden" for="organization-search">Search organizations</label>
            <span class="search-icon" aria-hidden="true">⌕</span>
            <input type="search" id="organization-search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search organizations">
            <?php if ($search !== ''): ?><a href="<?php echo htmlspecialchars($list_url(['q' => null]), ENT_QUOTES, 'UTF-8'); ?>" class="clear-search">Clear</a><?php endif; ?>
        </form>
        <div class="control-group" aria-label="Organization archive status">
            <a href="<?php echo htmlspecialchars($list_url(['status' => 'active']), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button<?php echo !$show_archived ? ' active' : ''; ?>">Active</a>
            <a href="<?php echo htmlspecialchars($list_url(['status' => 'archived']), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button<?php echo $show_archived ? ' active' : ''; ?>">Archived</a>
        </div>

        <div class="control-group" aria-label="Organization sort order">
            <span class="control-label">Sort:</span>
            <div class="sort-buttons">
                <a href="<?php echo htmlspecialchars($list_url(['sort_by' => 'name', 'name_sort' => $sort_column === 'name' && $name_sort === 'asc' ? 'desc' : 'asc']), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button<?php echo $sort_column === 'name' ? ' active' : ''; ?>"<?php echo $sort_column === 'name' ? ' aria-current="true"' : ''; ?>>
                    Organization <?php echo $name_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
                <?php foreach (['last_giving' => ['Last Giving', $last_giving_sort], 'lifetime_giving' => ['Lifetime Giving', $lifetime_giving_sort]] as $giving_column => [$giving_label, $giving_direction]): ?>
                    <a href="<?php echo htmlspecialchars($list_url(['sort_by' => $giving_column, $giving_column . '_sort' => $sort_column === $giving_column ? ($giving_direction === 'asc' ? 'desc' : 'asc') : $giving_direction]), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button<?php echo $sort_column === $giving_column ? ' active' : ''; ?>"<?php echo $sort_column === $giving_column ? ' aria-current="true"' : ''; ?>>
                        <?php echo $giving_label . ' ' . ($giving_direction === 'asc' ? '↑' : '↓'); ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <?php if ($search !== ''): ?>
        <p class="result-context">Showing organizations matching “<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>”.</p>
    <?php endif; ?>

    <?php renderPagination($pagination['total'], $current_page, $page_size, $list_current_url, 'organizations', 'Organization pages'); ?>
    <table class="organization-table data-table">
        <thead>
            <tr>
                <th>Organization</th>
                <th>Location</th>
                <th>Contact(s)</th>
                <th>Last Giving</th>
                <th>Lifetime Giving</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($organizations === []): ?>
                <tr><td colspan="6" class="empty-state">No organizations match the current view.</td></tr>
            <?php endif; ?>
            <?php foreach ($organizations as $org): ?>
                <tr>
                    <td><a class="record-link" href="view_organization.php?id=<?php echo (int) $org['id']; ?>&amp;return_to=<?php echo urlencode($list_current_url); ?>"><?php echo htmlspecialchars($org['organization_name']); ?></a></td>
                    <td>
                        <?php
                        $address_parts = [];
                        if (!empty($org['physical_city'])) $address_parts[] = htmlspecialchars($org['physical_city']);
                        if (!empty($org['physical_state'])) $address_parts[] = htmlspecialchars($org['physical_state']);
                        echo implode(', ', $address_parts);
                        ?>
                    </td>
                    <td>
                        <?php echo htmlspecialchars($org['contact_names'] ?? '', ENT_QUOTES, 'UTF-8'); ?>
                    </td>
                    <td class="money-column"><?php echo $org['last_event_giving'] === null ? '—' : formatFinancialAmount($org['last_event_giving']); ?></td>
                    <td class="money-column"><strong><?php echo formatFinancialAmount($org['lifetime_giving']); ?></strong></td>
                    <td>
                        <div class="action-buttons">
                            <a href="view_organization.php?id=<?php echo $org['id']; ?>&amp;return_to=<?php echo urlencode($list_current_url); ?>" class="action-button action-icon-button view-button" aria-label="View organization" title="View" data-tooltip="View"><?php echo actionIconSvg('view'); ?></a>
                            <?php if (!$show_archived && ($user_role === 'admin' || $user_role === 'editor')): ?>
                                <a href="edit_organization.php?id=<?php echo $org['id']; ?>&amp;return_to=<?php echo urlencode($list_current_url); ?>" class="action-button action-icon-button edit-button" aria-label="Edit organization" title="Edit" data-tooltip="Edit"><?php echo actionIconSvg('edit'); ?></a>
                            <?php endif; ?>
                            <?php if (canArchiveEntries($user_role)): ?>
                                <?php if ($show_archived): ?>
                                    <form method="post" action="organizations.php">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($list_current_url, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="organization_id" value="<?php echo (int) $org['id']; ?>">
                                        <input type="hidden" name="list_status" value="archived">
                                        <input type="hidden" name="action" value="restore">
                                        <button type="submit" class="action-button action-icon-button restore-button" aria-label="Restore organization" title="Restore" data-tooltip="Restore"><?php echo actionIconSvg('restore'); ?></button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="organizations.php">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($list_current_url, ENT_QUOTES, 'UTF-8'); ?>">
                                        <input type="hidden" name="organization_id" value="<?php echo (int) $org['id']; ?>">
                                        <input type="hidden" name="list_status" value="active">
                                        <input type="hidden" name="action" value="archive">
                                        <button type="submit" class="action-button action-icon-button archive-button" aria-label="Archive organization" title="Archive" data-tooltip="Archive"><?php echo actionIconSvg('archive'); ?></button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                            <?php if (canDeleteEntries($user_role)): ?>
                                <form method="post" action="organizations.php"
                                      data-delete-confirmation="Permanently delete this organization and all of its contacts and events?"
                                      <?php if ($show_archived): ?>data-archive-button-label="Keep archived"<?php else: ?>data-archive-action="archive"<?php endif; ?>>
                                    <?php echo csrfInput(); ?>
                                        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($list_current_url, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="organization_id" value="<?php echo (int) $org['id']; ?>">
                                    <input type="hidden" name="list_status" value="<?php echo $list_status; ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button type="submit" class="action-button action-icon-button delete-button" aria-label="Delete organization" title="Delete" data-tooltip="Delete"><?php echo actionIconSvg('delete'); ?></button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php renderPagination($pagination['total'], $current_page, $page_size, $list_current_url, 'organizations', 'Organization pages'); ?>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
<?php if ($query_stmt) $query_stmt->close(); ?>
