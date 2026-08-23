<?php
require_once __DIR__ . '/bootstrap.php';
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

    header('Location: organizations.php?' . http_build_query(['status' => $list_status]));
    exit();
}

$action_message = $_SESSION['organization_action_message'] ?? '';
$action_error = $_SESSION['organization_action_error'] ?? '';
unset($_SESSION['organization_action_message'], $_SESSION['organization_action_error']);

// Retrieve organizations using an allowlisted name-sort direction.
$name_sort = strtolower(\Dnr\Http\RequestInput::string($_GET, 'name_sort')) === 'desc'
    ? 'desc'
    : 'asc';
$order_direction = $name_sort === 'asc' ? 'ASC' : 'DESC';
$search = \Dnr\Http\RequestInput::string($_GET, 'q', '', 256);
$fulltext_query = fulltextSearchQuery($search);
if ($fulltext_query === '') {
    $search = '';
}
$requested_page_size = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT);
$page_size = in_array($requested_page_size, $allowed_page_sizes, true) ? $requested_page_size : 20;
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
            WHERE searched_contact.organization_id = o.id
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
if ($cursor !== null && ctype_digit((string) $cursor['id'])) {
    $comparison = $order_direction === 'ASC' ? '>' : '<';
    $cursor_filter = " AND (o.organization_name, o.id) {$comparison} (?, ?)";
    $cursor_values = [(string) $cursor['name'], (int) $cursor['id']];
    $cursor_types = 'si';
} else {
    $cursor = null;
}
$query_limit = $page_size + 1;

$query = "SELECT o.id, o.organization_name, o.physical_city, o.physical_state,
                 '' AS contact_names
          FROM organizations o
          WHERE o.is_deleted = {$archive_value}{$search_filter}{$cursor_filter}
          ORDER BY o.organization_name {$order_direction}, o.id {$order_direction}
          LIMIT ?";
$query_stmt = $conn->prepare($query);
if (!$query_stmt) abortApplication(503, 'Organizations are temporarily unavailable.', ['error' => $conn->error]);
$query_types = ($fulltext_query !== '' ? 'ss' : '') . $cursor_types . 'i';
$query_values = $fulltext_query !== '' ? [$fulltext_query, $fulltext_query] : [];
$query_values = array_merge($query_values, $cursor_values, [$query_limit]);
$query_bind = [$query_types];
foreach ($query_values as &$query_value) $query_bind[] = &$query_value;
unset($query_value);
$query_stmt->bind_param(...$query_bind);
$query_stmt->execute();
$organizations = $query_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
$has_more_organizations = count($organizations) > $page_size;
if ($has_more_organizations) array_pop($organizations);
$next_cursor = null;
if ($has_more_organizations && $organizations !== []) {
    $last_organization = $organizations[array_key_last($organizations)];
    $next_cursor = encodePaginationCursor([
        'name' => (string) $last_organization['organization_name'],
        'id' => (int) $last_organization['id'],
    ]);
}

if ($organizations !== []) {
    $organization_ids = array_map(static fn($row) => (int) $row['id'], $organizations);
    $placeholders = implode(', ', array_fill(0, count($organization_ids), '?'));
    $contacts_stmt = $conn->prepare(
        "SELECT organization_id, contact_name, contact_count
         FROM (
             SELECT c.organization_id,
                    CONCAT_WS(' ', c.contact_first_name, c.contact_last_name) AS contact_name,
                    ROW_NUMBER() OVER (
                        PARTITION BY c.organization_id
                        ORDER BY c.contact_last_name, c.contact_first_name, c.id
                    ) AS contact_position,
                    COUNT(*) OVER (PARTITION BY c.organization_id) AS contact_count
             FROM contacts c
             WHERE c.is_deleted = 0 AND c.organization_id IN ({$placeholders})
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
}

function organizationsPageUrl($status, $name_sort, $search = '', $cursor = null, $page_size = 20)
{
    $parameters = [
        'status' => $status,
        'name_sort' => $name_sort,
        'per_page' => $page_size,
    ];
    if (is_string($cursor) && $cursor !== '') $parameters['cursor'] = $cursor;
    if ($search !== '') {
        $parameters['q'] = $search;
    }
    return 'organizations.php?' . http_build_query($parameters);
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Organizations - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/organizations.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <div class="page-heading">
        <div><h1><?php echo $show_archived ? 'Archived Organizations' : 'Organizations'; ?></h1><p class="page-intro">Keep organization details, locations, and related contacts together.</p></div>
        <?php if (!$show_archived && ($user_role === 'admin' || $user_role === 'editor')): ?>
            <a href="add_organization.php" class="button-add">+ New organization</a>
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
            <label class="visually-hidden" for="organization-search">Search organizations</label>
            <span class="search-icon" aria-hidden="true">⌕</span>
            <input type="search" id="organization-search" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search organizations">
            <?php if ($search !== ''): ?><a href="<?php echo htmlspecialchars(organizationsPageUrl($list_status, $name_sort, '', null, $page_size), ENT_QUOTES, 'UTF-8'); ?>" class="clear-search">Clear</a><?php endif; ?>
        </form>
        <div class="control-group" aria-label="Organization archive status">
            <a href="<?php echo htmlspecialchars(organizationsPageUrl('active', $name_sort, $search, null, $page_size), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button<?php echo !$show_archived ? ' active' : ''; ?>">Active</a>
            <a href="<?php echo htmlspecialchars(organizationsPageUrl('archived', $name_sort, $search, null, $page_size), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button<?php echo $show_archived ? ' active' : ''; ?>">Archived</a>
        </div>

        <div class="control-group" aria-label="Organization sort order">
            <span class="control-label">Sort:</span>
            <div class="sort-buttons">
                <a href="<?php echo htmlspecialchars(organizationsPageUrl($list_status, $name_sort === 'asc' ? 'desc' : 'asc', $search, null, $page_size), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button active" aria-current="true">
                    Organization <?php echo $name_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
            </div>
        </div>
    </div>

    <?php if ($search !== ''): ?>
        <p class="result-context">Showing organizations matching “<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>”.</p>
    <?php endif; ?>

    <table class="organization-table">
        <thead>
            <tr>
                <th>Organization</th>
                <th>Location</th>
                <th>Contact(s)</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if ($organizations === []): ?>
                <tr><td colspan="4" class="empty-state">No organizations match the current view.</td></tr>
            <?php endif; ?>
            <?php foreach ($organizations as $org): ?>
                <tr>
                    <td><a class="record-link" href="view_organization.php?id=<?php echo (int) $org['id']; ?>"><?php echo htmlspecialchars($org['organization_name']); ?></a></td>
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
                    <td>
                        <div class="action-buttons">
                            <a href="view_organization.php?id=<?php echo $org['id']; ?>" class="action-button action-icon-button view-button" aria-label="View organization" title="View" data-tooltip="View"><?php echo actionIconSvg('view'); ?></a>
                            <?php if (!$show_archived && ($user_role === 'admin' || $user_role === 'editor')): ?>
                                <a href="edit_organization.php?id=<?php echo $org['id']; ?>&from=list" class="action-button action-icon-button edit-button" aria-label="Edit organization" title="Edit" data-tooltip="Edit"><?php echo actionIconSvg('edit'); ?></a>
                            <?php endif; ?>
                            <?php if (canArchiveEntries($user_role)): ?>
                                <?php if ($show_archived): ?>
                                    <form method="post" action="organizations.php">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="organization_id" value="<?php echo (int) $org['id']; ?>">
                                        <input type="hidden" name="list_status" value="archived">
                                        <input type="hidden" name="action" value="restore">
                                        <button type="submit" class="action-button action-icon-button restore-button" aria-label="Restore organization" title="Restore" data-tooltip="Restore"><?php echo actionIconSvg('restore'); ?></button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="organizations.php">
                                        <?php echo csrfInput(); ?>
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
    <?php if ($cursor !== null || $next_cursor !== null): ?>
        <nav class="pagination" aria-label="Organization pages">
            <span class="pagination-status">Showing up to <?php echo $page_size; ?> organizations</span>
            <div class="pagination-actions">
                <?php if ($cursor !== null): ?><a class="filter-button" href="<?php echo htmlspecialchars(organizationsPageUrl($list_status, $name_sort, $search, null, $page_size), ENT_QUOTES, 'UTF-8'); ?>">First page</a><?php endif; ?>
                <?php if ($next_cursor !== null): ?><a class="filter-button" href="<?php echo htmlspecialchars(organizationsPageUrl($list_status, $name_sort, $search, $next_cursor, $page_size), ENT_QUOTES, 'UTF-8'); ?>">Next</a><?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
<?php if ($query_stmt) $query_stmt->close(); ?>
