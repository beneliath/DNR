<?php
session_start();
include 'config.php';
include 'functions.php';

// Ensure the user is logged in
requireLogin();

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_contact'])) {
    $organization_id = intval($_POST['organization_id'] ?? 0);
    $contact_name = trim($_POST['contact_name'] ?? '');
    $contact_role = trim($_POST['contact_role'] ?? '');
    $contact_role_other = trim($_POST['contact_role_other'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_email_confirm = trim($_POST['contact_email_confirm'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');

    // Validate required fields
    if (!$organization_id || !$contact_name || !$contact_role || !$contact_email || !$contact_email_confirm) {
        $error_message = "Please fill in all required fields.";
    } elseif ($contact_email !== $contact_email_confirm) {
        $error_message = "Email addresses do not match.";
    } elseif ($contact_role === 'other' && empty($contact_role_other)) {
        $error_message = "Please specify the other role.";
    } else {
        // Sanitize input
        $contact_name = $conn->real_escape_string($contact_name);
        $contact_role = $conn->real_escape_string($contact_role);
        $contact_role_other = $conn->real_escape_string($contact_role_other);
        $contact_email = $conn->real_escape_string($contact_email);
        $contact_phone = $conn->real_escape_string($contact_phone);

        // Insert contact information
        $sql = "INSERT INTO contacts (organization_id, contact_name, contact_role, contact_role_other, contact_email, contact_phone)
                VALUES ('$organization_id', '$contact_name', '$contact_role', '$contact_role_other', '$contact_email', '$contact_phone')";

        if ($conn->query($sql) === TRUE) {
            $success_message = "Contact added successfully!";
        } else {
            $error_message = "Error adding contact: " . $conn->error;
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Contact - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .success {
            background-color: #d4edda !important;
            color: #155724 !important;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da !important;
            color: #721c24 !important;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
            border: 1px solid #f5c6cb;
        }
        .dark-mode .success {
            background-color: #d4edda !important;
            color: #155724 !important;
        }
        .dark-mode .error {
            background-color: #f8d7da !important;
            color: #721c24 !important;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #fff;
            color: #000;
        }
        .dark-mode .form-group input[type="text"],
        .dark-mode .form-group input[type="email"],
        .dark-mode .form-group input[type="tel"],
        .dark-mode .form-group select {
            background-color: #1e1e1e;
            color: #fff;
            border-color: #444;
        }
        .required::after {
            content: " *";
            color: red;
        }
        .required {
            color: inherit;
        }
        .organization-container {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 20px;
        }
        .add-org-button {
            padding: 8px 15px;
            background-color: #357abd;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 14px;
        }
        .add-org-button:hover {
            background-color: #2a5f8f;
        }
        .role-container {
            display: flex;
            gap: 30px;
            margin-bottom: 15px;
        }
        .role-container .form-group {
            margin-bottom: 0;
        }
        #other_role_group {
            flex: 1;
        }
        .email-container {
            display: flex;
            gap: 30px;
            margin-bottom: 15px;
        }
        .email-container .form-group {
            margin-bottom: 0;
        }
        .email-field {
            flex: 1;
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <?php if (!empty($error_message)): ?>
        <div class="error" style="background-color: #f8d7da !important; color: #721c24 !important; padding: 15px; margin-bottom: 20px; border-radius: 4px; border: 1px solid #f5c6cb;"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
        <div class="success" style="background-color: #d4edda !important; color: #155724 !important; padding: 15px; margin-bottom: 20px; border-radius: 4px; border: 1px solid #c3e6cb;"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <h2>Add Contact</h2>
    <form method="post" action="add_contact.php">
        <div class="organization-container">
            <div class="form-group" style="flex: 1;">
                <label for="organization_id" class="required">Organization</label>
                <select name="organization_id" id="organization_id" required>
                    <option value="" disabled selected>Select an organization</option>
                    <?php
                    $orgs = $conn->query("SELECT id, organization_name FROM organizations ORDER BY organization_name");
                    while ($row = $orgs->fetch_assoc()) {
                        $selected = (!empty($error_message) && isset($_POST['organization_id']) && $_POST['organization_id'] == $row['id']) ? 'selected' : '';
                        echo "<option value='" . htmlspecialchars($row['id']) . "' $selected>" . htmlspecialchars($row['organization_name']) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <a href="add_organization.php" class="add-org-button">Add New Organization</a>
        </div>

        <div class="form-group">
            <label for="contact_name" class="required">Contact Name</label>
            <input type="text" name="contact_name" id="contact_name" required value="<?php echo !empty($error_message) ? htmlspecialchars($_POST['contact_name'] ?? '') : ''; ?>">
        </div>

        <div class="role-container">
            <div class="form-group" style="flex: 0 0 200px;">
                <label for="contact_role" class="required">Role</label>
                <select name="contact_role" id="contact_role" required onchange="toggleOtherRole()">
                    <option value="Pastor" <?php echo (!empty($error_message) && isset($_POST['contact_role']) && $_POST['contact_role'] === 'Pastor') ? 'selected' : ''; ?>>Pastor</option>
                    <option value="Admin" <?php echo (!empty($error_message) && isset($_POST['contact_role']) && $_POST['contact_role'] === 'Admin') ? 'selected' : ''; ?>>Admin</option>
                    <option value="other" <?php echo (!empty($error_message) && isset($_POST['contact_role']) && $_POST['contact_role'] === 'other') ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            <div class="form-group" id="other_role_group" style="display: <?php echo (!empty($error_message) && isset($_POST['contact_role']) && $_POST['contact_role'] === 'other') ? 'block' : 'none'; ?>;">
                <label for="contact_role_other" class="required">Other Role Description</label>
                <input type="text" name="contact_role_other" id="contact_role_other" value="<?php echo !empty($error_message) ? htmlspecialchars($_POST['contact_role_other'] ?? '') : ''; ?>">
            </div>
        </div>

        <div class="email-container">
            <div class="form-group email-field">
                <label for="contact_email" class="required">Email</label>
                <input type="email" name="contact_email" id="contact_email" required value="<?php echo !empty($error_message) ? htmlspecialchars($_POST['contact_email'] ?? '') : ''; ?>">
            </div>
            <div class="form-group email-field">
                <label for="contact_email_confirm" class="required">Confirm Email</label>
                <input type="email" name="contact_email_confirm" id="contact_email_confirm" required value="<?php echo !empty($error_message) ? htmlspecialchars($_POST['contact_email_confirm'] ?? '') : ''; ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="contact_phone">Phone Number</label>
            <input type="tel" name="contact_phone" id="contact_phone" value="<?php echo !empty($error_message) ? htmlspecialchars($_POST['contact_phone'] ?? '') : ''; ?>">
        </div>
<br>
        <div class="form-group" style="padding-left: 0; margin-left: 0;">
            <input type="submit" name="save_contact" value="SAVE CONTACT" class="save-button" style="margin-left: 0;">
        </div>
    </form>
</div>

<script>
function toggleOtherRole() {
    const roleSelect = document.getElementById('contact_role');
    const otherRoleGroup = document.getElementById('other_role_group');
    const otherRoleInput = document.getElementById('contact_role_other');
    
    if (roleSelect.value === 'other') {
        otherRoleGroup.style.display = 'block';
        otherRoleInput.required = true;
    } else {
        otherRoleGroup.style.display = 'none';
        otherRoleInput.required = false;
        otherRoleInput.value = '';
    }
}
</script>

<?php include 'templates/footer.php'; ?>
</body>
</html> 