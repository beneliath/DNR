<?php
session_start(); // Start session to access session variables
include 'config.php';
include 'functions.php';
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';

// Handle delete action if user is admin
if ($user_role === 'admin' && isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $org_id = intval($_GET['delete']);
    $conn->query("UPDATE organizations SET is_deleted = 1 WHERE id = $org_id");
    // Redirect to remove delete parameter from URL
    header("Location: organizations.php");
    exit();
}

// Retrieve organizations
$name_sort = isset($_GET['name_sort']) ? $_GET['name_sort'] : 'asc';
$date_sort = isset($_GET['date_sort']) ? $_GET['date_sort'] : 'desc';

// Determine which column to sort by based on which button was clicked
$sort_column = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'date';

// Determine sort order based on column
if ($sort_column === 'name') {
    $sort_order = $name_sort;
} elseif ($sort_column === 'date') {
    $sort_order = $date_sort;
} else {
    $sort_order = 'desc';
}

// Build the ORDER BY clause safely
if ($sort_column === 'name') {
    $order_by = 'organization_name';
} elseif ($sort_column === 'date') {
    $order_by = 'created_at';
} else {
    $order_by = 'created_at';
}
$order_direction = ($sort_order === 'asc' ? 'ASC' : 'DESC');

// Prepare and execute the query
$query = "SELECT * FROM organizations WHERE is_deleted = 0 ORDER BY {$order_by} {$order_direction}";

$result = $conn->query($query);
if (!$result) {
    die("Database error: " . $conn->error);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Organizations - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .organization-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
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
            display: flex;
            gap: 5px;
        }
        .action-button {
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            color: white;
            font-size: 0.9em;
            white-space: nowrap;
        }
        .view-button {
            background-color: #4CAF50;
        }
        .edit-button {
            background-color: #2196F3;
        }
        .delete-button {
            background-color: #f44336;
        }
        .action-button:hover {
            opacity: 0.9;
        }
        .sort-buttons {
            margin-bottom: 20px;
        }
        .sort-buttons button {
            margin-right: 10px;
            padding: 5px 10px;
            background-color: #666;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        .sort-buttons button:hover {
            background-color: #FF9800;
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h2>Organizations</h2>
    
    <div class="sort-buttons">
        <button onclick="window.location.href='organizations.php?sort_by=name&name_sort=<?php echo $name_sort === 'asc' ? 'desc' : 'asc'; ?>'">
            Name <?php echo $sort_column === 'name' ? ($name_sort === 'asc' ? '↑' : '↓') : ''; ?>
        </button>
        <button onclick="window.location.href='organizations.php?sort_by=date&date_sort=<?php echo $date_sort === 'asc' ? 'desc' : 'asc'; ?>'">
            Date/Time Created <?php echo $sort_column === 'date' ? ($date_sort === 'asc' ? '↑' : '↓') : ''; ?>
        </button>
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
                        $contact_query = "SELECT contact_name FROM contacts WHERE organization_id = ? ORDER BY contact_name";
                        $contact_stmt = $conn->prepare($contact_query);
                        $contact_stmt->bind_param("i", $org['id']);
                        $contact_stmt->execute();
                        $contacts_result = $contact_stmt->get_result();
                        
                        $contact_names = [];
                        while ($contact = $contacts_result->fetch_assoc()) {
                            $contact_names[] = htmlspecialchars($contact['contact_name']);
                        }
                        echo implode(', ', $contact_names);
                        ?>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <a href="view_organization.php?id=<?php echo $org['id']; ?>" class="action-button view-button">View</a>
                            <?php if ($user_role === 'admin' || $user_role === 'editor'): ?>
                                <a href="edit_organization.php?id=<?php echo $org['id']; ?>" class="action-button edit-button">Edit</a>
                            <?php endif; ?>
                            <?php if ($user_role === 'admin'): ?>
                                <a href="organizations.php?delete=<?php echo $org['id']; ?>" class="action-button delete-button" onclick="return confirm('Are you sure you want to delete this organization?');">Delete</a>
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