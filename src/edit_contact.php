<?php
include 'config.php';
include 'functions.php';
include 'contact_photo_helpers.php';
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
    "SELECT c.id, c.organization_id, c.contact_first_name, c.contact_last_name,
            c.contact_role, c.contact_role_other, c.contact_email, c.contact_phone,
            c.contact_notes, c.contact_photo_mime, c.contact_photo_sha256,
            c.contact_photo_updated_at, c.is_deleted
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
    $contact_notes = trim($_POST['contact_notes'] ?? '');
    $contact_phone_country_code = trim($_POST['contact_phone_country_code'] ?? '+1');
    $remove_contact_photo = isset($_POST['remove_contact_photo']);
    $contact_photo = null;

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
    try {
        $contact_phone = normalizePhoneNumber(
            $contact_phone_country_code,
            $contact_phone,
            'Phone number'
        );
    } catch (InvalidArgumentException $exception) {
        $error_messages[] = $exception->getMessage();
    }
    try {
        $contact_photo = contactPhotoFromUpload($_FILES['contact_photo'] ?? []);
        if ($contact_photo !== null && $remove_contact_photo) {
            throw new InvalidArgumentException('Choose either a new contact photo or remove the current photo.');
        }
    } catch (InvalidArgumentException $exception) {
        $error_messages[] = $exception->getMessage();
    } catch (Throwable $exception) {
        error_log('Unable to read contact photo upload: ' . $exception->getMessage());
        $error_messages[] = 'The contact photo could not be uploaded. Try again.';
    }

    if (!$error_messages) {
        $conn->begin_transaction();
        try {
            requireActiveOrganization($conn, $organization_id, true);
            if ($contact_photo !== null) {
                $update_stmt = $conn->prepare(
                    "UPDATE contacts SET
                        organization_id = ?,
                        contact_first_name = ?,
                        contact_last_name = ?,
                        contact_role = ?,
                        contact_role_other = ?,
                        contact_email = ?,
                        contact_phone = ?,
                        contact_notes = ?,
                        contact_photo = ?,
                        contact_photo_mime = ?,
                        contact_photo_sha256 = ?,
                        contact_photo_updated_at = UTC_TIMESTAMP()
                     WHERE id = ? AND is_deleted = 0"
                );
            } elseif ($remove_contact_photo) {
                $update_stmt = $conn->prepare(
                    "UPDATE contacts SET
                        organization_id = ?,
                        contact_first_name = ?,
                        contact_last_name = ?,
                        contact_role = ?,
                        contact_role_other = ?,
                        contact_email = ?,
                        contact_phone = ?,
                        contact_notes = ?,
                        contact_photo = NULL,
                        contact_photo_mime = NULL,
                        contact_photo_sha256 = NULL,
                        contact_photo_updated_at = UTC_TIMESTAMP()
                     WHERE id = ? AND is_deleted = 0"
                );
            } else {
                $update_stmt = $conn->prepare(
                    "UPDATE contacts SET
                        organization_id = ?,
                        contact_first_name = ?,
                        contact_last_name = ?,
                        contact_role = ?,
                        contact_role_other = ?,
                        contact_email = ?,
                        contact_phone = ?,
                        contact_notes = ?
                     WHERE id = ? AND is_deleted = 0"
                );
            }
            if (!$update_stmt) {
                throw new RuntimeException('Unable to prepare the contact update.');
            }
            if ($contact_photo !== null) {
                $contact_photo_data = $contact_photo['data'];
                $contact_photo_mime = $contact_photo['mime_type'];
                $contact_photo_sha256 = $contact_photo['sha256'];
                $update_stmt->bind_param(
                    'issssssssssi',
                    $organization_id,
                    $contact_first_name,
                    $contact_last_name,
                    $contact_role,
                    $contact_role_other,
                    $contact_email,
                    $contact_phone,
                    $contact_notes,
                    $contact_photo_data,
                    $contact_photo_mime,
                    $contact_photo_sha256,
                    $contact_id
                );
            } else {
                $update_stmt->bind_param(
                    'isssssssi',
                    $organization_id,
                    $contact_first_name,
                    $contact_last_name,
                    $contact_role,
                    $contact_role_other,
                    $contact_email,
                    $contact_phone,
                    $contact_notes,
                    $contact_id
                );
            }
            if (!$update_stmt->execute() || $update_stmt->affected_rows > 1) {
                throw new RuntimeException('Unable to update the contact.');
            }
            $update_stmt->close();
            $conn->commit();
            $_SESSION['success_message'] = 'Contact updated successfully.';
            header("Location: view_contact.php?id={$contact_id}");
            exit();
        } catch (Throwable $exception) {
            $conn->rollback();
            $error_messages[] = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'Unable to update the contact.';
        }
    }

    $contact['organization_id'] = $organization_id;
    $contact['contact_first_name'] = $contact_first_name;
    $contact['contact_last_name'] = $contact_last_name;
    $contact['contact_role'] = $contact_role;
    $contact['contact_role_other'] = $contact_role_other;
    $contact['contact_email'] = $contact_email;
    $contact['contact_phone'] = $contact_phone;
    $contact['contact_notes'] = $contact_notes;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contact_phone_country_code_value = trim($_POST['contact_phone_country_code'] ?? '+1');
    [, $contact_phone_local_value] = phoneNumberInputParts(
        $_POST['contact_phone'] ?? '',
        $contact_phone_country_code_value
    );
} else {
    [$contact_phone_country_code_value, $contact_phone_local_value] = phoneNumberInputParts(
        $contact['contact_phone'] ?? ''
    );
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
$contact_photo_version = strtotime((string) ($contact['contact_photo_updated_at'] ?? '')) ?: 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Contact - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
    <script src="assets/js/contact-photo.min.js?v=1.0.0" defer></script>
    <style>
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #fff;
            color: #000;
            box-sizing: border-box;
        }
        .dark-mode .form-group input,
        .dark-mode .form-group select,
        .dark-mode .form-group textarea {
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
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="contacts.php">Contacts</a><span aria-hidden="true">/</span><span>Edit Contact</span></nav>
    <div class="page-heading form-page-heading"><div><h1>Edit Contact</h1><p class="page-intro">Update contact information, role, and organization.</p></div></div>

    <?php if ($error_messages): ?>
        <p class="error"><?php echo implode(
            '<br>',
            array_map(fn($message) => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), $error_messages)
        ); ?></p>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data" action="edit_contact.php?id=<?php echo $contact_id; ?><?php echo ($_GET['from'] ?? '') === 'view' ? '&amp;from=view' : ''; ?>">
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
                <select name="contact_role" id="contact_role" required>
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
            <div class="phone-input-group" data-phone-input-group>
                <?php echo phoneCountryPicker('contact_phone_country_code', $contact_phone_country_code_value); ?>
                <input type="tel" name="contact_phone" id="contact_phone" value="<?php echo htmlspecialchars($contact_phone_local_value, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(111) 111-1111" autocomplete="tel-national" inputmode="tel" data-phone-number>
            </div>
        </div>

        <div class="form-group">
            <label for="contact_notes">Notes</label>
            <textarea name="contact_notes" id="contact_notes" rows="5" placeholder="Add incidental notes about this person."><?php echo htmlspecialchars($contact['contact_notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>

        <div class="form-group contact-photo-field">
            <div class="contact-photo-preview">
                <img src="contact_photo.php?id=<?php echo $contact_id; ?>&amp;v=<?php echo $contact_photo_version; ?>" alt="Current contact photo for <?php echo htmlspecialchars($contact['contact_first_name'] . ' ' . $contact['contact_last_name'], ENT_QUOTES, 'UTF-8'); ?>" data-contact-photo-preview>
            </div>
            <div>
                <label for="contact_photo">Contact Photo</label>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo CONTACT_PHOTO_MAX_BYTES; ?>">
                <input type="file" id="contact_photo" name="contact_photo" accept="image/jpeg,image/png,image/webp" data-max-bytes="<?php echo CONTACT_PHOTO_MAX_BYTES; ?>" data-contact-photo-input>
                <p class="field-help">JPEG, PNG, or WebP; maximum 5 MB.</p>
                <p class="contact-photo-preview-status" hidden aria-live="polite" data-contact-photo-preview-status></p>
                <?php if (!empty($contact['contact_photo_mime'])): ?>
                    <label class="contact-photo-remove"><input type="checkbox" name="remove_contact_photo" value="1" <?php echo isset($_POST['remove_contact_photo']) ? 'checked' : ''; ?> data-remove-contact-photo> Remove current photo</label>
                <?php endif; ?>
            </div>
        </div>

        <div class="action-buttons">
            <a href="<?php echo htmlspecialchars($cancel_url, ENT_QUOTES, 'UTF-8'); ?>" class="action-button cancel-button">Cancel</a>
            <button type="submit" class="action-button save-button">Save Changes</button>
        </div>
    </form>
</div>

<script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>">
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

document.getElementById('contact_role').addEventListener('change', toggleOtherRole);
toggleOtherRole();
</script>

<?php include 'templates/footer.php'; ?>
</body>
</html>
