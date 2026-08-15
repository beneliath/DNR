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
    $org_id = filter_input(INPUT_POST, 'organization_id', FILTER_VALIDATE_INT);
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

    if ($org_id && $action === 'archive') {
        $action_succeeded = archiveEntity($conn, 'organization', $org_id);
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
        $_SESSION['organization_action_error'] = 'Unable to update the organization. Please try again.';
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

// Prepare and execute the query
$archive_value = $show_archived ? 1 : 0;
$query = "SELECT * FROM organizations
          WHERE is_deleted = {$archive_value}
          ORDER BY organization_name {$order_direction}, id {$order_direction}";

$result = $conn->query($query);
if (!$result) {
    die("Database error: " . $conn->error);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Organizations - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.9">
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
        .action-button:hover {
            background-color: var(--button-hover-color);
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
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <div class="page-heading">
        <h1><?php echo $show_archived ? 'Archived Organizations' : 'Organizations'; ?></h1>
        <?php if (!$show_archived && ($user_role === 'admin' || $user_role === 'editor')): ?>
            <a href="add_organization.php" class="button-add">Add Organization</a>
        <?php endif; ?>
    </div>

    <?php if ($action_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($action_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($action_error !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($action_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <div class="list-controls">
        <div class="control-group" aria-label="Organization archive status">
            <a href="?status=active&amp;name_sort=<?php echo $name_sort; ?>" class="sort-button<?php echo !$show_archived ? ' active' : ''; ?>">Active</a>
            <a href="?status=archived&amp;name_sort=<?php echo $name_sort; ?>" class="sort-button<?php echo $show_archived ? ' active' : ''; ?>">Archived</a>
        </div>

        <div class="control-group" aria-label="Organization sort order">
            <span class="control-label">Sort:</span>
            <div class="sort-buttons">
                <a href="?status=<?php echo $list_status; ?>&amp;name_sort=<?php echo $name_sort === 'asc' ? 'desc' : 'asc'; ?>" class="sort-button active" aria-current="true">
                    Organization <?php echo $name_sort === 'asc' ? '↑' : '↓'; ?>
                </a>
            </div>
        </div>
    </div>

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
            <?php while ($org = $result->fetch_assoc()): ?>
                <tr>
                    <td><?php echo htmlspecialchars($org['organization_name']); ?></td>
                    <td>
                        <?php
                        $address_parts = [];
                        if (!empty($org['physical_city'])) $address_parts[] = htmlspecialchars($org['physical_city']);
                        if (!empty($org['physical_state'])) $address_parts[] = htmlspecialchars($org['physical_state']);
                        echo implode(', ', $address_parts);
                        ?>
                    </td>
                    <td>
                        <?php
                        // Fetch contacts for this organization
                        $contact_query = "SELECT contact_first_name, contact_last_name
                                          FROM contacts
                                          WHERE organization_id = ? AND is_deleted = 0
                                          ORDER BY contact_last_name, contact_first_name";
                        $contact_stmt = $conn->prepare($contact_query);
                        $contact_stmt->bind_param("i", $org['id']);
                        $contact_stmt->execute();
                        $contacts_result = $contact_stmt->get_result();

                        $contact_names = [];
                        while ($contact = $contacts_result->fetch_assoc()) {
                            $contact_names[] = htmlspecialchars(
                                trim($contact['contact_first_name'] . ' ' . $contact['contact_last_name']),
                                ENT_QUOTES,
                                'UTF-8'
                            );
                        }
                        echo implode(', ', $contact_names);
                        ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="view_organization.php?id=<?php echo $org['id']; ?>" class="action-button view-button">View</a>
                            <?php if (!$show_archived && ($user_role === 'admin' || $user_role === 'editor')): ?>
                                <a href="edit_organization.php?id=<?php echo $org['id']; ?>&from=list" class="action-button edit-button">Edit</a>
                            <?php endif; ?>
                            <?php if (canArchiveEntries($user_role)): ?>
                                <?php if ($show_archived): ?>
                                    <form method="post" action="organizations.php">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="organization_id" value="<?php echo (int) $org['id']; ?>">
                                        <input type="hidden" name="list_status" value="archived">
                                        <input type="hidden" name="action" value="restore">
                                        <button type="submit" class="action-button restore-button">Restore</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="organizations.php">
                                        <?php echo csrfInput(); ?>
                                        <input type="hidden" name="organization_id" value="<?php echo (int) $org['id']; ?>">
                                        <input type="hidden" name="list_status" value="active">
                                        <input type="hidden" name="action" value="archive">
                                        <button type="submit" class="action-button archive-button">Archive</button>
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
