<?php
include 'config.php';
include 'functions.php';
startSecureSession();
requireLogin();

$user_role = $_SESSION['role'] ?? '';
$allowed_page_sizes = [20, 50, 100];

$list_status = ($_POST['list_status'] ?? $_GET['status'] ?? '') === 'archived'
    ? 'archived'
    : 'active';
$show_archived = $list_status === 'archived';

// Handle archive, restore, and permanent deletion through authenticated POST requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $contact_id = filter_input(INPUT_POST, 'contact_id', FILTER_VALIDATE_INT);
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

    if ($contact_id && $action === 'archive') {
        $action_succeeded = archiveEntity($conn, 'contact', $contact_id);
        $action_message = 'Contact archived.';
    } elseif ($contact_id && $action === 'restore') {
        $action_succeeded = restoreEntity($conn, 'contact', $contact_id);
        $action_message = 'Contact restored.';
    } elseif ($contact_id && $action === 'delete') {
        $action_succeeded = permanentlyDeleteEntity($conn, 'contact', $contact_id);
        $action_message = 'Contact permanently deleted.';
    } else {
        http_response_code(400);
        exit('Invalid contact action.');
    }

    if ($action_succeeded) {
        $_SESSION['contact_action_message'] = $action_message;
    } else {
        $_SESSION['contact_action_error'] = 'Unable to update the contact. Please try again.';
    }

    header('Location: contacts.php?' . http_build_query(['status' => $list_status]));
    exit();
}

$action_message = $_SESSION['contact_action_message'] ?? '';
$action_error = $_SESSION['contact_action_error'] ?? '';
unset($_SESSION['contact_action_message'], $_SESSION['contact_action_error']);

$requested_page_size = filter_input(INPUT_GET, 'per_page', FILTER_VALIDATE_INT);
$page_size = in_array($requested_page_size, $allowed_page_sizes, true)
    ? $requested_page_size
    : 20;

$legacy_sort = $_GET['sort'] ?? '';
$last_name_sort = strtolower($_GET['last_name_sort'] ?? $legacy_sort) === 'desc' ? 'desc' : 'asc';
$organization_sort = strtolower($_GET['organization_sort'] ?? '') === 'desc' ? 'desc' : 'asc';
$sort_column = in_array($_GET['sort_by'] ?? '', ['last_name', 'organization'], true)
    ? $_GET['sort_by']
    : 'last_name';

if ($sort_column === 'organization') {
    $order_direction = $organization_sort === 'desc' ? 'DESC' : 'ASC';
    $order_clause = "o.organization_name {$order_direction},
                     c.contact_last_name {$order_direction},
                     c.contact_first_name {$order_direction},
                     c.id {$order_direction}";
} else {
    $order_direction = $last_name_sort === 'desc' ? 'DESC' : 'ASC';
    $order_clause = "c.contact_last_name {$order_direction},
                     c.contact_first_name {$order_direction},
                     c.id {$order_direction}";
}

$requested_page = filter_input(
    INPUT_GET,
    'page',
    FILTER_VALIDATE_INT,
    ['options' => ['min_range' => 1]]
);
$requested_page = $requested_page ?: 1;

$archive_value = $show_archived ? 1 : 0;
$active_organization_filter = $show_archived ? '' : ' AND o.is_deleted = 0';
$count_query = "SELECT COUNT(*) AS contact_count
                FROM contacts c
                INNER JOIN organizations o ON c.organization_id = o.id
                WHERE c.is_deleted = {$archive_value}{$active_organization_filter}";
$count_result = $conn->query($count_query);
if (!$count_result) {
    die('Unable to retrieve contacts.');
}

$total_contacts = (int) $count_result->fetch_assoc()['contact_count'];
$total_pages = max(1, (int) ceil($total_contacts / $page_size));
$current_page = min($requested_page, $total_pages);
$offset = ($current_page - 1) * $page_size;

$contact_query = "SELECT
                    c.id,
                    c.organization_id,
                    c.contact_first_name,
                    c.contact_last_name,
                    o.organization_name,
                    o.is_deleted AS organization_is_archived
                  FROM contacts c
                  INNER JOIN organizations o ON c.organization_id = o.id
                  WHERE c.is_deleted = {$archive_value}{$active_organization_filter}
                  ORDER BY {$order_clause}
                  LIMIT ? OFFSET ?";
$contact_stmt = $conn->prepare($contact_query);
if (!$contact_stmt) {
    die('Unable to retrieve contacts.');
}

$contact_stmt->bind_param('ii', $page_size, $offset);
if (!$contact_stmt->execute()) {
    die('Unable to retrieve contacts.');
}
$contacts_result = $contact_stmt->get_result();

function contactsPageUrl(
    $page,
    $page_size,
    $sort_column,
    $last_name_sort,
    $organization_sort,
    $list_status
) {
    return 'contacts.php?' . http_build_query([
        'page' => $page,
        'per_page' => $page_size,
        'sort_by' => $sort_column,
        'last_name_sort' => $last_name_sort,
        'organization_sort' => $organization_sort,
        'status' => $list_status,
    ]);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Contacts - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.9">
    <style>
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
        .sort-selection.active {
            background-color: var(--button-edit-color) !important;
        }
        .page-size-button.active {
            background-color: var(--button-edit-color) !important;
        }
        .contact-table-wrapper {
            overflow-x: auto;
        }
        .contact-table {
            width: 100%;
            border-collapse: collapse;
        }
        .contact-table th,
        .contact-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        .contact-table th:last-child,
        .contact-table td:last-child {
            text-align: right;
        }
        .dark-mode .contact-table th,
        .dark-mode .contact-table td {
            border-bottom-color: #444;
        }
        .contact-table th {
            background-color: #f5f5f5;
            font-weight: var(--font-weight-bold);
        }
        .dark-mode .contact-table th {
            background-color: #2d2d2d;
        }
        .contact-table tr:hover {
            background-color: #f9f9f9;
        }
        .dark-mode .contact-table tr:hover {
            background-color: #333;
        }
        .action-buttons {
            display: inline-flex;
            justify-content: flex-end;
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
        .pagination {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            margin-top: 20px;
        }
        .pagination-status {
            min-width: 110px;
            text-align: center;
        }
        .empty-state {
            text-align: center !important;
        }
        @media (max-width: 640px) {
            .page-heading,
            .list-controls {
                align-items: flex-start;
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <div class="page-heading">
        <h1><?php echo $show_archived ? 'Archived Contacts' : 'Contacts'; ?></h1>
        <?php if (!$show_archived && ($user_role === 'admin' || $user_role === 'editor')): ?>
            <a href="add_contact.php" class="button-add">Add Contact</a>
        <?php endif; ?>
    </div>

    <?php if ($action_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($action_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($action_error !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($action_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <div class="list-controls">
        <div class="control-group" aria-label="Contact archive status">
            <a href="<?php echo htmlspecialchars(contactsPageUrl(1, $page_size, $sort_column, $last_name_sort, $organization_sort, 'active'), ENT_QUOTES, 'UTF-8'); ?>"
               class="sort-button<?php echo !$show_archived ? ' active' : ''; ?>">Active</a>
            <a href="<?php echo htmlspecialchars(contactsPageUrl(1, $page_size, $sort_column, $last_name_sort, $organization_sort, 'archived'), ENT_QUOTES, 'UTF-8'); ?>"
               class="sort-button<?php echo $show_archived ? ' active' : ''; ?>">Archived</a>
        </div>

        <div class="control-group" aria-label="Contact sort order">
            <span class="control-label">Sort:</span>
            <div class="sort-buttons">
                <a href="<?php echo htmlspecialchars(contactsPageUrl(1, $page_size, 'last_name', $last_name_sort === 'asc' ? 'desc' : 'asc', $organization_sort, $list_status), ENT_QUOTES, 'UTF-8'); ?>"
                   class="sort-button sort-selection<?php echo $sort_column === 'last_name' ? ' active' : ''; ?>"
                   <?php echo $sort_column === 'last_name' ? 'aria-current="true"' : ''; ?>>
                    Last Name <?php echo $last_name_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
                <a href="<?php echo htmlspecialchars(contactsPageUrl(1, $page_size, 'organization', $last_name_sort, $organization_sort === 'asc' ? 'desc' : 'asc', $list_status), ENT_QUOTES, 'UTF-8'); ?>"
                   class="sort-button sort-selection<?php echo $sort_column === 'organization' ? ' active' : ''; ?>"
                   <?php echo $sort_column === 'organization' ? 'aria-current="true"' : ''; ?>>
                    Organization <?php echo $organization_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
            </div>
        </div>

        <div class="control-group" aria-label="Contacts per page">
            <span class="control-label">Show:</span>
            <?php foreach ($allowed_page_sizes as $allowed_page_size): ?>
                <a href="<?php echo htmlspecialchars(contactsPageUrl(1, $allowed_page_size, $sort_column, $last_name_sort, $organization_sort, $list_status), ENT_QUOTES, 'UTF-8'); ?>"
                   class="sort-button page-size-button<?php echo $page_size === $allowed_page_size ? ' active' : ''; ?>"
                   <?php echo $page_size === $allowed_page_size ? 'aria-current="true"' : ''; ?>><?php echo $allowed_page_size; ?></a>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="contact-table-wrapper">
        <table class="contact-table">
            <thead>
                <tr>
                    <th>Contact</th>
                    <th>Organization</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($contacts_result->num_rows === 0): ?>
                    <tr>
                        <td colspan="3" class="empty-state">No contacts found.</td>
                    </tr>
                <?php else: ?>
                    <?php while ($contact = $contacts_result->fetch_assoc()): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(
                                $contact['contact_last_name'] . ', ' . $contact['contact_first_name'],
                                ENT_QUOTES,
                                'UTF-8'
                            ); ?></td>
                            <td>
                                <a href="view_organization.php?id=<?php echo (int) $contact['organization_id']; ?>">
                                    <?php echo htmlspecialchars($contact['organization_name'], ENT_QUOTES, 'UTF-8'); ?>
                                </a>
                                <?php if (!empty($contact['organization_is_archived'])): ?>
                                    <span class="archive-status">Archived</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <a href="view_contact.php?id=<?php echo (int) $contact['id']; ?>" class="action-button view-button">View</a>
                                    <?php if (!$show_archived && ($user_role === 'admin' || $user_role === 'editor')): ?>
                                        <a href="edit_contact.php?id=<?php echo (int) $contact['id']; ?>" class="action-button edit-button">Edit</a>
                                    <?php endif; ?>
                                    <?php if (canArchiveEntries($user_role)): ?>
                                        <?php if ($show_archived): ?>
                                            <form method="post" action="contacts.php">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="contact_id" value="<?php echo (int) $contact['id']; ?>">
                                                <input type="hidden" name="list_status" value="archived">
                                                <input type="hidden" name="action" value="restore">
                                                <button type="submit" class="action-button restore-button">Restore</button>
                                            </form>
                                        <?php else: ?>
                                            <form method="post" action="contacts.php">
                                                <?php echo csrfInput(); ?>
                                                <input type="hidden" name="contact_id" value="<?php echo (int) $contact['id']; ?>">
                                                <input type="hidden" name="list_status" value="active">
                                                <input type="hidden" name="action" value="archive">
                                                <button type="submit" class="action-button archive-button">Archive</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if (canDeleteEntries($user_role)): ?>
                                        <form method="post" action="contacts.php"
                                              data-delete-confirmation="Permanently delete this contact?"
                                              <?php if ($show_archived): ?>data-archive-button-label="Keep archived"<?php else: ?>data-archive-action="archive"<?php endif; ?>>
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="contact_id" value="<?php echo (int) $contact['id']; ?>">
                                            <input type="hidden" name="list_status" value="<?php echo $list_status; ?>">
                                            <input type="hidden" name="action" value="delete">
                                            <button type="submit" class="action-button delete-button">Delete</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_contacts > 0): ?>
        <div class="pagination" aria-label="Contact pages">
            <?php if ($current_page > 1): ?>
                <a href="<?php echo htmlspecialchars(contactsPageUrl($current_page - 1, $page_size, $sort_column, $last_name_sort, $organization_sort, $list_status), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button">Previous</a>
            <?php endif; ?>
            <span class="pagination-status">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?></span>
            <?php if ($current_page < $total_pages): ?>
                <a href="<?php echo htmlspecialchars(contactsPageUrl($current_page + 1, $page_size, $sort_column, $last_name_sort, $organization_sort, $list_status), ENT_QUOTES, 'UTF-8'); ?>" class="sort-button">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
<?php $contact_stmt->close(); ?>
