<?php
include 'config.php';
include 'functions.php';
include 'contact_photo_helpers.php';
startSecureSession();

// Ensure the user is logged in
requireLogin();
if (!hasRole(['admin', 'editor'])) {
    header("Location: contacts.php");
    exit();
}

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_contact'])) {
    requireValidCsrfToken();

    $organization_id = intval($_POST['organization_id'] ?? 0);
    $contact_first_name = trim($_POST['contact_first_name'] ?? '');
    $contact_last_name = trim($_POST['contact_last_name'] ?? '');
    $contact_role = strtolower(trim($_POST['contact_role'] ?? ''));
    $contact_role_other = trim($_POST['contact_role_other'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_email_confirm = trim($_POST['contact_email_confirm'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $contact_phone_country_code = trim($_POST['contact_phone_country_code'] ?? '+1');
    $phone_error = '';
    $photo_error = '';
    $contact_photo = null;
    try {
        $contact_phone = normalizePhoneNumber(
            $contact_phone_country_code,
            $contact_phone,
            'Phone number'
        );
    } catch (InvalidArgumentException $exception) {
        $phone_error = $exception->getMessage();
    }
    try {
        $contact_photo = contactPhotoFromUpload($_FILES['contact_photo'] ?? []);
    } catch (InvalidArgumentException $exception) {
        $photo_error = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Unable to read contact photo upload: ' . $exception->getMessage());
        $photo_error = 'The contact photo could not be uploaded. Try again.';
    }

    // Validate required fields
    $valid_contact_roles = ['pastor', 'admin', 'other'];

    if (!$organization_id || !$contact_first_name || !$contact_last_name || !$contact_role || !$contact_email || !$contact_email_confirm) {
        $error_message = "Please fill in all required fields.";
    } elseif ($contact_email !== $contact_email_confirm) {
        $error_message = "Email addresses do not match.";
    } elseif (!filter_var($contact_email, FILTER_VALIDATE_EMAIL)) {
        $error_message = "Please provide a valid email address.";
    } elseif (!in_array($contact_role, $valid_contact_roles, true)) {
        $error_message = "Invalid contact role selected.";
    } elseif ($contact_role === 'other' && empty($contact_role_other)) {
        $error_message = "Please specify the other role.";
    } elseif ($phone_error !== '') {
        $error_message = $phone_error;
    } elseif ($photo_error !== '') {
        $error_message = $photo_error;
    } else {
        $conn->begin_transaction();
        try {
            requireActiveOrganization($conn, $organization_id, true);
            if ($contact_photo !== null) {
                $stmt = $conn->prepare(
                    "INSERT INTO contacts (
                        organization_id, contact_first_name, contact_last_name, contact_role,
                        contact_role_other, contact_email, contact_phone,
                        contact_photo, contact_photo_mime, contact_photo_updated_at
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
                );
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO contacts (
                        organization_id, contact_first_name, contact_last_name, contact_role,
                        contact_role_other, contact_email, contact_phone
                     ) VALUES (?, ?, ?, ?, ?, ?, ?)"
                );
            }
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the contact.');
            }
            if ($contact_photo !== null) {
                $contact_photo_data = $contact_photo['data'];
                $contact_photo_mime = $contact_photo['mime_type'];
                $stmt->bind_param(
                    'issssssss',
                    $organization_id,
                    $contact_first_name,
                    $contact_last_name,
                    $contact_role,
                    $contact_role_other,
                    $contact_email,
                    $contact_phone,
                    $contact_photo_data,
                    $contact_photo_mime
                );
            } else {
                $stmt->bind_param(
                    'issssss',
                    $organization_id,
                    $contact_first_name,
                    $contact_last_name,
                    $contact_role,
                    $contact_role_other,
                    $contact_email,
                    $contact_phone
                );
            }
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to add the contact.');
            }
            $contact_id = $conn->insert_id;
            $stmt->close();
            $conn->commit();
            $_SESSION['success_message'] = 'Contact added successfully.';
            header('Location: view_contact.php?id=' . $contact_id);
            exit();
        } catch (Throwable $exception) {
            $conn->rollback();
            $error_message = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'Unable to add the contact.';
        }
    }
}

$contact_phone_country_code_value = trim($_POST['contact_phone_country_code'] ?? '+1');
[, $contact_phone_local_value] = phoneNumberInputParts(
    $_POST['contact_phone'] ?? '',
    $contact_phone_country_code_value
);
$contact_photo_placeholder = 'data:image/svg+xml;base64,' . base64_encode(contactInitialsSvg([
    'contact_first_name' => $_POST['contact_first_name'] ?? '',
    'contact_last_name' => $_POST['contact_last_name'] ?? '',
]));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Add Contact - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
    <script src="assets/js/contact-photo.min.js?v=1.0.0" defer></script>
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
            background-color: var(--button-add-color);
            color: white;
            text-decoration: none;
            border-radius: 4px;
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
        .name-container {
            display: flex;
            gap: 30px;
            margin-bottom: 15px;
        }
        .name-container .form-group {
            flex: 1;
            margin-bottom: 0;
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

    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="contacts.php">Contacts</a><span aria-hidden="true">/</span><span>New Contact</span></nav>
    <div class="page-heading form-page-heading"><div><h1>New Contact</h1><p class="page-intro">Connect a person with an organization and their role.</p></div></div>
    <form method="post" action="add_contact.php" enctype="multipart/form-data" class="contact-form">
        <?php echo csrfInput(); ?>
        <p class="required-fields-note"><span aria-hidden="true">*</span> Required fields</p>
        <div class="organization-container">
            <div class="form-group" style="flex: 1;">
                <label for="organization_id" class="required">Organization</label>
                <select name="organization_id" id="organization_id" required>
                    <option value="" disabled selected>Select an organization</option>
                    <?php
                    $orgs = $conn->query("SELECT id, organization_name FROM organizations WHERE is_deleted = 0 ORDER BY organization_name");
                    while ($row = $orgs->fetch_assoc()) {
                        $selected = (!empty($error_message) && isset($_POST['organization_id']) && $_POST['organization_id'] == $row['id']) ? 'selected' : '';
                        echo "<option value='" . htmlspecialchars($row['id']) . "' $selected>" . htmlspecialchars($row['organization_name']) . "</option>";
                    }
                    ?>
                </select>
            </div>
            <a href="add_organization.php" class="add-org-button">Add New Organization</a>
        </div>

        <div class="name-container">
            <div class="form-group">
                <label for="contact_first_name" class="required">First Name</label>
                <input type="text" name="contact_first_name" id="contact_first_name" required autocomplete="given-name" value="<?php echo !empty($error_message) ? htmlspecialchars($_POST['contact_first_name'] ?? '', ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
            <div class="form-group">
                <label for="contact_last_name" class="required">Last Name</label>
                <input type="text" name="contact_last_name" id="contact_last_name" required autocomplete="family-name" value="<?php echo !empty($error_message) ? htmlspecialchars($_POST['contact_last_name'] ?? '', ENT_QUOTES, 'UTF-8') : ''; ?>">
            </div>
        </div>

        <div class="role-container">
            <div class="form-group" style="flex: 0 0 200px;">
                <label for="contact_role" class="required">Role</label>
                <select name="contact_role" id="contact_role" required onchange="toggleOtherRole()">
                    <option value="pastor" <?php echo (!empty($error_message) && isset($_POST['contact_role']) && $_POST['contact_role'] === 'pastor') ? 'selected' : ''; ?>>Pastor</option>
                    <option value="admin" <?php echo (!empty($error_message) && isset($_POST['contact_role']) && $_POST['contact_role'] === 'admin') ? 'selected' : ''; ?>>Admin</option>
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
            <div class="phone-input-group" data-phone-input-group>
                <?php echo phoneCountryPicker('contact_phone_country_code', $contact_phone_country_code_value); ?>
                <input type="tel" name="contact_phone" id="contact_phone" value="<?php echo htmlspecialchars($contact_phone_local_value, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(111) 111-1111" autocomplete="tel-national" inputmode="tel" data-phone-number>
            </div>
        </div>

        <div class="form-group contact-photo-field">
            <div class="contact-photo-preview">
                <img src="<?php echo htmlspecialchars($contact_photo_placeholder, ENT_QUOTES, 'UTF-8'); ?>" alt="Contact photo preview" data-contact-photo-preview>
            </div>
            <div>
                <label for="contact_photo">Contact Photo</label>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo CONTACT_PHOTO_MAX_BYTES; ?>">
                <input type="file" id="contact_photo" name="contact_photo" accept="image/jpeg,image/png,image/webp" data-max-bytes="<?php echo CONTACT_PHOTO_MAX_BYTES; ?>" data-contact-photo-input>
                <p class="field-help">Optional. JPEG, PNG, or WebP; maximum 5 MB.</p>
                <p class="contact-photo-preview-status" hidden aria-live="polite" data-contact-photo-preview-status></p>
            </div>
        </div>
<br>
        <div class="form-group create-form-actions" style="padding-left: 0; margin-left: 0;">
            <a href="contacts.php" class="cancel-button">Cancel</a>
            <input type="submit" name="save_contact" value="Create contact" class="save-button" style="margin-left: 0;">
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
