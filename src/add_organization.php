<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/record_workspace_helpers.php';
startSecureSession();
$creation_return = safeRecordReturnUrl($_POST['return_to'] ?? $_GET['return_to'] ?? null, '');

requireLogin();
if (!hasRole(['admin', 'editor'])) {
    header("Location: organizations.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_org'])) {
    requireValidCsrfToken();

    $error = false;
    $errorMessages = array();

    $normalized_organization = \Dnr\Domain\OrganizationInput::normalize($_POST);
    foreach ($normalized_organization['data'] as $field_name => $field_value) {
        ${$field_name} = $field_value;
    }
    $errorMessages = $normalized_organization['errors'];

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
        'phone_country_code' => $contact_phone_country_code
    ]];
    if (isset($_POST['contacts']) && is_array($_POST['contacts'])) {
        foreach ($_POST['contacts'] as $submitted_contact) {
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
                'phone_country_code' => trim($submitted_contact['phone_country_code'] ?? applicationDefaultPhoneCountryCode())
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
        if (!$has_contact_data) {
            continue;
        }
        $normalized_contact = \Dnr\Domain\ContactInput::normalizeEmbedded($candidate);
        foreach ($normalized_contact['errors'] as $contact_error) {
            $errorMessages[] = "Contact {$contact_number}: {$contact_error}";
        }
        $contacts_to_create[] = $normalized_contact['data'];
    }

    $error = !empty($errorMessages);
    if (!$error) {
        $check_stmt = $conn->prepare("SELECT id FROM organizations WHERE organization_name = ?");
        $check_stmt->bind_param("s", $organization_name);
        $check_stmt->execute();

        if ($check_stmt->get_result()->num_rows > 0) {
            $error = true;
            $errorMessages[] = "An organization with this name already exists.";
        } else {
            $conn->begin_transaction();
            try {
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
                            contact_role_other, contact_email, contact_phone, contact_birthday, contact_notes
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $saved_contact_first_name = '';
                    $saved_contact_last_name = '';
                    $saved_contact_role = '';
                    $saved_contact_role_other = '';
                    $saved_contact_email = '';
                    $saved_contact_phone = '';
                    $saved_contact_birthday = null;
                    $saved_contact_notes = '';
                    $contact_stmt->bind_param(
                        "issssssss",
                        $organization_id,
                        $saved_contact_first_name,
                        $saved_contact_last_name,
                        $saved_contact_role,
                        $saved_contact_role_other,
                        $saved_contact_email,
                        $saved_contact_phone,
                        $saved_contact_birthday,
                        $saved_contact_notes
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
                        if (!$contact_stmt->execute()) {
                            throw new RuntimeException("Unable to save contact.");
                        }
                    }
                }

                $conn->commit();
                $_SESSION['success_message'] = !empty($contacts_to_create)
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
                $errorMessages[] = "Unable to save the organization.";
            }
        }
    }
}

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
  ),
)); ?>
<body class="add-organization-body">
<?php include 'templates/header.php'; ?>
<div class="container add-organization-page" role="main">
    <?php if (isset($message)) echo "<p class='success'>$message</p>"; ?>
    <?php if (isset($error) && $error && !empty($errorMessages)) echo formErrorSummary($errorMessages); ?>
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="organizations.php">Organizations</a><span aria-hidden="true">/</span><span>New Organization</span></nav>
    <div class="page-heading form-page-heading add-organization-heading"><div><h1>New Organization</h1><p class="page-intro">Start with a name; add contacts and address details as the relationship develops.</p></div></div>
    <p class="required-fields-note"><span aria-hidden="true">*</span> Required fields</p>
    <form method="post" action="add_organization.php" class="organization-form">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($creation_return, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="form-group">
            <label class="required" for="organization_name">Organization Name</label>
            <input type="text" id="organization_name" name="organization_name" required value="<?php echo htmlspecialchars($_POST['organization_name'] ?? ''); ?>">
        </div>

        <details class="record-form-section"<?php echo !empty($errorMessages) ? ' open' : ''; ?>><summary>Organization details</summary>
        <div class="form-group">
            <label for="notes">Notes</label>
            <textarea id="notes" name="notes" rows="6"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
        </div>

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

        <div class="contact-grid">
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
        </div>

        </details>
        <details class="record-form-section"<?php echo !empty($errorMessages) ? ' open' : ''; ?>><summary>Addresses</summary>
        <fieldset class="radio-group">
            <legend>Mailing and Physical Address the Same</legend>
            <div>
                <label><input type="radio" name="same_address" value="yes" <?php echo (!isset($_POST['same_address']) || $_POST['same_address'] === 'yes') ? 'checked' : ''; ?>> Yes</label>
                <label><input type="radio" name="same_address" value="no" <?php echo (isset($_POST['same_address']) && $_POST['same_address'] === 'no') ? 'checked' : ''; ?>> No</label>
            </div>
        </fieldset>

        <div id="physical_address_section" class="address-section">
            <h3>Physical Address</h3><p>Add the known address details now or complete them later.</p>
            <div class="address-grid">
                <div class="address-full-width">
                    <label for="physical_address_line_1">Address line 1</label>
                    <input type="text" id="physical_address_line_1" name="physical_address_line_1" placeholder="Address Line 1" value="<?php echo htmlspecialchars($_POST['physical_address_line_1'] ?? ''); ?>">
                </div>
                <div class="address-full-width">
                    <label for="physical_address_line_2">Address line 2</label>
                    <input type="text" id="physical_address_line_2" name="physical_address_line_2" placeholder="Address Line 2" value="<?php echo htmlspecialchars($_POST['physical_address_line_2'] ?? ''); ?>">
                </div>
                <div>
                    <label for="physical_city">City</label>
                    <input type="text" id="physical_city" name="physical_city" placeholder="City" value="<?php echo htmlspecialchars($_POST['physical_city'] ?? ''); ?>">
                </div>
                <div data-address-region-control data-address-region-for="physical" data-region-required="false">
                    <label for="physical_state">State / province</label>
                    <input type="text" id="physical_state" name="physical_state" placeholder="State/Province" value="<?php echo htmlspecialchars($_POST['physical_state'] ?? ''); ?>" data-address-region-input>
                </div>
                <div>
                    <label for="physical_zipcode">Postal code</label>
                    <input type="text" id="physical_zipcode" name="physical_zipcode" placeholder="Zip/Postal" value="<?php echo htmlspecialchars($_POST['physical_zipcode'] ?? ''); ?>">
                </div>
                <div>
                    <?php echo addressCountryPicker(
                        'physical_country',
                        $_POST['physical_country'] ?? applicationDefaultCountry(),
                        'physical',
                        false
                    ); ?>
                </div>
            </div>
        </div>

        <div id="mailing_address_section" data-address-optional class="address-section">
            <h3>Mailing Address</h3>
            <div class="address-grid">
                <div class="address-full-width">
                    <label for="mailing_address_line_1">Address line 1</label>
                    <input type="text" id="mailing_address_line_1" name="mailing_address_line_1" placeholder="Address Line 1" value="<?php echo htmlspecialchars($_POST['mailing_address_line_1'] ?? ''); ?>">
                </div>
                <div class="address-full-width">
                    <label for="mailing_address_line_2">Address line 2</label>
                    <input type="text" id="mailing_address_line_2" name="mailing_address_line_2" placeholder="Address Line 2" value="<?php echo htmlspecialchars($_POST['mailing_address_line_2'] ?? ''); ?>">
                </div>
                <div>
                    <label for="mailing_city">City</label>
                    <input type="text" id="mailing_city" name="mailing_city" placeholder="City" value="<?php echo htmlspecialchars($_POST['mailing_city'] ?? ''); ?>">
                </div>
                <div data-address-region-control data-address-region-for="mailing" data-region-required="false">
                    <label for="mailing_state">State / province</label>
                    <input type="text" id="mailing_state" name="mailing_state" placeholder="State/Province" value="<?php echo htmlspecialchars($_POST['mailing_state'] ?? ''); ?>" data-address-region-input>
                </div>
                <div>
                    <label for="mailing_zipcode">Postal code</label>
                    <input type="text" id="mailing_zipcode" name="mailing_zipcode" placeholder="Zip/Postal" value="<?php echo htmlspecialchars($_POST['mailing_zipcode'] ?? ''); ?>">
                </div>
                <div>
                    <?php echo addressCountryPicker(
                        'mailing_country',
                        $_POST['mailing_country'] ?? applicationDefaultCountry(),
                        'mailing'
                    ); ?>
                </div>
            </div>
        </div>

        </details>
        <details class="record-form-section"<?php echo !empty($errorMessages) ? ' open' : ''; ?>><summary>Contacts</summary>
        <div class="address-section">
            <div id="contacts-container">
                <div class="contact-entry">
                    <div class="contact-fields">
                        <div class="name-phone-row">
                            <div class="form-group">
                                <label id="first_name_label" for="contact_first_name">First Name</label>
                                <input type="text" name="contact_first_name" id="contact_first_name" autocomplete="given-name" value="<?php echo htmlspecialchars($_POST['contact_first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div class="form-group">
                                <label id="last_name_label" for="contact_last_name">Last Name</label>
                                <input type="text" name="contact_last_name" id="contact_last_name" autocomplete="family-name" value="<?php echo htmlspecialchars($_POST['contact_last_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div class="form-group contact-phone-field">
                                <label for="contact_phone">Phone</label>
                                <div class="phone-input-group" data-phone-input-group>
                                    <?php echo phoneCountryPicker('contact_phone_country_code', $contact_phone_country_code_value, 'Contact phone country code'); ?>
                                    <input type="tel" name="contact_phone" id="contact_phone" value="<?php echo htmlspecialchars($contact_phone_local_value, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(111) 111-1111" autocomplete="tel-national" inputmode="tel" data-phone-number>
                                </div>
                            </div>
                            <div class="form-group contact-birthday-field">
                                <label for="contact_birthday">Birthday</label>
                                <input type="text" name="contact_birthday" id="contact_birthday" value="<?php echo htmlspecialchars($_POST['contact_birthday'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" placeholder="MM/DD" inputmode="numeric" autocomplete="bday" maxlength="5" pattern="[0-9]{2}/[0-9]{2}">
                                <p class="field-help">Optional; repeats annually.</p>
                            </div>
                        </div>

                        <div class="role-container">
                            <div class="form-group">
                                <label id="role_label" for="contact_role">Role</label>
                                <select name="contact_role" id="contact_role" class="narrow-select" data-contact-role-id="">
                                    <option value="">Select Role</option>
                                    <?php foreach (\Dnr\Domain\ReferenceData::contactRoles() as $role): ?>
                                        <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($_POST['contact_role'] ?? '') === $role ? 'selected' : ''; ?>><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($role), ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group" id="other_role_group" <?php echo ($_POST['contact_role'] ?? '') === 'other' ? '' : 'hidden'; ?>>
                                <label for="contact_role_other">Describe Other Role</label>
                                <input type="text" name="contact_role_other" id="contact_role_other" value="<?php echo htmlspecialchars($_POST['contact_role_other'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>

                        <div class="email-container">
                            <div class="form-group">
                                <label id="email_label" for="contact_email">Email</label>
                                <input type="email" name="contact_email" id="contact_email" value="<?php echo htmlspecialchars($_POST['contact_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
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
                    <div class="contact-fields">
                        <div class="name-phone-row">
                            <div class="form-group">
                                <label class="required" for="additional-__CONTACT_INDEX__-first_name">First Name</label>
                                <input type="text" id="additional-__CONTACT_INDEX__-first_name" name="contacts[__CONTACT_INDEX__][first_name]" autocomplete="given-name" required>
                            </div>
                            <div class="form-group">
                                <label class="required" for="additional-__CONTACT_INDEX__-last_name">Last Name</label>
                                <input type="text" id="additional-__CONTACT_INDEX__-last_name" name="contacts[__CONTACT_INDEX__][last_name]" autocomplete="family-name" required>
                            </div>
                            <div class="form-group contact-phone-field">
                                <label for="additional-__CONTACT_INDEX__-phone">Phone</label>
                                <div class="phone-input-group" data-phone-input-group>
                                    <?php echo phoneCountryPicker('contacts[__CONTACT_INDEX__][phone_country_code]', applicationDefaultPhoneCountryCode(), 'Contact phone country code'); ?>
                                    <input type="tel" id="additional-__CONTACT_INDEX__-phone" name="contacts[__CONTACT_INDEX__][phone]" placeholder="(111) 111-1111" autocomplete="tel-national" inputmode="tel" data-phone-number>
                                </div>
                            </div>
                            <div class="form-group contact-birthday-field">
                                <label for="additional-__CONTACT_INDEX__-birthday">Birthday</label>
                                <input type="text" id="additional-__CONTACT_INDEX__-birthday" name="contacts[__CONTACT_INDEX__][birthday]" placeholder="MM/DD" inputmode="numeric" autocomplete="bday" maxlength="5" pattern="[0-9]{2}/[0-9]{2}">
                                <p class="field-help">Optional; repeats annually.</p>
                            </div>
                        </div>
                        <div class="role-container">
                            <div class="form-group">
                                <label class="required" for="additional-__CONTACT_INDEX__-role">Role</label>
                                <select id="additional-__CONTACT_INDEX__-role" name="contacts[__CONTACT_INDEX__][role]" class="narrow-select" required data-contact-role-id="__CONTACT_ID__">
                                    <option value="">Select Role</option>
                                    <?php foreach (\Dnr\Domain\ReferenceData::contactRoles() as $role): ?>
                                        <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($role), ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" hidden data-additional-other-role>
                                <label class="required" for="additional-__CONTACT_INDEX__-role_other">Describe Other Role</label>
                                <input type="text" id="additional-__CONTACT_INDEX__-role_other" name="contacts[__CONTACT_INDEX__][role_other]">
                            </div>
                        </div>
                        <div class="email-container">
                            <div class="form-group">
                                <label class="required" for="additional-__CONTACT_INDEX__-email">Email</label>
                                <input type="email" id="additional-__CONTACT_INDEX__-email" name="contacts[__CONTACT_INDEX__][email]" required>
                            </div>
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
            <input type="submit" name="save_org" value="Create organization" class="save-button">
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
