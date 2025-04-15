<?php
session_start();
include 'config.php';
include 'functions.php';
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';

// Check if ID is provided and is numeric
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: engagements.php");
    exit();
}

$engagement_id = intval($_GET['id']);

// Fetch engagement details with organization name and contacts
$query = "SELECT e.*, o.organization_name, o.id as org_id
          FROM engagements e 
          LEFT JOIN organizations o ON e.organization_id = o.id 
          WHERE e.id = ? AND e.is_deleted = 0";

$stmt = $conn->prepare($query);
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("i", $engagement_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: engagements.php");
    exit();
}

$engagement = $result->fetch_assoc();

// Fetch contacts for the organization
$contact_query = "SELECT * FROM contacts WHERE organization_id = ? ORDER BY contact_name";
$contact_stmt = $conn->prepare($contact_query);
if ($contact_stmt === false) {
    die("Error preparing contacts statement: " . $conn->error);
}

$contact_stmt->bind_param("i", $engagement['org_id']);
$contact_stmt->execute();
$contacts_result = $contact_stmt->get_result();

// Close statements
$stmt->close();
$contact_stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>View Engagement - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .view-container {
            max-width: 800px;
            margin: 0 auto;
            padding: 20px;
        }
        .detail-group {
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 15px;
        }
        .detail-group:last-child {
            border-bottom: none;
        }
        .detail-label {
            font-weight: bold;
            margin-bottom: 5px;
            color: var(--text-color);
        }
        .detail-value {
            margin-bottom: 10px;
            color: var(--text-color);
        }
        .action-buttons {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        .action-button {
            padding: 10px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            color: white;
            font-size: 14px;
        }
        .back-button {
            background-color: #666;
        }
        .back-button:hover {
            background-color: #555;
        }
        .edit-button {
            background-color: #357abd;
        }
        .edit-button:hover {
            background-color: #2d6a9d;
        }
        /* Contact styles */
        .contacts-list {
            margin-top: 10px;
            margin-bottom: 20px;
        }
        .contact-item {
            background-color: var(--light-bg-color);
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 10px;
        }
        .dark-mode .contact-item {
            background-color: var(--dark-input-bg);
            border-color: #333;
        }
        .contact-title {
            color: #666;
            font-style: italic;
            margin: 5px 0;
        }
        .dark-mode .contact-title {
            color: #aaa;
        }
        .contact-notes {
            margin-top: 8px;
            padding-top: 8px;
            border-top: 1px solid #eee;
            font-size: 0.9em;
        }
        .dark-mode .contact-notes {
            border-top-color: #333;
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="view-container">
    <h1>View Engagement</h1>
    
    <div class="detail-group">
        <div class="detail-label">Organization</div>
        <div class="detail-value"><?php echo htmlspecialchars($engagement['organization_name']); ?></div>
        
        <?php if ($contacts_result->num_rows > 0): ?>
        <div class="detail-label">Contacts</div>
        <div class="detail-value contacts-list">
            <?php while ($contact = $contacts_result->fetch_assoc()): ?>
            <div class="contact-item">
                <div><strong><?php echo htmlspecialchars($contact['contact_name']); ?></strong></div>
                <?php if (!empty($contact['contact_role'])): ?>
                <div class="contact-title">
                    <?php 
                    echo htmlspecialchars(
                        $contact['contact_role'] === 'other' && !empty($contact['contact_role_other']) 
                        ? $contact['contact_role_other'] 
                        : ucfirst($contact['contact_role'])
                    ); 
                    ?>
                </div>
                <?php endif; ?>
                <?php if (!empty($contact['contact_email'])): ?>
                <div>Email: <a href="mailto:<?php echo htmlspecialchars($contact['contact_email']); ?>"><?php echo htmlspecialchars($contact['contact_email']); ?></a></div>
                <?php endif; ?>
                <?php if (!empty($contact['contact_phone'])): ?>
                <div>Phone: <?php echo htmlspecialchars($contact['contact_phone']); ?></div>
                <?php endif; ?>
            </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>
        
        <div class="detail-label">Event Type</div>
        <div class="detail-value"><?php echo htmlspecialchars($engagement['event_type']); ?></div>
        
        <div class="detail-label">Event Dates</div>
        <div class="detail-value">
            <?php echo htmlspecialchars($engagement['event_start_date'] . ' to ' . $engagement['event_end_date']); ?>
        </div>
    </div>

    <div class="detail-group">
        <div class="detail-label">Status</div>
        <div class="detail-value">
            <?php 
            $status = $engagement['confirmation_status'];
            $status_class = 'status-' . str_replace('_', '-', $status);
            $display_status = str_replace('_', ' ', $status);
            echo "<span class='{$status_class}'>" . htmlspecialchars($display_status) . "</span>";
            ?>
        </div>
    </div>

    <?php if (!empty($engagement['chron'])): ?>
    <div class="detail-group">
        <div class="detail-label">Chron</div>
        <div class="detail-value"><?php echo nl2br(htmlspecialchars($engagement['chron'])); ?></div>
    </div>
    <?php endif; ?>

    <div class="detail-group">
        <div class="detail-label">Event Details</div>
        <div class="detail-value">
            <div><strong>Book Table Provided:</strong> <?php echo $engagement['book_table_provided'] ? 'Yes' : 'No'; ?></div>
            <div><strong>Brochures Permitted:</strong> <?php echo $engagement['brochures_permitted'] ? 'Yes' : 'No'; ?></div>
            <div><strong>All Travel Covered:</strong> <?php echo $engagement['all_travel_covered'] ? 'Yes' : 'No'; ?></div>
        </div>
    </div>

    <?php if (!empty($engagement['compensation_type'])): ?>
    <div class="detail-group">
        <div class="detail-label">Compensation</div>
        <div class="detail-value">
            <div><strong>Type:</strong> <?php echo htmlspecialchars($engagement['compensation_type']); ?></div>
            <?php if (!empty($engagement['travel_amount'])): ?>
            <div><strong>Travel Amount:</strong> $<?php echo htmlspecialchars($engagement['travel_amount']); ?></div>
            <?php endif; ?>
            <?php if (!empty($engagement['lodging_amount'])): ?>
            <div><strong>Lodging Amount:</strong> $<?php echo htmlspecialchars($engagement['lodging_amount']); ?></div>
            <?php endif; ?>
            <?php if (!empty($engagement['housing_type'])): ?>
            <div><strong>Housing Type:</strong> <?php echo htmlspecialchars($engagement['housing_type']); ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!empty($engagement['event_location'])): ?>
    <div class="detail-group">
        <div class="detail-label">Location</div>
        <div class="detail-value">
            <?php echo nl2br(htmlspecialchars($engagement['event_location'])); ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="action-buttons">
        <a href="engagements.php" class="action-button back-button">Back to List</a>
        <?php if ($user_role === 'admin' || $user_role === 'editor'): ?>
        <a href="edit_engagement.php?id=<?php echo $engagement_id; ?>" class="action-button edit-button">Edit</a>
        <?php endif; ?>
    </div>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html> 