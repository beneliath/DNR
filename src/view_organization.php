<?php
session_start();
include 'config.php';
include 'functions.php';
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';

// Check if ID is provided and is numeric
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: organizations.php");
    exit();
}

$org_id = intval($_GET['id']);

// Fetch organization details
$query = "SELECT * FROM organizations WHERE id = ? AND is_deleted = 0";

$stmt = $conn->prepare($query);
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("i", $org_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: organizations.php");
    exit();
}

$organization = $result->fetch_assoc();

// Fetch contacts for the organization
$contact_query = "SELECT * FROM contacts WHERE organization_id = ? ORDER BY contact_name";
$contact_stmt = $conn->prepare($contact_query);
if ($contact_stmt === false) {
    die("Error preparing contacts statement: " . $conn->error);
}

$contact_stmt->bind_param("i", $org_id);
$contact_stmt->execute();
$contacts_result = $contact_stmt->get_result();

// Close statements
$stmt->close();
$contact_stmt->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>View Organization - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .organization-details {
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .dark-mode .organization-details {
            background-color: #1e1e1e;
            border-color: #444;
        }
        .detail-row {
            margin-bottom: 15px;
        }
        .detail-row strong {
            display: block;
            margin-bottom: 5px;
        }
        .contacts-section {
            margin-top: 30px;
        }
        .contact-card {
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 15px;
            margin-bottom: 15px;
        }
        .dark-mode .contact-card {
            background-color: #1e1e1e;
            border-color: #444;
        }
        .contact-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }
        .contact-name {
            font-size: 1.1em;
            font-weight: bold;
            margin: 0;
        }
        .contact-role {
            font-style: italic;
            color: #666;
        }
        .dark-mode .contact-role {
            color: #888;
        }
        .contact-info {
            margin-top: 10px;
        }
        .action-buttons {
            margin-top: 20px;
        }
        .action-button {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            color: white;
            margin-right: 10px;
        }
        .back-button {
            background-color: #666;
        }
        .edit-button {
            background-color: #2196F3;
        }
        .action-button:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <div class="organization-details">
        <h2><?php echo htmlspecialchars($organization['organization_name']); ?></h2>
        
        <div class="detail-row">
            <strong>Affiliation</strong>
            <?php echo !empty($organization['affiliation']) ? htmlspecialchars($organization['affiliation']) : 'Not specified'; ?>
        </div>
        
        <div class="detail-row">
            <strong>Distinctives</strong>
            <?php echo !empty($organization['distinctives']) ? htmlspecialchars($organization['distinctives']) : 'Not specified'; ?>
        </div>
        
        <div class="detail-row">
            <strong>Website</strong>
            <?php if (!empty($organization['website_url'])): ?>
                <a href="<?php echo htmlspecialchars($organization['website_url']); ?>" target="_blank"><?php echo htmlspecialchars($organization['website_url']); ?></a>
            <?php else: ?>
                Not specified
            <?php endif; ?>
        </div>
        
        <div class="detail-row">
            <strong>Phone</strong>
            <?php echo !empty($organization['phone']) ? htmlspecialchars($organization['phone']) : 'Not specified'; ?>
        </div>
        
        <div class="detail-row">
            <strong>Fax</strong>
            <?php echo !empty($organization['fax']) ? htmlspecialchars($organization['fax']) : 'Not specified'; ?>
        </div>
        
        <div class="detail-row">
            <strong>Physical Address</strong>
            <?php
            $address_parts = [];
            if (!empty($organization['physical_address_line_1'])) $address_parts[] = htmlspecialchars($organization['physical_address_line_1']);
            if (!empty($organization['physical_address_line_2'])) $address_parts[] = htmlspecialchars($organization['physical_address_line_2']);
            if (!empty($organization['physical_city'])) $address_parts[] = htmlspecialchars($organization['physical_city']);
            if (!empty($organization['physical_state'])) $address_parts[] = htmlspecialchars($organization['physical_state']);
            if (!empty($organization['physical_zipcode'])) $address_parts[] = htmlspecialchars($organization['physical_zipcode']);
            if (!empty($organization['physical_country'])) $address_parts[] = htmlspecialchars($organization['physical_country']);
            
            echo !empty($address_parts) ? implode(', ', $address_parts) : 'Not specified';
            ?>
        </div>
        
        <div class="detail-row">
            <strong>Mailing Address</strong>
            <?php
            $mailing_parts = [];
            if (!empty($organization['mailing_address_line_1'])) $mailing_parts[] = htmlspecialchars($organization['mailing_address_line_1']);
            if (!empty($organization['mailing_address_line_2'])) $mailing_parts[] = htmlspecialchars($organization['mailing_address_line_2']);
            if (!empty($organization['mailing_city'])) $mailing_parts[] = htmlspecialchars($organization['mailing_city']);
            if (!empty($organization['mailing_state'])) $mailing_parts[] = htmlspecialchars($organization['mailing_state']);
            if (!empty($organization['mailing_zipcode'])) $mailing_parts[] = htmlspecialchars($organization['mailing_zipcode']);
            if (!empty($organization['mailing_country'])) $mailing_parts[] = htmlspecialchars($organization['mailing_country']);
            
            echo !empty($mailing_parts) ? implode(', ', $mailing_parts) : 'Not specified';
            ?>
        </div>
        
        <div class="detail-row">
            <strong>Notes</strong>
            <?php echo !empty($organization['notes']) ? nl2br(htmlspecialchars($organization['notes'])) : 'No notes'; ?>
        </div>
    </div>
    
    <div class="contacts-section">
        <h3>Contacts</h3>
        <?php if ($contacts_result->num_rows > 0): ?>
            <?php while ($contact = $contacts_result->fetch_assoc()): ?>
                <div class="contact-card">
                    <div class="contact-header">
                        <h4 class="contact-name"><?php echo htmlspecialchars($contact['contact_name']); ?></h4>
                        <span class="contact-role">
                            <?php
                            $role = $contact['contact_role'];
                            if ($role === 'other' && !empty($contact['contact_role_other'])) {
                                echo htmlspecialchars($contact['contact_role_other']);
                            } else {
                                echo ucfirst($role);
                            }
                            ?>
                        </span>
                    </div>
                    <div class="contact-info">
                        <div><strong>Email:</strong> <?php echo htmlspecialchars($contact['contact_email']); ?></div>
                        <?php if (!empty($contact['contact_phone'])): ?>
                            <div><strong>Phone:</strong> <?php echo htmlspecialchars($contact['contact_phone']); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endwhile; ?>
        <?php else: ?>
            <p>No contacts found for this organization.</p>
        <?php endif; ?>
    </div>
    
    <div class="action-buttons">
        <a href="organizations.php" class="action-button back-button">Back to Organizations</a>
        <?php if ($user_role === 'admin'): ?>
            <a href="edit_organization.php?id=<?php echo $org_id; ?>" class="action-button edit-button">Edit Organization</a>
        <?php endif; ?>
    </div>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html> 