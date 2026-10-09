<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/record_creation_helpers.php';
$creation_operation_token = creationFormToken();
require_once __DIR__ . '/duplicate_warning_helpers.php';
require_once __DIR__ . '/record_workspace_helpers.php';
require_once __DIR__ . '/contact_organization_helpers.php';
require_once __DIR__ . '/contact_photo_helpers.php';
require_once __DIR__ . '/organization_options_helpers.php';
startSecureSession();
$creation_return = safeRecordReturnUrl($_POST['return_to'] ?? $_GET['return_to'] ?? null, '');

requireLogin();
if (!hasRole(['admin', 'editor'])) {
    header("Location: organizations.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_org'])) {
    requireValidCsrfToken();
    $creation_operation_token = redirectCompletedRecordCreation($conn, 'organization', 'view_organization.php?id=', $creation_return);

    $error = false;
    $errorMessages = array();
    $errorFieldIds = [];

    $normalized_organization = \Dnr\Domain\OrganizationInput::normalize($_POST);
    foreach ($normalized_organization['data'] as $field_name => $field_value) {
        ${$field_name} = $field_value;
    }
    $errorMessages = $normalized_organization['errors'];
    foreach ($normalized_organization['error_fields'] as $index => $field_id) {
        if ($field_id !== null) $errorFieldIds[$index] = $field_id;
    }
    $existing_contacts = [];
    try {
        $existing_contacts = normalizeOrganizationExistingContacts($_POST['existing_contacts'] ?? null);
    } catch (InvalidArgumentException $exception) {
        $errorMessages[] = $exception->getMessage();
    }

    $contact_first_name = trim($_POST['contact_first_name'] ?? '');
    $contact_last_name = trim($_POST['contact_last_name'] ?? '');
    $contact_role = strtolower(trim($_POST['contact_role'] ?? ''));
    $contact_role_other = trim($_POST['contact_role_other'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_email_confirm = trim($_POST['contact_email_confirm'] ?? ($_POST['contact_email'] ?? ''));
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $contact_birthday = trim($_POST['contact_birthday'] ?? '');
    $contact_notes = trim($_POST['contact_notes'] ?? '');
    $contact_phone_country_code = trim($_POST['contact_phone_country_code'] ?? applicationDefaultPhoneCountryCode());

    $contact_candidates = [[
        'first_name' => $contact_first_name,
        'last_name' => $contact_last_name,
        'role' => $contact_role,
        'role_other' => $contact_role_other,
        'email' => $contact_email,
        'email_confirm' => $contact_email_confirm,
        'phone' => $contact_phone,
        'birthday' => $contact_birthday,
        'notes' => $contact_notes,
        'phone_country_code' => $contact_phone_country_code,
        'photo_upload' => is_array($_FILES['contact_photo'] ?? null) ? $_FILES['contact_photo'] : [],
        'field_prefix' => 'contact_',
    ]];
    if (isset($_POST['contacts']) && is_array($_POST['contacts'])) {
        foreach ($_POST['contacts'] as $submitted_index => $submitted_contact) {
            if (!is_array($submitted_contact)) {
                continue;
            }
            $contact_candidates[] = [
                'first_name' => trim($submitted_contact['first_name'] ?? ''),
                'last_name' => trim($submitted_contact['last_name'] ?? ''),
                'role' => strtolower(trim($submitted_contact['role'] ?? '')),
                'role_other' => trim($submitted_contact['role_other'] ?? ''),
                'email' => trim($submitted_contact['email'] ?? ''),
                'email_confirm' => trim($submitted_contact['email_confirm'] ?? ($submitted_contact['email'] ?? '')),
                'phone' => trim($submitted_contact['phone'] ?? ''),
                'birthday' => trim($submitted_contact['birthday'] ?? ''),
                'notes' => trim($submitted_contact['notes'] ?? ''),
                'phone_country_code' => trim($submitted_contact['phone_country_code'] ?? applicationDefaultPhoneCountryCode()),
                'photo_upload' => organizationContactPhotoUpload(is_array($_FILES['contacts'] ?? null) ? $_FILES['contacts'] : [], $submitted_index),
                'field_prefix' => 'additional-' . count($contact_candidates) . '-',
            ];
        }
    }

    $contacts_to_create = [];
    foreach ($contact_candidates as $contact_index => $candidate) {
        $contact_number = $contact_index + 1;
        $has_contact_data = implode('', [
            $candidate['first_name'],
            $candidate['last_name'],
            $candidate['role'],
            $candidate['role_other'],
            $candidate['email'],
            $candidate['email_confirm'],
            $candidate['phone'],
            $candidate['birthday'],
            $candidate['notes'],
        ]) !== '';
        $has_photo_upload = (int) ($candidate['photo_upload']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
        if (!$has_contact_data && !$has_photo_upload) {
            continue;
        }
        $photo = null;
        try {
            $photo = contactPhotoFromUpload($candidate['photo_upload']);
        } catch (InvalidArgumentException $exception) {
            $errorFieldIds[count($errorMessages)] = $candidate['field_prefix'] . 'photo';
            $errorMessages[] = "Contact {$contact_number}: " . $exception->getMessage();
        }
        $normalized_contact = \Dnr\Domain\ContactInput::normalizeEmbedded($candidate);
        foreach ($normalized_contact['errors'] as $error_index => $contact_error) {
            $contact_field = $normalized_contact['error_fields'][$error_index] ?? null;
            if ($contact_field !== null) {
                $errorFieldIds[count($errorMessages)] = $candidate['field_prefix'] . $contact_field;
            }
            $errorMessages[] = "Contact {$contact_number}: {$contact_error}";
        }
        $contacts_to_create[] = $normalized_contact['data'] + ['photo' => $photo];
    }

    $duplicateWarning = creationDuplicateWarning($conn, 'organization', $_POST);
    if (!creationDuplicatesAcknowledged($duplicateWarning, $_POST)) $errorMessages[] = 'Review possible existing records, or confirm that this is a different record.';
    $error = !empty($errorMessages);
    if (!$error) {
        $check_stmt = $conn->prepare("SELECT id FROM organizations WHERE organization_name = ?");
        $check_stmt->bind_param("s", $organization_name);
        $check_stmt->execute();

        if ($check_stmt->get_result()->num_rows > 0 && !creationDuplicatesAcknowledged($duplicateWarning, $_POST)) {
            $error = true;
            $errorFieldIds[count($errorMessages)] = 'organization_name';
            $errorMessages[] = "An organization with this name already exists.";
        } else {
            $conn->begin_transaction();

            try {
        $replayed_id = beginRecordCreation($conn, (int) $_SESSION['user_id'], $creation_operation_token, 'organization');
        if ($replayed_id !== null) { $conn->commit(); header('Location: ' . ($creation_return !== '' ? recordUrlWithQuery($creation_return, ['created_organization_id' => $replayed_id]) : 'view_organization.php?id=' . $replayed_id), true, 303); exit(); }
                $org_stmt = $conn->prepare(
                    "INSERT INTO organizations (
                        organization_name, notes, affiliation, distinctives, website_url, phone, fax,
                        mailing_address_line_1, mailing_address_line_2, mailing_city, mailing_state,
                        mailing_zipcode, mailing_country, physical_address_line_1, physical_address_line_2,
                        physical_city, physical_state, physical_zipcode, physical_country
                     ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $org_stmt->bind_param(
                    "sssssssssssssssssss",
                    $organization_name, $notes, $affiliation, $distinctives, $website_url, $phone, $fax,
                    $mailing_address_line_1, $mailing_address_line_2, $mailing_city, $mailing_state,
                    $mailing_zipcode, $mailing_country, $physical_address_line_1, $physical_address_line_2,
                    $physical_city, $physical_state, $physical_zipcode, $physical_country
                );
                if (!$org_stmt->execute()) {
                    throw new RuntimeException("Unable to save organization.");
                }

                $organization_id = $conn->insert_id;
                if (!empty($contacts_to_create)) {
                    $contact_stmt = $conn->prepare(
                        "INSERT INTO contacts (
                            organization_id, contact_first_name, contact_last_name, contact_role,
                            contact_role_other, contact_email, contact_phone, contact_birthday, contact_notes,
                            contact_photo_key, contact_photo_thumbnail_key, contact_photo_thumbnail_mime,
                            contact_photo_mime, contact_photo_sha256, contact_photo_updated_at
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $saved_contact_first_name = '';
                    $saved_contact_last_name = '';
                    $saved_contact_role = '';
                    $saved_contact_role_other = '';
                    $saved_contact_email = '';
                    $saved_contact_phone = '';
                    $saved_contact_birthday = null;
                    $saved_contact_notes = '';
                    $saved_contact_photo_key = $saved_contact_photo_thumbnail_key = null;
                    $saved_contact_photo_thumbnail_mime = $saved_contact_photo_mime = null;
                    $saved_contact_photo_sha256 = $saved_contact_photo_updated_at = null;
                    $contact_stmt->bind_param(
                        "issssssssssssss",
                        $organization_id,
                        $saved_contact_first_name,
                        $saved_contact_last_name,
                        $saved_contact_role,
                        $saved_contact_role_other,
                        $saved_contact_email,
                        $saved_contact_phone,
                        $saved_contact_birthday,
                        $saved_contact_notes,
                        $saved_contact_photo_key,
                        $saved_contact_photo_thumbnail_key,
                        $saved_contact_photo_thumbnail_mime,
                        $saved_contact_photo_mime,
                        $saved_contact_photo_sha256,
                        $saved_contact_photo_updated_at
                    );

                    foreach ($contacts_to_create as $contact_to_create) {
                        $saved_contact_first_name = $contact_to_create['first_name'];
                        $saved_contact_last_name = $contact_to_create['last_name'];
                        $saved_contact_role = $contact_to_create['role'];
                        $saved_contact_role_other = $contact_to_create['role_other'];
                        $saved_contact_email = $contact_to_create['email'];
                        $saved_contact_phone = $contact_to_create['phone'];
                        $saved_contact_birthday = $contact_to_create['birthday'];
                        $saved_contact_notes = $contact_to_create['notes'];
                        $portrait = $contact_to_create['photo'] === null ? null : storePersistentPortrait($conn, $contact_to_create['photo'], 'contact');
                        $saved_contact_photo_key = $portrait['storage_key'] ?? null;
                        $saved_contact_photo_thumbnail_key = $portrait['thumbnail_key'] ?? null;
                        $saved_contact_photo_thumbnail_mime = $portrait['thumbnail_mime_type'] ?? null;
                        $saved_contact_photo_mime = $portrait['mime_type'] ?? null;
                        $saved_contact_photo_sha256 = $portrait['sha256'] ?? null;
                        $saved_contact_photo_updated_at = $portrait === null ? null : gmdate('Y-m-d H:i:s');
                        if (!$contact_stmt->execute()) {
                            throw new RuntimeException("Unable to save contact.");
                        }
                    }
                }

                foreach ($existing_contacts as $existing_contact) {
                    ensureContactOrganizationAffiliation($conn, (int) $existing_contact['contact_id'],
                        (int) $organization_id, $existing_contact['role_title']);
                }
                completeRecordCreation($conn, (int) $_SESSION['user_id'], $creation_operation_token, (int) $organization_id);
                    $conn->commit();
                $_SESSION['success_message'] = !empty($contacts_to_create) || !empty($existing_contacts)
                    ? "Organization and contact information saved successfully."
                    : "Organization saved successfully.";
                header('Location: ' . ($creation_return !== ''
                    ? recordUrlWithQuery($creation_return, ['created_organization_id' => $organization_id])
                    : 'view_organization.php?id=' . $organization_id));
                exit();
            } catch (Throwable $exception) {
                $conn->rollback();
                applicationLog('error', 'Organization creation failed', ['error' => $exception->getMessage()]);
                $error = true;
                $errorMessages[] = $exception instanceof InvalidArgumentException
                    ? $exception->getMessage() : "Unable to save the organization.";
            }
        }
    }
}

$existing_contact_rows = organizationExistingContactFormRows($_POST['existing_contacts'] ?? null);
$contact_search = \Dnr\Http\RequestInput::string($_GET, 'contact_search', '', 128);
$existing_contact_options = boundedContactOptions(
    $conn,
    array_column($existing_contact_rows, 'contact_id'),
    $contact_search
);
$existing_contact_option_ids = array_map('intval', array_column($existing_contact_options, 'id'));
$phone_country_code_value = trim($_POST['phone_country_code'] ?? applicationDefaultPhoneCountryCode());
[, $phone_local_value] = phoneNumberInputParts($_POST['phone'] ?? '', $phone_country_code_value);
$fax_country_code_value = trim($_POST['fax_country_code'] ?? applicationDefaultPhoneCountryCode());
[, $fax_local_value] = phoneNumberInputParts($_POST['fax'] ?? '', $fax_country_code_value);
$contact_phone_country_code_value = trim($_POST['contact_phone_country_code'] ?? applicationDefaultPhoneCountryCode());
[, $contact_phone_local_value] = phoneNumberInputParts(
    $_POST['contact_phone'] ?? '',
    $contact_phone_country_code_value
);

// Display success message if it exists in session
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']); // Clear the message after displaying
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Organizations'), array (
  'styles' =>
  array (
    'assets/css/style.min.css',
    'assets/css/modern.min.css',
    'assets/css/pages/record_workspace.min.css',
    'assets/css/pages/add_organization.min.css',
    'assets/css/pages/organization_form.min.css',
    'assets/css/pages/contact_form.min.css',
  ),
  'scripts' => ['assets/js/relationship-search.min.js', ['path' => 'assets/js/contact-photo.min.js', 'defer' => true]],
)); ?>
<body class="add-organization-body">
<?php include 'templates/header.php'; ?>
<div class="container add-organization-page" role="main">
    <?php if (isset($message)) echo "<p class='success'>$message</p>"; ?>
    <?php if (isset($error) && $error && !empty($errorMessages)) echo formErrorSummary($errorMessages, $errorFieldIds); ?>
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="organizations.php">Organizations</a><span aria-hidden="true">/</span><span>New Organization</span></nav>
    <div class="page-heading form-page-heading add-organization-heading"><div><h1>New Organization</h1><p class="page-intro">Start with a name; add contacts and address details as the relationship develops.</p></div></div>
    <noscript><form method="get" action="add_organization.php" class="card">
        <?php if ($creation_return !== ''): ?><input type="hidden" name="return_to" value="<?php echo htmlspecialchars($creation_return, ENT_QUOTES, 'UTF-8'); ?>"><?php endif; ?>
        <label for="existing-contact-search-fallback">Find an Existing Contact</label>
        <input id="existing-contact-search-fallback" name="contact_search" type="search" maxlength="128" value="<?php echo htmlspecialchars($contact_search, ENT_QUOTES, 'UTF-8'); ?>">
        <button type="submit" class="button-secondary">Find Contacts</button>
        <p>Search before filling the organization form. The results show up to 25 contacts.</p>
    </form></noscript>
    <p class="required-fields-note"><span aria-hidden="true">*</span> Required fields</p>
    <form method="post" enctype="multipart/form-data" action="add_organization.php" class="organization-form" data-duplicate-kind="organization">
<?php renderCreationDuplicateWarning($duplicateWarning ?? ['matches'=>[], 'token'=>''], 'organization', $creation_return); ?>

        <?php echo csrfInput(); echo creationTokenInput($creation_operation_token); ?>
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($creation_return, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="form-group">
            <label class="required" for="organization_name">Organization Name</label>
            <input type="text" id="organization_name" name="organization_name" required value="<?php echo htmlspecialchars($_POST['organization_name'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="7"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
        </div>

        <div class="organization-profile-row">
            <div class="form-group">
                <label for="affiliation">Affiliation</label>
                <input type="text" id="affiliation" name="affiliation" value="<?php echo htmlspecialchars($_POST['affiliation'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="distinctives">Distinctives</label>
                <input type="text" id="distinctives" name="distinctives" value="<?php echo htmlspecialchars($_POST['distinctives'] ?? ''); ?>">
            </div>

            <div class="form-group">
                <label for="website_url">Website URL</label>
                <input type="url" id="website_url" name="website_url" value="<?php echo htmlspecialchars($_POST['website_url'] ?? ''); ?>">
            </div>
        </div>

        <div class="organization-phone-row">
            <div class="form-group">
                <label for="phone">Phone</label>
                <div class="phone-input-group" data-phone-input-group>
                    <?php echo phoneCountryPicker('phone_country_code', $phone_country_code_value, 'Organization phone country code'); ?>
                    <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($phone_local_value, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(111) 111-1111" autocomplete="tel-national" inputmode="tel" data-phone-number>
                </div>
            </div>

            <div class="form-group">
                <label for="fax">Fax</label>
                <div class="phone-input-group" data-phone-input-group>
                    <?php echo phoneCountryPicker('fax_country_code', $fax_country_code_value, 'Organization fax country code'); ?>
                    <input type="tel" id="fax" name="fax" value="<?php echo htmlspecialchars($fax_local_value, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(111) 111-1111" inputmode="tel" data-phone-number>
                </div>
            </div>
            <fieldset class="radio-group">
                <legend>Mailing and Physical Address the Same</legend>
                <div>
                    <label><input type="radio" name="same_address" value="yes" <?php echo (!isset($_POST['same_address']) || $_POST['same_address'] === 'yes') ? 'checked' : ''; ?>> Yes</label>
                    <label><input type="radio" name="same_address" value="no" <?php echo (isset($_POST['same_address']) && $_POST['same_address'] === 'no') ? 'checked' : ''; ?>> No</label>
                </div>
            </fieldset>
        </div>

        <div class="organization-address-row">
            <div id="physical_address_section" class="address-section">
                <h3>Physical Address</h3><p>Add the known address details now or complete them later.</p>
                <div class="address-grid">
                    <div class="address-full-width">
                        <label for="physical_address_line_1">Address Line 1</label>
                        <input type="text" id="physical_address_line_1" name="physical_address_line_1" placeholder="Address Line 1" value="<?php echo htmlspecialchars($_POST['physical_address_line_1'] ?? ''); ?>">
                    </div>
                    <div class="address-full-width">
                        <label for="physical_address_line_2">Address Line 2</label>
                        <input type="text" id="physical_address_line_2" name="physical_address_line_2" placeholder="Address Line 2" value="<?php echo htmlspecialchars($_POST['physical_address_line_2'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="physical_city">City</label>
                        <input type="text" id="physical_city" name="physical_city" placeholder="City" value="<?php echo htmlspecialchars($_POST['physical_city'] ?? ''); ?>">
                    </div>
                    <div data-address-region-control data-address-region-for="physical" data-region-required="false">
                        <label for="physical_state">State / Province</label>
                        <input type="text" id="physical_state" name="physical_state" placeholder="State/Province" value="<?php echo htmlspecialchars($_POST['physical_state'] ?? ''); ?>" data-address-region-input>
                    </div>
                    <div>
                        <label for="physical_zipcode">Postal Code</label>
                        <input type="text" id="physical_zipcode" name="physical_zipcode" placeholder="Zip/Postal" value="<?php echo htmlspecialchars($_POST['physical_zipcode'] ?? ''); ?>">
                    </div>
                    <div class="address-full-width">
                        <?php echo addressCountryPicker(
                            'physical_country',
                            $_POST['physical_country'] ?? applicationDefaultCountry(),
                            'physical',
                            false
                        ); ?>
                    </div>
                </div>
            </div>

            <div id="mailing_address_section" data-address-optional class="address-section"<?php echo ($_POST['same_address'] ?? 'yes') === 'no' ? '' : ' hidden'; ?>>
                <h3>Mailing Address</h3>
                <div class="address-grid">
                    <div class="address-full-width">
                        <label for="mailing_address_line_1">Address Line 1</label>
                        <input type="text" id="mailing_address_line_1" name="mailing_address_line_1" placeholder="Address Line 1" value="<?php echo htmlspecialchars($_POST['mailing_address_line_1'] ?? ''); ?>">
                    </div>
                    <div class="address-full-width">
                        <label for="mailing_address_line_2">Address Line 2</label>
                        <input type="text" id="mailing_address_line_2" name="mailing_address_line_2" placeholder="Address Line 2" value="<?php echo htmlspecialchars($_POST['mailing_address_line_2'] ?? ''); ?>">
                    </div>
                    <div>
                        <label for="mailing_city">City</label>
                        <input type="text" id="mailing_city" name="mailing_city" placeholder="City" value="<?php echo htmlspecialchars($_POST['mailing_city'] ?? ''); ?>">
                    </div>
                    <div data-address-region-control data-address-region-for="mailing" data-region-required="false">
                        <label for="mailing_state">State / Province</label>
                        <input type="text" id="mailing_state" name="mailing_state" placeholder="State/Province" value="<?php echo htmlspecialchars($_POST['mailing_state'] ?? ''); ?>" data-address-region-input>
                    </div>
                    <div>
                        <label for="mailing_zipcode">Postal Code</label>
                        <input type="text" id="mailing_zipcode" name="mailing_zipcode" placeholder="Zip/Postal" value="<?php echo htmlspecialchars($_POST['mailing_zipcode'] ?? ''); ?>">
                    </div>
                    <div class="address-full-width">
                        <?php echo addressCountryPicker(
                            'mailing_country',
                            $_POST['mailing_country'] ?? applicationDefaultCountry(),
                            'mailing'
                        ); ?>
                    </div>
                </div>
            </div>
        </div>

        <details class="record-form-section" open><summary>Contacts</summary>
        <div class="address-section" data-existing-organization-contacts>
            <h3>Add Existing Contacts</h3>
            <p class="field-help">Choose a contact and describe their role with this organization. Their other organizations and roles stay in place.</p>
            <div data-existing-organization-contact-rows>
                <?php foreach ($existing_contact_rows as $index => $row): ?>
                    <div class="existing-organization-contact-row" data-existing-organization-contact-row>
                        <div class="form-group">
                            <label for="existing-contact-<?php echo $index; ?>">Existing Contact</label>
                            <select id="existing-contact-<?php echo $index; ?>" name="existing_contacts[<?php echo $index; ?>][contact_id]" data-existing-contact-select data-contact-search>
                                <option value="">Select an Existing Contact</option>
                                <?php if ($row['contact_id'] !== '' && !in_array((int) $row['contact_id'], $existing_contact_option_ids, true)): ?>
                                    <option value="<?php echo htmlspecialchars($row['contact_id'], ENT_QUOTES, 'UTF-8'); ?>" selected>Previously Selected Contact Is Unavailable</option>
                                <?php endif; ?>
                                <?php foreach ($existing_contact_options as $option): ?>
                                    <option value="<?php echo (int) $option['id']; ?>"<?php echo (string) $option['id'] === $row['contact_id'] ? ' selected' : ''; ?>><?php echo htmlspecialchars(
                                        trim($option['contact_first_name'] . ' ' . $option['contact_last_name'])
                                            . ($option['contact_email'] !== '' ? ' — ' . $option['contact_email'] : '')
                                            . (!empty($option['organization_name']) ? ' · ' . $option['organization_name'] : ''),
                                        ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="existing-contact-role-<?php echo $index; ?>">Role with This Organization</label>
                            <input type="text" id="existing-contact-role-<?php echo $index; ?>" name="existing_contacts[<?php echo $index; ?>][role_title]"
                                value="<?php echo htmlspecialchars($row['role_title'], ENT_QUOTES, 'UTF-8'); ?>" maxlength="255" placeholder="e.g., Board member" data-existing-contact-role>
                        </div>
                        <button type="button" class="button-secondary" data-remove-existing-organization-contact aria-label="Remove Existing Contact">Remove</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="button-secondary" data-add-existing-organization-contact>Add Another Existing Contact</button>
        </div>
        <div class="address-section">
            <h3>Create New Contacts</h3>
            <div id="contacts-container">
                <div class="contact-entry">
                    <div class="contact-fields contact-layout-form">
                        <div class="contact-form-overview">
                            <section class="contact-form-photo" aria-label="Contact Photo">
                                <div class="form-group contact-photo-field">
                                    <div class="contact-photo-upload">
                                        <div class="contact-photo-preview">
                                            <img src="data:image/svg+xml;base64,<?php echo base64_encode(contactInitialsSvg(['contact_first_name' => $_POST['contact_first_name'] ?? '', 'contact_last_name' => $_POST['contact_last_name'] ?? ''])); ?>" width="96" height="96" alt="Contact photo preview" data-contact-photo-preview>
                                        </div>
                                        <div>
                                            <label for="contact_photo">Contact Photo</label>
                                            <input type="file" id="contact_photo" name="contact_photo" accept="image/jpeg,image/png,image/webp" data-max-bytes="<?php echo CONTACT_PHOTO_MAX_BYTES; ?>" data-contact-photo-input>
                                            <p class="field-help">Optional. JPEG, PNG, or WebP; maximum 5 MB.</p>
                                        </div>
                                    </div>
                                    <div class="contact-photo-paste-controls">
                                        <button type="button" class="button-secondary" data-contact-photo-paste aria-controls="contact_photo-paste-box" aria-expanded="false">Paste Image</button>
                                        <div class="contact-photo-paste-box" id="contact_photo-paste-box" data-contact-photo-paste-box hidden>
                                            <label for="contact_photo-paste-target">Paste a Photo</label>
                                            <textarea id="contact_photo-paste-target" data-contact-photo-paste-target rows="2" placeholder="Command+V or Ctrl+V" aria-describedby="contact_photo-paste-help"></textarea>
                                            <p class="field-help" id="contact_photo-paste-help">Copy the image itself, then paste here. Create the organization to save the photo.</p>
                                        </div>
                                    </div>
                                    <div class="contact-photo-feedback">
                                        <p class="contact-photo-preview-status" hidden aria-live="polite" data-contact-photo-preview-status></p>
                                    </div>
                                </div>
                            </section>
                            <section class="contact-form-details" aria-label="Contact Details">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label id="first_name_label" for="contact_first_name">First Name</label>
                                        <input type="text" name="contact_first_name" id="contact_first_name" autocomplete="given-name" value="<?php echo htmlspecialchars($_POST['contact_first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                    <div class="form-group">
                                        <label id="last_name_label" for="contact_last_name">Last Name</label>
                                        <input type="text" name="contact_last_name" id="contact_last_name" autocomplete="family-name" value="<?php echo htmlspecialchars($_POST['contact_last_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                    </div>
                                </div>
                                <div class="form-group contact-form-email">
                                    <label id="email_label" for="contact_email">Email</label>
                                    <input type="email" name="contact_email" id="contact_email" value="<?php echo htmlspecialchars($_POST['contact_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                </div>
                                <div class="contact-phone-birthday-row">
                                    <div class="form-group contact-phone-field">
                                        <div class="contact-control-pair">
                                            <label for="contact_phone">Phone</label>
                                            <div class="phone-input-group" data-phone-input-group>
                                                <?php echo phoneCountryPicker('contact_phone_country_code', $contact_phone_country_code_value, 'Contact phone country code'); ?>
                                                <input type="tel" name="contact_phone" id="contact_phone" value="<?php echo htmlspecialchars($contact_phone_local_value, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(111) 111-1111" autocomplete="tel-national" inputmode="tel" data-phone-number>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group contact-birthday-field">
                                        <div class="contact-control-pair">
                                            <label for="contact_birthday">Birthday</label>
                                            <input type="text" name="contact_birthday" id="contact_birthday" value="<?php echo htmlspecialchars($_POST['contact_birthday'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="MM/DD" inputmode="numeric" autocomplete="bday" maxlength="5" pattern="[0-9]{2}/[0-9]{2}">
                                        </div>
                                        <p class="field-help">Optional; repeats annually.</p>
                                    </div>
                                </div>
                            </section>
                            <section class="contact-form-organization" aria-label="Contact Role">
                                <div class="form-row">
                                    <div class="form-group">
                                        <div class="contact-control-pair">
                                            <label id="role_label" for="contact_role">Role</label>
                                            <select name="contact_role" id="contact_role" class="narrow-select" data-contact-role-id="">
                                            <option value="">Select Role</option>
                                            <?php foreach (\Dnr\Domain\ReferenceData::contactRoles() as $role): ?>
                                            <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($_POST['contact_role'] ?? '') === $role ? 'selected' : ''; ?>><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($role), ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group" id="other_role_group" <?php echo ($_POST['contact_role'] ?? '') === 'other' ? '' : 'hidden'; ?>
                                        >
                                        <div class="contact-control-pair">
                                            <label for="contact_role_other">Describe Other Role</label>
                                            <input type="text" name="contact_role_other" id="contact_role_other" value="<?php echo htmlspecialchars($_POST['contact_role_other'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>
                        <div class="form-group">
                            <label for="contact_notes">Notes</label>
                            <textarea name="contact_notes" id="contact_notes" rows="6" placeholder="Add incidental notes about this person."><?php echo htmlspecialchars($_POST['contact_notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <template id="contact-entry-template">
                <div class="contact-entry" id="contact-__CONTACT_ID__">
                    <div class="contact-fields contact-layout-form">
                        <div class="contact-form-overview">
                            <section class="contact-form-photo" aria-label="Contact Photo">
                                <div class="form-group contact-photo-field">
                                    <div class="contact-photo-upload">
                                        <div class="contact-photo-preview">
                                            <img src="data:image/svg+xml;base64,<?php echo base64_encode(contactInitialsSvg([])); ?>" width="96" height="96" alt="Contact photo preview" data-contact-photo-preview>
                                        </div>
                                        <div>
                                            <label for="additional-__CONTACT_INDEX__-photo">Contact Photo</label>
                                            <input type="file" id="additional-__CONTACT_INDEX__-photo" name="contacts[__CONTACT_INDEX__][photo]" accept="image/jpeg,image/png,image/webp" data-max-bytes="<?php echo CONTACT_PHOTO_MAX_BYTES; ?>" data-contact-photo-input>
                                            <p class="field-help">Optional. JPEG, PNG, or WebP; maximum 5 MB.</p>
                                        </div>
                                    </div>
                                    <div class="contact-photo-paste-controls">
                                        <button type="button" class="button-secondary" data-contact-photo-paste aria-controls="additional-__CONTACT_INDEX__-photo-paste-box" aria-expanded="false">Paste Image</button>
                                        <div class="contact-photo-paste-box" id="additional-__CONTACT_INDEX__-photo-paste-box" data-contact-photo-paste-box hidden>
                                            <label for="additional-__CONTACT_INDEX__-photo-paste-target">Paste a Photo</label>
                                            <textarea id="additional-__CONTACT_INDEX__-photo-paste-target" data-contact-photo-paste-target rows="2" placeholder="Command+V or Ctrl+V" aria-describedby="additional-__CONTACT_INDEX__-photo-paste-help"></textarea>
                                            <p class="field-help" id="additional-__CONTACT_INDEX__-photo-paste-help">Copy the image itself, then paste here. Create the organization to save the photo.</p>
                                        </div>
                                    </div>
                                    <div class="contact-photo-feedback">
                                        <p class="contact-photo-preview-status" hidden aria-live="polite" data-contact-photo-preview-status></p>
                                    </div>
                                </div>
                            </section>
                            <section class="contact-form-details" aria-label="Contact Details">
                                <div class="form-row">
                                    <div class="form-group">
                                        <label class="required" for="additional-__CONTACT_INDEX__-first_name">First Name</label>
                                        <input type="text" id="additional-__CONTACT_INDEX__-first_name" name="contacts[__CONTACT_INDEX__][first_name]" autocomplete="given-name" required>
                                    </div>
                                    <div class="form-group">
                                        <label class="required" for="additional-__CONTACT_INDEX__-last_name">Last Name</label>
                                        <input type="text" id="additional-__CONTACT_INDEX__-last_name" name="contacts[__CONTACT_INDEX__][last_name]" autocomplete="family-name" required>
                                    </div>
                                </div>
                                <div class="form-group contact-form-email">
                                    <label class="required" for="additional-__CONTACT_INDEX__-email">Email</label>
                                    <input type="email" id="additional-__CONTACT_INDEX__-email" name="contacts[__CONTACT_INDEX__][email]" required>
                                </div>
                                <div class="contact-phone-birthday-row">
                                    <div class="form-group contact-phone-field">
                                        <div class="contact-control-pair">
                                            <label for="additional-__CONTACT_INDEX__-phone">Phone</label>
                                            <div class="phone-input-group" data-phone-input-group>
                                                <?php echo phoneCountryPicker('contacts[__CONTACT_INDEX__][phone_country_code]', applicationDefaultPhoneCountryCode(), 'Contact phone country code'); ?>
                                                <input type="tel" id="additional-__CONTACT_INDEX__-phone" name="contacts[__CONTACT_INDEX__][phone]" placeholder="(111) 111-1111" autocomplete="tel-national" inputmode="tel" data-phone-number>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="form-group contact-birthday-field">
                                        <div class="contact-control-pair">
                                            <label for="additional-__CONTACT_INDEX__-birthday">Birthday</label>
                                            <input type="text" id="additional-__CONTACT_INDEX__-birthday" name="contacts[__CONTACT_INDEX__][birthday]" placeholder="MM/DD" inputmode="numeric" autocomplete="bday" maxlength="5" pattern="[0-9]{2}/[0-9]{2}">
                                        </div>
                                        <p class="field-help">Optional; repeats annually.</p>
                                    </div>
                                </div>
                            </section>
                            <section class="contact-form-organization" aria-label="Contact Role">
                                <div class="form-row">
                                    <div class="form-group">
                                        <div class="contact-control-pair">
                                            <label class="required" for="additional-__CONTACT_INDEX__-role">Role</label>
                                            <select id="additional-__CONTACT_INDEX__-role" name="contacts[__CONTACT_INDEX__][role]" class="narrow-select" required data-contact-role-id="__CONTACT_ID__">
                                            <option value="">Select Role</option>
                                            <?php foreach (\Dnr\Domain\ReferenceData::contactRoles() as $role): ?>
                                            <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($role), ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="form-group" hidden data-additional-other-role>
                                        <div class="contact-control-pair">
                                            <label class="required" for="additional-__CONTACT_INDEX__-role_other">Describe Other Role</label>
                                            <input type="text" id="additional-__CONTACT_INDEX__-role_other" name="contacts[__CONTACT_INDEX__][role_other]">
                                        </div>
                                    </div>
                                </div>
                            </section>
                        </div>
                        <div class="form-group">
                            <label for="additional-__CONTACT_INDEX__-notes">Notes</label>
                            <textarea id="additional-__CONTACT_INDEX__-notes" name="contacts[__CONTACT_INDEX__][notes]" rows="6" placeholder="Add incidental notes about this person."></textarea>
                        </div>
                    </div>
                    <button type="button" data-remove-contact class="remove-contact-btn">Remove</button>
                </div>
            </template>
            <button type="button" data-add-contact class="add-contact-btn">Add Another Contact</button>
        </div>

        </details>
        <div class="form-group create-form-actions create-form-actions-end">
            <a href="<?php echo htmlspecialchars($creation_return ?: 'organizations.php', ENT_QUOTES, 'UTF-8'); ?>" class="cancel-button">Cancel</a>
            <input type="submit" name="save_org" value="Create Organization" class="save-button">
        </div>
    </form>
</div>

<?php if (!empty($error) && is_array($_POST['contacts'] ?? null)): ?>
<script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>" type="application/json" id="submitted-additional-contacts"><?php echo json_encode(
    array_values($_POST['contacts']),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
); ?></script>
<?php endif; ?>

<script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>" type="application/json" id="address-region-data"><?php echo json_encode(
    addressRegionClientData(),
    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE
); ?></script>

<?php renderScript('assets/js/record-workspace.min.js'); ?>
<?php include 'templates/footer.php'; ?>
</body>
</html>
