<?php
require_once __DIR__ . '/bootstrap.php';
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

    $normalized_contact = \Dnr\Domain\ContactInput::normalize($_POST);
    foreach ($normalized_contact['data'] as $field_name => $field_value) {
        ${$field_name} = $field_value;
    }
    $validation_errors = $normalized_contact['errors'];
    $photo_error = '';
    $contact_photo = null;
    try {
        $contact_photo = contactPhotoFromUpload($_FILES['contact_photo'] ?? []);
    } catch (InvalidArgumentException $exception) {
        $photo_error = $exception->getMessage();
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to read contact photo upload', ['error' => $exception->getMessage()]);
        $photo_error = 'The contact photo could not be uploaded. Try again.';
    }

    if ($photo_error !== '') {
        $error_message = $photo_error;
    } elseif ($validation_errors) {
        $error_message = $validation_errors[0];
    } else {
        $conn->begin_transaction();
        try {
            requireActiveOrganization($conn, $organization_id, true);
            if ($contact_photo !== null) {
                $stmt = $conn->prepare(
                    "INSERT INTO contacts (
                        organization_id, contact_first_name, contact_last_name, contact_role,
                        contact_role_other, contact_email, contact_phone, contact_notes,
                        contact_photo, contact_photo_thumbnail,
                        contact_photo_thumbnail_mime, contact_photo_mime,
                        contact_photo_sha256,
                        contact_photo_updated_at
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
                );
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO contacts (
                        organization_id, contact_first_name, contact_last_name, contact_role,
                        contact_role_other, contact_email, contact_phone, contact_notes
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                );
            }
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the contact.');
            }
            if ($contact_photo !== null) {
                $contact_photo_data = $contact_photo['data'];
                $contact_photo_thumbnail = $contact_photo['thumbnail_data'];
                $contact_photo_thumbnail_mime = $contact_photo['thumbnail_mime_type'];
                $contact_photo_mime = $contact_photo['mime_type'];
                $contact_photo_sha256 = $contact_photo['sha256'];
                $stmt->bind_param(
                    'issssssssssss',
                    $organization_id,
                    $contact_first_name,
                    $contact_last_name,
                    $contact_role,
                    $contact_role_other,
                    $contact_email,
                    $contact_phone,
                    $contact_notes,
                    $contact_photo_data,
                    $contact_photo_thumbnail,
                    $contact_photo_thumbnail_mime,
                    $contact_photo_mime,
                    $contact_photo_sha256
                );
            } else {
                $stmt->bind_param(
                    'isssssss',
                    $organization_id,
                    $contact_first_name,
                    $contact_last_name,
                    $contact_role,
                    $contact_role_other,
                    $contact_email,
                    $contact_phone,
                    $contact_notes
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
<?php renderPageHead('Add Contact - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/add_contact.min.css',
  ),
  'scripts' =>
  array (
    0 =>
    array (
      'path' => 'assets/js/contact-photo.min.js',
    ),
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <?php if (!empty($error_message)): ?>
        <div class="error"><?php echo htmlspecialchars($error_message); ?></div>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
        <div class="success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="contacts.php">Contacts</a><span aria-hidden="true">/</span><span>New Contact</span></nav>
    <div class="page-heading form-page-heading"><div><h1>New Contact</h1><p class="page-intro">Connect a person with an organization and their role.</p></div></div>
    <form method="post" action="add_contact.php" enctype="multipart/form-data" class="contact-form">
        <?php echo csrfInput(); ?>
        <p class="required-fields-note"><span aria-hidden="true">*</span> Required fields</p>
        <div class="organization-container">
            <div class="form-group form-flex-one">
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
            <div class="form-group contact-role-field">
                <label for="contact_role" class="required">Role</label>
                <select name="contact_role" id="contact_role" required>
                    <?php foreach (\Dnr\Domain\ReferenceData::contactRoles() as $role): ?>
                        <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (!empty($error_message) && ($_POST['contact_role'] ?? '') === $role) ? 'selected' : ''; ?>><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($role), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="other_role_group" <?php echo (!empty($error_message) && isset($_POST['contact_role']) && $_POST['contact_role'] === 'other') ? '' : 'hidden'; ?>>
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

        <div class="form-group">
            <label for="contact_notes">Notes</label>
            <textarea name="contact_notes" id="contact_notes" rows="5" placeholder="Add incidental notes about this person."><?php echo htmlspecialchars($_POST['contact_notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
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
        <div class="form-group create-form-actions create-form-actions-flush">
            <a href="contacts.php" class="cancel-button">Cancel</a>
            <input type="submit" name="save_contact" value="Create contact" class="save-button save-button-flush">
        </div>
    </form>
</div>

<?php include 'templates/footer.php'; ?>
</body>
</html>
