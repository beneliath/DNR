<?php
include 'config.php';
include 'functions.php';
startSecureSession();
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';

// Handle soft deletion through an authenticated POST request.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_organization'])) {
    if ($user_role !== 'admin') {
        http_response_code(403);
        exit('Forbidden.');
    }
    requireValidCsrfToken();
    $org_id = filter_input(INPUT_POST, 'organization_id', FILTER_VALIDATE_INT);
    if ($org_id) {
        $delete_stmt = $conn->prepare("UPDATE organizations SET is_deleted = 1 WHERE id = ?");
        $delete_stmt->bind_param("i", $org_id);
        $delete_stmt->execute();
    }
    header("Location: organizations.php");
    exit();
}

// Retrieve organizations using an allowlisted name-sort direction.
$name_sort = strtolower($_GET['name_sort'] ?? '') === 'desc' ? 'desc' : 'asc';
$order_direction = $name_sort === 'asc' ? 'ASC' : 'DESC';

// Prepare and execute the query
$query = "SELECT * FROM organizations
          WHERE is_deleted = 0
          ORDER BY organization_name {$order_direction}, id {$order_direction}";

$result = $conn->query($query);
if (!$result) {
    die("Database error: " . $conn->error);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Organizations - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.3.2">
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
            font-weight: bold;
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
            font-family: inherit;
            font-size: 0.9em;
            line-height: 1.6;
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
            font-size: 14px;
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
        <h1>Organizations</h1>
        <?php if ($user_role === 'admin' || $user_role === 'editor'): ?>
            <a href="add_organization.php" class="button-add">Add Organization</a>
        <?php endif; ?>
    </div>

    <div class="sort-buttons">
        <a href="?name_sort=<?php echo $name_sort === 'asc' ? 'desc' : 'asc'; ?>" class="sort-button">
            Organization <?php echo $name_sort === 'asc' ? '↑' : '↓'; ?>
        </a>
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
                                          WHERE organization_id = ?
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
                            <?php if ($user_role === 'admin' || $user_role === 'editor'): ?>
                                <a href="edit_organization.php?id=<?php echo $org['id']; ?>&from=list" class="action-button edit-button">Edit</a>
                            <?php endif; ?>
                            <?php if ($user_role === 'admin'): ?>
                                <form method="post" action="organizations.php" data-delete-confirmation="Are you sure you want to delete this organization?">
                                    <?php echo csrfInput(); ?>
                                    <input type="hidden" name="organization_id" value="<?php echo (int) $org['id']; ?>">
                                    <button type="submit" name="delete_organization" class="action-button delete-button">Delete</button>
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
