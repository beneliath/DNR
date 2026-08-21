<?php
include 'config.php';
include 'functions.php';
include 'two_factor_helpers.php';
startSecureSession();
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';
$allowed_page_sizes = [20, 50, 100];

$list_status = ($_POST['list_status'] ?? $_GET['status'] ?? '') === 'archived'
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
$name_sort = strtolower($_GET['name_sort'] ?? '') === 'desc' ? 'desc' : 'asc';
$order_direction = $name_sort === 'asc' ? 'ASC' : 'DESC';
$search = trim(substr((string) ($_GET['q'] ?? ''), 0, 256));
$fulltext_query = fulltextSearchQuery($search);
if ($fulltext_query === '') {
    $search = '';
}
$requested_page_size = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT);
$page_size = in_array($requested_page_size, $allowed_page_sizes, true) ? $requested_page_size : 20;
$requested_page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 1;

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
$count_query = "SELECT COUNT(*) AS organization_count FROM organizations o
                WHERE o.is_deleted = {$archive_value}{$search_filter}";
$count_stmt = $conn->prepare($count_query);
if ($fulltext_query !== '') $count_stmt->bind_param('ss', $fulltext_query, $fulltext_query);
$count_stmt->execute();
$total_organizations = (int) $count_stmt->get_result()->fetch_assoc()['organization_count'];
$count_stmt->close();
$total_pages = max(1, (int) ceil($total_organizations / $page_size));
$current_page = min($requested_page, $total_pages);
$offset = ($current_page - 1) * $page_size;

$query = "SELECT o.id, o.organization_name, o.physical_city, o.physical_state,
                 GROUP_CONCAT(
                    CONCAT_WS(' ', c.contact_first_name, c.contact_last_name)
                    ORDER BY c.contact_last_name, c.contact_first_name SEPARATOR ', '
                 ) AS contact_names
          FROM organizations o
          LEFT JOIN contacts c ON c.organization_id = o.id AND c.is_deleted = 0
          WHERE o.is_deleted = {$archive_value}{$search_filter}
          GROUP BY o.id, o.organization_name, o.physical_city, o.physical_state
          ORDER BY o.organization_name {$order_direction}, o.id {$order_direction}
          LIMIT ? OFFSET ?";
$query_stmt = $conn->prepare($query);
if (!$query_stmt) die('Unable to retrieve organizations.');
if ($fulltext_query !== '') {
    $query_stmt->bind_param('ssii', $fulltext_query, $fulltext_query, $page_size, $offset);
} else {
    $query_stmt->bind_param('ii', $page_size, $offset);
}
$query_stmt->execute();
$result = $query_stmt->get_result();

function organizationsPageUrl($status, $name_sort, $search = '', $page = 1, $page_size = 20)
{
    $parameters = [
        'status' => $status,
        'name_sort' => $name_sort,
        'page' => $page,
        'per_page' => $page_size,
    ];
    if ($search !== '') {
        $parameters['q'] = $search;
    }
    return 'organizations.php?' . http_build_query($parameters);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Organizations - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
    <style>
        .organization-table {
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
        .organization-table th,
        .organization-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .dark-mode .organization-table th,
        .dark-mode .organization-table td {
            border-bottom-color: #444;
        }
        .organization-table th {
            background-color: #f5f5f5;
            font-weight: var(--font-weight-bold);
        }
        .dark-mode .organization-table th {
            background-color: #2d2d2d;
        }
        .organization-table tr:hover {
            background-color: #f9f9f9;
        }
        .dark-mode .organization-table tr:hover {
            background-color: #333;
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
        .view-button {
            background-color: var(--button-view-color);
        }
        .edit-button {
            background-color: var(--button-edit-color);
        }
        .delete-button {
            background-color: var(--button-delete-color);
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
    </style>
</head>
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
            <?php if ($search !== ''): ?><a href="<?php echo htmlspecialchars(organizationsPageUrl($list_status, $name_sort, '', 1, $page_size), ENT_QUOTES, 'UTF-8'); ?>" class="clear-search">Clear</a><?php endif; ?>
        </form>
        <div class="control-group" aria-label="Organization archive status">
            <a href="<?php echo htmlspecialchars(organizationsPageUrl('active', $name_sort, $search, 1, $page_size), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button<?php echo !$show_archived ? ' active' : ''; ?>">Active</a>
            <a href="<?php echo htmlspecialchars(organizationsPageUrl('archived', $name_sort, $search, 1, $page_size), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button<?php echo $show_archived ? ' active' : ''; ?>">Archived</a>
        </div>

        <div class="control-group" aria-label="Organization sort order">
            <span class="control-label">Sort:</span>
            <div class="sort-buttons">
                <a href="<?php echo htmlspecialchars(organizationsPageUrl($list_status, $name_sort === 'asc' ? 'desc' : 'asc', $search, 1, $page_size), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button active" aria-current="true">
                    Organization <?php echo $name_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
            </div>
        </div>
    </div>

    <?php if ($search !== ''): ?>
        <p class="result-context"><?php echo $total_organizations; ?> result<?php echo $total_organizations === 1 ? '' : 's'; ?> for “<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>”.</p>
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
            <?php if ($result->num_rows === 0): ?>
                <tr><td colspan="4" class="empty-state">No organizations match the current view.</td></tr>
            <?php endif; ?>
            <?php while ($org = $result->fetch_assoc()): ?>
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
            <?php endwhile; ?>
        </tbody>
    </table>
    <?php if ($total_pages > 1): ?>
        <nav class="pagination" aria-label="Organization pages">
            <span class="pagination-status">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?> · <?php echo $total_organizations; ?> organizations</span>
            <div class="pagination-actions">
                <?php if ($current_page > 1): ?><a class="filter-button" href="<?php echo htmlspecialchars(organizationsPageUrl($list_status, $name_sort, $search, $current_page - 1, $page_size), ENT_QUOTES, 'UTF-8'); ?>">Previous</a><?php endif; ?>
                <?php if ($current_page < $total_pages): ?><a class="filter-button" href="<?php echo htmlspecialchars(organizationsPageUrl($list_status, $name_sort, $search, $current_page + 1, $page_size), ENT_QUOTES, 'UTF-8'); ?>">Next</a><?php endif; ?>
            </div>
        </nav>
    <?php endif; ?>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
<?php if ($query_stmt) $query_stmt->close(); ?>
