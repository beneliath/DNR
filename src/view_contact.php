<?php
include 'config.php';
include 'functions.php';
startSecureSession();
requireLogin();

$user_role = $_SESSION['role'] ?? '';
$contact_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$contact_id) {
    header('Location: contacts.php');
    exit();
}

$contact_stmt = $conn->prepare(
    "SELECT
        c.*,
        o.organization_name
     FROM contacts c
     INNER JOIN organizations o ON o.id = c.organization_id
     WHERE c.id = ? AND o.is_deleted = 0"
);
if (!$contact_stmt) {
    die('Unable to retrieve the contact.');
}

$contact_stmt->bind_param('i', $contact_id);
$contact_stmt->execute();
$contact_result = $contact_stmt->get_result();
if ($contact_result->num_rows === 0) {
    $contact_stmt->close();
    header('Location: contacts.php');
    exit();
}

$contact = $contact_result->fetch_assoc();
$contact_stmt->close();

$display_role = $contact['contact_role'] === 'other'
    ? ($contact['contact_role_other'] ?: 'Other')
    : ucfirst($contact['contact_role']);

$success_message = $_SESSION['success_message'] ?? '';
unset($_SESSION['success_message']);
?>
<!DOCTYPE html>
<html>
<head>
    <title>View Contact - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.3.2">
    <style>
        .contact-details {
            background-color: #fff;
            border: 1px solid #ddd;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .dark-mode .contact-details {
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
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        .action-button {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            color: white;
        }
        .back-button {
            background-color: var(--button-neutral-color);
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <?php if ($success_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($success_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <div class="contact-details">
        <h2><?php echo htmlspecialchars(
            $contact['contact_last_name'] . ', ' . $contact['contact_first_name'],
            ENT_QUOTES,
            'UTF-8'
        ); ?></h2>

        <div class="detail-row">
            <strong>Organization</strong>
            <a href="view_organization.php?id=<?php echo (int) $contact['organization_id']; ?>">
                <?php echo htmlspecialchars($contact['organization_name'], ENT_QUOTES, 'UTF-8'); ?>
            </a>
        </div>

        <div class="detail-row">
            <strong>Role</strong>
            <?php echo htmlspecialchars($display_role, ENT_QUOTES, 'UTF-8'); ?>
        </div>

        <div class="detail-row">
            <strong>Email</strong>
            <a href="mailto:<?php echo htmlspecialchars($contact['contact_email'], ENT_QUOTES, 'UTF-8'); ?>">
                <?php echo htmlspecialchars($contact['contact_email'], ENT_QUOTES, 'UTF-8'); ?>
            </a>
        </div>

        <div class="detail-row">
            <strong>Phone</strong>
            <?php echo htmlspecialchars($contact['contact_phone'] ?: 'Not specified', ENT_QUOTES, 'UTF-8'); ?>
        </div>
    </div>

    <div class="action-buttons">
        <a href="contacts.php" class="action-button back-button">Back to List</a>
        <?php if ($user_role === 'admin' || $user_role === 'editor'): ?>
            <a href="edit_contact.php?id=<?php echo $contact_id; ?>&amp;from=view" class="action-button edit-button">Edit Contact</a>
        <?php endif; ?>
    </div>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
