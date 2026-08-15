<?php
include 'config.php';
include 'functions.php';
startSecureSession();
requireLogin();

$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'admin' && $user_role !== 'editor') {
    header('Location: contacts.php');
    exit();
}

$contact_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$contact_id) {
    header('Location: contacts.php');
    exit();
}

$contact_stmt = $conn->prepare(
    "SELECT c.*
     FROM contacts c
     INNER JOIN organizations o ON o.id = c.organization_id
     WHERE c.id = ? AND c.is_deleted = 0 AND o.is_deleted = 0"
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
$error_messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $organization_id = intval($_POST['organization_id'] ?? 0);
    $contact_first_name = trim($_POST['contact_first_name'] ?? '');
    $contact_last_name = trim($_POST['contact_last_name'] ?? '');
    $contact_role = strtolower(trim($_POST['contact_role'] ?? ''));
    $contact_role_other = trim($_POST['contact_role_other'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_email_confirm = trim($_POST['contact_email_confirm'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');

    if (!$organization_id) {
        $error_messages[] = 'Organization is required.';
    }
    if ($contact_first_name === '') {
        $error_messages[] = 'First name is required.';
    }
    if ($contact_last_name === '') {
        $error_messages[] = 'Last name is required.';
    }
    if (!in_array($contact_role, ['pastor', 'admin', 'other'], true)) {
        $error_messages[] = 'A valid role is required.';
    }
    if ($contact_role === 'other' && $contact_role_other === '') {
        $error_messages[] = 'Please specify the other role.';
    }
    if (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $error_messages[] = 'Please provide a valid email address.';
    } elseif (!hash_equals($contact_email, $contact_email_confirm)) {
        $error_messages[] = 'Email addresses do not match.';
    }

    if (!$error_messages) {
        $organization_stmt = $conn->prepare(
            'SELECT id FROM organizations WHERE id = ? AND is_deleted = 0'
        );
        $organization_stmt->bind_param('i', $organization_id);
        $organization_stmt->execute();
        if ($organization_stmt->get_result()->num_rows === 0) {
            $error_messages[] = 'Please select an active organization.';
        }
        $organization_stmt->close();
    }

    if (!$error_messages) {
        $update_stmt = $conn->prepare(
            "UPDATE contacts SET
                organization_id = ?,
                contact_first_name = ?,
                contact_last_name = ?,
                contact_role = ?,
                contact_role_other = ?,
                contact_email = ?,
                contact_phone = ?
             WHERE id = ?"
        );
        $update_stmt->bind_param(
            'issssssi',
            $organization_id,
            $contact_first_name,
            $contact_last_name,
            $contact_role,
            $contact_role_other,
            $contact_email,
            $contact_phone,
            $contact_id
        );

        if ($update_stmt->execute()) {
            $update_stmt->close();
            $_SESSION['success_message'] = 'Contact updated successfully.';
            header("Location: view_contact.php?id={$contact_id}");
            exit();
        }

        $update_stmt->close();
        $error_messages[] = 'Unable to update the contact.';
    }

    $contact['organization_id'] = $organization_id;
    $contact['contact_first_name'] = $contact_first_name;
    $contact['contact_last_name'] = $contact_last_name;
    $contact['contact_role'] = $contact_role;
    $contact['contact_role_other'] = $contact_role_other;
    $contact['contact_email'] = $contact_email;
    $contact['contact_phone'] = $contact_phone;
}

$organizations_result = $conn->query(
    'SELECT id, organization_name FROM organizations WHERE is_deleted = 0 ORDER BY organization_name'
);
if (!$organizations_result) {
    die('Unable to retrieve organizations.');
}

$cancel_url = ($_GET['from'] ?? '') === 'view'
    ? "view_contact.php?id={$contact_id}"
    : 'contacts.php';
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Contact - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.10">
    <style>
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #fff;
            color: #000;
            box-sizing: border-box;
        }
        .dark-mode .form-group input,
        .dark-mode .form-group select {
            background-color: #1e1e1e;
            color: #fff;
            border-color: #444;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 20px;
        }
        .required::after {
            content: " *";
            color: red;
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
        @media (max-width: 640px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h2>Edit Contact</h2>

    <?php if ($error_messages): ?>
        <p class="error"><?php echo implode(
            '<br>',
            array_map(fn($message) => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), $error_messages)
        ); ?></p>
    <?php endif; ?>

    <form method="post" action="edit_contact.php?id=<?php echo $contact_id; ?><?php echo ($_GET['from'] ?? '') === 'view' ? '&amp;from=view' : ''; ?>">
        <?php echo csrfInput(); ?>

        <div class="form-group">
            <label for="organization_id" class="required">Organization</label>
            <select name="organization_id" id="organization_id" required>
                <?php while ($organization = $organizations_result->fetch_assoc()): ?>
                    <option value="<?php echo (int) $organization['id']; ?>" <?php echo (int) $contact['organization_id'] === (int) $organization['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($organization['organization_name'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="contact_first_name" class="required">First Name</label>
                <input type="text" name="contact_first_name" id="contact_first_name" required autocomplete="given-name" value="<?php echo htmlspecialchars($contact['contact_first_name'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label for="contact_last_name" class="required">Last Name</label>
                <input type="text" name="contact_last_name" id="contact_last_name" required autocomplete="family-name" value="<?php echo htmlspecialchars($contact['contact_last_name'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="contact_role" class="required">Role</label>
                <select name="contact_role" id="contact_role" required onchange="toggleOtherRole()">
                    <option value="pastor" <?php echo $contact['contact_role'] === 'pastor' ? 'selected' : ''; ?>>Pastor</option>
                    <option value="admin" <?php echo $contact['contact_role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="other" <?php echo $contact['contact_role'] === 'other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            <div class="form-group" id="other_role_group">
                <label for="contact_role_other">Other Role Description</label>
                <input type="text" name="contact_role_other" id="contact_role_other" value="<?php echo htmlspecialchars($contact['contact_role_other'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="contact_email" class="required">Email</label>
                <input type="email" name="contact_email" id="contact_email" required value="<?php echo htmlspecialchars($contact['contact_email'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label for="contact_email_confirm" class="required">Confirm Email</label>
                <input type="email" name="contact_email_confirm" id="contact_email_confirm" required value="<?php echo htmlspecialchars($_POST['contact_email_confirm'] ?? $contact['contact_email'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="contact_phone">Phone Number</label>
            <input type="tel" name="contact_phone" id="contact_phone" value="<?php echo htmlspecialchars($contact['contact_phone'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
        </div>

        <div class="action-buttons">
            <a href="<?php echo htmlspecialchars($cancel_url, ENT_QUOTES, 'UTF-8'); ?>" class="action-button cancel-button">Cancel</a>
            <button type="submit" class="action-button save-button">Save Changes</button>
        </div>
    </form>
</div>

<script>
function toggleOtherRole() {
    const roleSelect = document.getElementById('contact_role');
    const otherRoleGroup = document.getElementById('other_role_group');
    const otherRoleInput = document.getElementById('contact_role_other');
    const isOther = roleSelect.value === 'other';

    otherRoleGroup.style.display = isOther ? 'block' : 'none';
    otherRoleInput.required = isOther;
    if (!isOther) {
        otherRoleInput.value = '';
    }
}

toggleOtherRole();
</script>

<?php include 'templates/footer.php'; ?>
</body>
</html>
