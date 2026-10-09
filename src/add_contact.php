<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/record_creation_helpers.php';
$creation_operation_token = creationFormToken();
require_once __DIR__ . '/duplicate_warning_helpers.php';
require_once __DIR__ . '/record_workspace_helpers.php';
require_once __DIR__ . '/contact_organization_helpers.php';
include 'contact_photo_helpers.php';
startSecureSession();
$creation_return = safeRecordReturnUrl($_POST['return_to'] ?? $_GET['return_to'] ?? null, '');

// Ensure the user is logged in
requireLogin();
if (!hasRole(['admin', 'editor'])) {
    header("Location: contacts.php");
    exit();
}

$success_message = '';
$error_message = '';
$error_messages = [];
$error_field_ids = [];
$requested_organization_id = \Dnr\Http\RequestInput::positiveInt($_GET, 'organization_id');
$context_organization = null;
if ($requested_organization_id !== null) {
    $context_stmt = $conn->prepare(
        'SELECT id, organization_name
         FROM organizations
         WHERE id = ? AND is_deleted = 0'
    );
    if (!$context_stmt) {
        abortApplication(503, 'The selected organization is temporarily unavailable.', [
            'error' => $conn->error,
        ]);
    }
    $context_stmt->bind_param('i', $requested_organization_id);
    $context_stmt->execute();
    $context_organization = $context_stmt->get_result()->fetch_assoc() ?: null;
    $context_stmt->close();
    if ($context_organization === null) {
        $requested_organization_id = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_contact'])) {
    requireValidCsrfToken();
    $creation_operation_token = redirectCompletedRecordCreation($conn, 'contact', 'view_contact.php?id=', $creation_return);

    $normalized_contact = \Dnr\Domain\ContactInput::normalize($_POST);
    foreach ($normalized_contact['data'] as $field_name => $field_value) {
        ${$field_name} = $field_value;
    }
    $error_messages = $normalized_contact['errors'];
    foreach ($normalized_contact['error_fields'] as $index => $field_id) {
        if ($field_id !== null) $error_field_ids[$index] = $field_id;
    }
    $additional_organizations = [];
    try {
        $additional_organizations = normalizeContactOrganizationAffiliations(
            $_POST['additional_organizations'] ?? [],
            $organization_id
        );
    } catch (InvalidArgumentException $exception) {
        $error_messages[] = $exception->getMessage();
    }
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
        $error_field_ids[count($error_messages)] = 'contact_photo';
        $error_messages[] = $photo_error;
    }
    $duplicateWarning = creationDuplicateWarning($conn, 'contact', $_POST);
    if (!creationDuplicatesAcknowledged($duplicateWarning, $_POST)) $error_messages[] = 'Review possible existing records, or confirm that this is a different record.';
    if ($error_messages !== []) {
        $error_message = $error_messages[0];
    } else {
        $conn->begin_transaction();

        try {
        $replayed_id = beginRecordCreation($conn, (int) $_SESSION['user_id'], $creation_operation_token, 'contact');
        if ($replayed_id !== null) { $conn->commit(); header('Location: ' . ($creation_return !== '' ? recordUrlWithQuery($creation_return, ['created_contact_id' => $replayed_id]) : 'view_contact.php?id=' . $replayed_id), true, 303); exit(); }
            if ($organization_id !== null) {
                requireActiveOrganization($conn, $organization_id, true);
            }
            if ($contact_photo !== null) {
                $stmt = $conn->prepare(
                    "INSERT INTO contacts (
                        organization_id, contact_first_name, contact_last_name, contact_role,
                        contact_role_other, contact_email, contact_phone, contact_birthday, contact_notes,
                        contact_photo_key, contact_photo_thumbnail_key,
                        contact_photo_thumbnail_mime, contact_photo_mime,
                        contact_photo_sha256,
                        contact_photo_updated_at
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())"
                );
            } else {
                $stmt = $conn->prepare(
                    "INSERT INTO contacts (
                        organization_id, contact_first_name, contact_last_name, contact_role,
                        contact_role_other, contact_email, contact_phone, contact_birthday, contact_notes
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
            }
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the contact.');
            }
            if ($contact_photo !== null) {
                $contact_photo = storePersistentPortrait($conn, $contact_photo, 'contact');
                $contact_photo_data = $contact_photo['storage_key'];
                $contact_photo_thumbnail = $contact_photo['thumbnail_key'];
                $contact_photo_thumbnail_mime = $contact_photo['thumbnail_mime_type'];
                $contact_photo_mime = $contact_photo['mime_type'];
                $contact_photo_sha256 = $contact_photo['sha256'];
                $stmt->bind_param(
                    'isssssssssssss',
                    $organization_id,
                    $contact_first_name,
                    $contact_last_name,
                    $contact_role,
                    $contact_role_other,
                    $contact_email,
                    $contact_phone,
                    $contact_birthday,
                    $contact_notes,
                    $contact_photo_data,
                    $contact_photo_thumbnail,
                    $contact_photo_thumbnail_mime,
                    $contact_photo_mime,
                    $contact_photo_sha256
                );
            } else {
                $stmt->bind_param(
                    'issssssss',
                    $organization_id,
                    $contact_first_name,
                    $contact_last_name,
                    $contact_role,
                    $contact_role_other,
                    $contact_email,
                    $contact_phone,
                    $contact_birthday,
                    $contact_notes
                );
            }
            if (!$stmt->execute()) {
                throw new RuntimeException('Unable to add the contact.');
            }
            $contact_id = $conn->insert_id;
            $stmt->close();
            syncContactOrganizations(
                $conn,
                (int) $contact_id,
                $organization_id,
                $contact_role === 'other' ? $contact_role_other : ucfirst($contact_role),
                $additional_organizations
            );
            completeRecordCreation($conn, (int) $_SESSION['user_id'], $creation_operation_token, (int) $contact_id);
                    $conn->commit();
            $_SESSION['success_message'] = 'Contact added successfully.';
            $return_to_organization = $requested_organization_id !== null
                && $organization_id === $requested_organization_id;
            header(
                'Location: ' . ($creation_return !== '' ? recordUrlWithQuery($creation_return, ['created_contact_id' => $contact_id]) : ($return_to_organization
                    ? 'view_organization.php?id=' . $requested_organization_id
                    : 'view_contact.php?id=' . $contact_id))
            );
            exit();
        } catch (Throwable $exception) {
            $conn->rollback();
            $error_message = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'Unable to add the contact.';
            $error_messages[] = $error_message;
        }
    }
}

require_once __DIR__ . '/organization_options_helpers.php';
$selected_options = [$_POST['organization_id'] ?? $_GET['organization_id'] ?? null];
foreach (array_slice(is_array($_POST['additional_organizations'] ?? null) ? $_POST['additional_organizations'] : [], 0, 100) as $row) {
    if (is_array($row)) $selected_options[] = $row['organization_id'] ?? null;
}
$organization_search = \Dnr\Http\RequestInput::string($_GET, 'organization_search');
$contact_organization_options = boundedOrganizationOptions($conn, $selected_options, $organization_search);
$additional_organization_rows = $_POST['additional_organizations'] ?? [];

$contact_phone_country_code_value = trim($_POST['contact_phone_country_code'] ?? applicationDefaultPhoneCountryCode());
[, $contact_phone_local_value] = phoneNumberInputParts(
    $_POST['contact_phone'] ?? '',
    $contact_phone_country_code_value
);
$contact_photo_placeholder = 'data:image/svg+xml;base64,' . base64_encode(contactInitialsSvg([
    'contact_first_name' => $_POST['contact_first_name'] ?? '',
    'contact_last_name' => $_POST['contact_last_name'] ?? '',
]));
$selected_organization_id = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? filter_input(INPUT_POST, 'organization_id', FILTER_VALIDATE_INT)
    : $requested_organization_id;
$add_contact_action = 'add_contact.php'
    . ($requested_organization_id !== null
        ? '?' . http_build_query(['organization_id' => $requested_organization_id])
        : '');
$cancel_url = $creation_return !== '' ? $creation_return : ($requested_organization_id !== null
    ? 'view_organization.php?id=' . $requested_organization_id
    : 'contacts.php');
?>

<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Add Contact'), array (
  'styles' =>
  array (
    'assets/css/style.min.css',
    'assets/css/modern.min.css',
    'assets/css/pages/record_workspace.min.css',
    'assets/css/pages/add_contact.min.css',
    'assets/css/pages/contact_form.min.css',
  ),
  'scripts' =>
  array (
    0 =>
    array (
      'path' => 'assets/js/contact-photo.min.js',
    ),
    array (
      'path' => 'assets/js/relationship-search.min.js',
    ),
  ),
)); ?>
<body class="add-contact-body">
<?php include 'templates/header.php'; ?>
<main class="container add-contact-page">
    <?php if (!empty($error_message)): ?>
        <?php echo formErrorSummary($error_messages, $error_field_ids); ?>
    <?php endif; ?>
    <?php if (!empty($success_message)): ?>
        <div class="success"><?php echo htmlspecialchars($success_message); ?></div>
    <?php endif; ?>

    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="contacts.php">Contacts</a><span aria-hidden="true">/</span><span>New Contact</span></nav>
    <div class="page-heading form-page-heading add-contact-heading"><div><h1>New Contact</h1><p class="page-intro"><?php echo $context_organization !== null
        ? 'Add a contact for ' . htmlspecialchars((string) $context_organization['organization_name'], ENT_QUOTES, 'UTF-8') . '.'
        : 'Connect a person with their organizations and roles.'; ?></p></div></div>
    <?php include __DIR__ . '/templates/organization_search_fallback.php'; ?>
    <p class="required-fields-note"><span aria-hidden="true">*</span> Required fields</p>
    <form method="post" action="<?php echo htmlspecialchars($add_contact_action, ENT_QUOTES, 'UTF-8'); ?>" enctype="multipart/form-data" class="contact-form contact-layout-form" data-duplicate-kind="contact">
<?php renderCreationDuplicateWarning($duplicateWarning ?? ['matches'=>[], 'token'=>''], 'contact', $creation_return); ?>

        <?php echo csrfInput(); echo creationTokenInput($creation_operation_token); ?>
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($creation_return, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="contact-form-overview">
            <section class="contact-form-photo" aria-label="Contact Photo">
                <div class="form-group contact-photo-field">
                    <div class="contact-photo-upload">
                        <div class="contact-photo-preview">
                            <img src="<?php echo htmlspecialchars($contact_photo_placeholder, ENT_QUOTES, 'UTF-8'); ?>" width="96" height="96" alt="Contact photo preview" data-contact-photo-preview>
                        </div>
                        <div>
                            <label for="contact_photo">Contact Photo</label>
                            <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo CONTACT_PHOTO_MAX_BYTES; ?>">
                            <input type="file" id="contact_photo" name="contact_photo" accept="image/jpeg,image/png,image/webp" data-max-bytes="<?php echo CONTACT_PHOTO_MAX_BYTES; ?>" data-contact-photo-input>
                            <p class="field-help">Optional. JPEG, PNG, or WebP; maximum 5 MB.</p>
                        </div>
                    </div>
                    <?php include __DIR__ . '/templates/contact_photo_paste.php'; ?>
                    <div class="contact-photo-feedback">
                        <p class="contact-photo-preview-status" hidden aria-live="polite" data-contact-photo-preview-status></p>
                    </div>
                </div>
            </section>
            <section class="contact-form-details" aria-label="Contact Details">
                <div class="form-row">
                    <div class="form-group">
                        <label for="contact_first_name" class="required">First Name</label>
                        <input type="text" name="contact_first_name" id="contact_first_name" required autocomplete="given-name" value="<?php echo !empty($error_message) ? htmlspecialchars($_POST['contact_first_name'] ?? '', ENT_QUOTES, 'UTF-8') : ''; ?>">
                    </div>
                    <div class="form-group">
                        <label for="contact_last_name" class="required">Last Name</label>
                        <input type="text" name="contact_last_name" id="contact_last_name" required autocomplete="family-name" value="<?php echo !empty($error_message) ? htmlspecialchars($_POST['contact_last_name'] ?? '', ENT_QUOTES, 'UTF-8') : ''; ?>">
                    </div>
                </div>

                <div class="form-group contact-form-email">
                    <label for="contact_email" class="required">Email</label>
                    <input type="email" name="contact_email" id="contact_email" required value="<?php echo !empty($error_message) ? htmlspecialchars($_POST['contact_email'] ?? '') : ''; ?>">
                </div>

                <div class="contact-phone-birthday-row">
                    <div class="form-group contact-phone-field">
                        <div class="contact-control-pair">
                            <label for="contact_phone">Phone Number</label>
                            <div class="phone-input-group" data-phone-input-group>
                                <?php echo phoneCountryPicker('contact_phone_country_code', $contact_phone_country_code_value); ?>
                                <input type="tel" name="contact_phone" id="contact_phone" value="<?php echo htmlspecialchars($contact_phone_local_value, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(111) 111-1111" autocomplete="tel-national" inputmode="tel" data-phone-number>
                            </div>
                        </div>
                    </div>

                    <div class="form-group contact-birthday-field">
                        <div class="contact-control-pair">
                            <label for="contact_birthday">Birthday</label>
                            <input type="text" name="contact_birthday" id="contact_birthday" value="<?php echo htmlspecialchars($_POST['contact_birthday'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="MM/DD" inputmode="numeric" autocomplete="bday" maxlength="5" pattern="[0-9]{2}/[0-9]{2}" aria-describedby="contact-birthday-help">
                        </div>
                        <p class="field-help" id="contact-birthday-help">Optional; repeats annually.</p>
                    </div>
                </div>
            </section>
            <section class="contact-form-organization" aria-label="Organization and Role">
                <div class="contact-form-organization-selector">
                    <div class="form-group">
                        <label for="primary-organization-search">Find an Organization</label>
                        <input type="search" id="primary-organization-search" placeholder="Find an organization" aria-label="Find an organization for Primary Organization" data-relationship-search-input>
                    </div>
                    <div class="form-group contact-form-primary-organization">
                        <label for="organization_id">Primary Organization</label>
                        <select name="organization_id" id="organization_id" data-organization-search data-relationship-search-input-id="primary-organization-search">
                            <option value="" <?php echo empty($selected_organization_id) ? 'selected' : ''; ?>>No Organization</option>
                            <?php foreach ($contact_organization_options as $row): ?>
                                <option value="<?php echo (int) $row['id']; ?>" <?php echo (int) $selected_organization_id === (int) $row['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($row['organization_name'], ENT_QUOTES, 'UTF-8'); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="field-help" role="status" data-relationship-search-status></p>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group contact-role-field">
                        <div class="contact-control-pair">
                            <label for="contact_role" class="required">Primary Role</label>
                            <select name="contact_role" id="contact_role" required>
                                <?php foreach (\Dnr\Domain\ReferenceData::contactRoles() as $role): ?>
                                    <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo (!empty($error_message) && ($_POST['contact_role'] ?? '') === $role) ? 'selected' : ''; ?>><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($role), ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" id="other_role_group" <?php echo (!empty($error_message) && isset($_POST['contact_role']) && $_POST['contact_role'] === 'other') ? '' : 'hidden'; ?>>
                        <div class="contact-control-pair">
                            <label for="contact_role_other" class="required">Other Role Description</label>
                            <input type="text" name="contact_role_other" id="contact_role_other" value="<?php echo !empty($error_message) ? htmlspecialchars($_POST['contact_role_other'] ?? '') : ''; ?>">
                        </div>
                    </div>
                </div>

                <details class="inline-organization-creator" data-inline-organization>
                    <summary>New Organization</summary>
                    <label for="inline-organization-name">Organization Name</label>
                    <input id="inline-organization-name" type="text" maxlength="255" data-organization-name autocomplete="organization">
                    <button type="button" class="button-secondary" data-create-organization>Create and Select</button>
                    <p data-organization-status role="status" aria-live="polite"></p>
                    <noscript><p>JavaScript is needed to create an organization here. You can save this contact without an organization and link it later.</p></noscript>
                </details>
            </section>
        </div>

        <?php include __DIR__ . '/templates/contact_organization_affiliations.php'; ?>

        <div class="form-group">
            <label for="contact_notes">Notes</label>
            <textarea name="contact_notes" id="contact_notes" rows="6" placeholder="Add incidental notes about this person."><?php echo htmlspecialchars($_POST['contact_notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>

        <div class="form-group create-form-actions create-form-actions-flush">
            <a href="<?php echo htmlspecialchars($cancel_url, ENT_QUOTES, 'UTF-8'); ?>" class="cancel-button">Cancel</a>
            <input type="submit" name="save_contact" value="Create Contact" class="save-button save-button-flush">
        </div>
    </form>
</main>

<?php renderScript('assets/js/record-workspace.min.js'); ?>
<?php include 'templates/footer.php'; ?>
</body>
</html>
