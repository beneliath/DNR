<?php
require_once __DIR__ . '/bootstrap.php';
startSecureSession();

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
    $contact_email_confirm = trim($_POST['contact_email_confirm'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');
    $contact_notes = trim($_POST['contact_notes'] ?? '');
    $contact_phone_country_code = trim($_POST['contact_phone_country_code'] ?? '+1');

    $contact_candidates = [[
        'first_name' => $contact_first_name,
        'last_name' => $contact_last_name,
        'role' => $contact_role,
        'role_other' => $contact_role_other,
        'email' => $contact_email,
        'email_confirm' => $contact_email_confirm,
        'phone' => $contact_phone,
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
                'email_confirm' => trim($submitted_contact['email_confirm'] ?? ''),
                'phone' => trim($submitted_contact['phone'] ?? ''),
                'notes' => trim($submitted_contact['notes'] ?? ''),
                'phone_country_code' => trim($submitted_contact['phone_country_code'] ?? '+1')
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
                            contact_role_other, contact_email, contact_phone, contact_notes
                         ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    $saved_contact_first_name = '';
                    $saved_contact_last_name = '';
                    $saved_contact_role = '';
                    $saved_contact_role_other = '';
                    $saved_contact_email = '';
                    $saved_contact_phone = '';
                    $saved_contact_notes = '';
                    $contact_stmt->bind_param(
                        "isssssss",
                        $organization_id,
                        $saved_contact_first_name,
                        $saved_contact_last_name,
                        $saved_contact_role,
                        $saved_contact_role_other,
                        $saved_contact_email,
                        $saved_contact_phone,
                        $saved_contact_notes
                    );

                    foreach ($contacts_to_create as $contact_to_create) {
                        $saved_contact_first_name = $contact_to_create['first_name'];
                        $saved_contact_last_name = $contact_to_create['last_name'];
                        $saved_contact_role = $contact_to_create['role'];
                        $saved_contact_role_other = $contact_to_create['role_other'];
                        $saved_contact_email = $contact_to_create['email'];
                        $saved_contact_phone = $contact_to_create['phone'];
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
                header('Location: add_organization.php');
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

$phone_country_code_value = trim($_POST['phone_country_code'] ?? '+1');
[, $phone_local_value] = phoneNumberInputParts($_POST['phone'] ?? '', $phone_country_code_value);
$fax_country_code_value = trim($_POST['fax_country_code'] ?? '+1');
[, $fax_local_value] = phoneNumberInputParts($_POST['fax'] ?? '', $fax_country_code_value);
$contact_phone_country_code_value = trim($_POST['contact_phone_country_code'] ?? '+1');
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
<?php renderPageHead('Organizations - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/add_organization.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <?php if (isset($message)) echo "<p class='success'>$message</p>"; ?>
    <?php if (isset($error) && $error && !empty($errorMessages)) echo "<p class='error'>" . implode("<br>", array_map('htmlspecialchars', $errorMessages)) . "</p>"; ?>
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="organizations.php">Organizations</a><span aria-hidden="true">/</span><span>New Organization</span></nav>
    <div class="page-heading form-page-heading"><div><h1>New Organization</h1><p class="page-intro">Add organization details, addresses, and contacts.</p></div></div>
    <form method="post" action="add_organization.php" class="organization-form">
        <?php echo csrfInput(); ?>
        <p class="required-fields-note"><span aria-hidden="true">*</span> Required fields</p>
        <div class="form-group">
            <label class="required">Organization Name</label>
            <input type="text" name="organization_name" required value="<?php echo htmlspecialchars($_POST['organization_name'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" rows="4"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
        </div>

        <div class="form-group">
            <label>Affiliation</label>
            <input type="text" name="affiliation" value="<?php echo htmlspecialchars($_POST['affiliation'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label>Distinctives</label>
            <input type="text" name="distinctives" value="<?php echo htmlspecialchars($_POST['distinctives'] ?? ''); ?>">
        </div>

        <div class="form-group">
            <label>Website URL</label>
            <input type="url" name="website_url" value="<?php echo htmlspecialchars($_POST['website_url'] ?? ''); ?>">
        </div>

        <div class="contact-grid">
            <div class="form-group">
                <label>Phone</label>
                <div class="phone-input-group" data-phone-input-group>
                    <?php echo phoneCountryPicker('phone_country_code', $phone_country_code_value, 'Organization phone country code'); ?>
                    <input type="tel" name="phone" value="<?php echo htmlspecialchars($phone_local_value, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(111) 111-1111" autocomplete="tel-national" inputmode="tel" data-phone-number>
                </div>
            </div>

            <div class="form-group">
                <label>Fax</label>
                <div class="phone-input-group" data-phone-input-group>
                    <?php echo phoneCountryPicker('fax_country_code', $fax_country_code_value, 'Organization fax country code'); ?>
                    <input type="tel" name="fax" value="<?php echo htmlspecialchars($fax_local_value, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(111) 111-1111" inputmode="tel" data-phone-number>
                </div>
            </div>
        </div>

        <div class="radio-group">
            <label class="required">Mailing and Physical Address the Same</label>
            <div>
                <label><input type="radio" name="same_address" value="yes" <?php echo (!isset($_POST['same_address']) || $_POST['same_address'] === 'yes') ? 'checked' : ''; ?>> Yes</label>
                <label><input type="radio" name="same_address" value="no" <?php echo (isset($_POST['same_address']) && $_POST['same_address'] === 'no') ? 'checked' : ''; ?>> No</label>
            </div>
        </div>

        <div id="physical_address_section" class="address-section">
            <h3 class="required">Physical Address</h3>
            <div class="address-grid">
                <div class="address-full-width">
                    <input type="text" name="physical_address_line_1" placeholder="Address Line 1" required value="<?php echo htmlspecialchars($_POST['physical_address_line_1'] ?? ''); ?>">
                </div>
                <div class="address-full-width">
                    <input type="text" name="physical_address_line_2" placeholder="Address Line 2" value="<?php echo htmlspecialchars($_POST['physical_address_line_2'] ?? ''); ?>">
                </div>
                <div>
                    <input type="text" name="physical_city" placeholder="City" required value="<?php echo htmlspecialchars($_POST['physical_city'] ?? ''); ?>">
                </div>
                <div>
                    <input type="text" name="physical_state" placeholder="State/Province" required value="<?php echo htmlspecialchars($_POST['physical_state'] ?? ''); ?>">
                </div>
                <div>
                    <input type="text" name="physical_zipcode" placeholder="Zip/Postal" required value="<?php echo htmlspecialchars($_POST['physical_zipcode'] ?? ''); ?>">
                </div>
                <div class="address-full-width">
                    <select name="physical_country" required>
                        <option value="">Select Country</option>
                        <option value="USA" <?php echo (isset($_POST['physical_country']) && $_POST['physical_country'] === 'USA') ? 'selected' : ''; ?>>United States</option>
                        <option value="CAN" <?php echo (isset($_POST['physical_country']) && $_POST['physical_country'] === 'CAN') ? 'selected' : ''; ?>>Canada</option>
                    </select>
                </div>
            </div>
        </div>

        <div id="mailing_address_section" class="address-section">
            <h3 class="required">Mailing Address</h3>
            <div class="address-grid">
                <div class="address-full-width">
                    <input type="text" name="mailing_address_line_1" placeholder="Address Line 1" value="<?php echo htmlspecialchars($_POST['mailing_address_line_1'] ?? ''); ?>">
                </div>
                <div class="address-full-width">
                    <input type="text" name="mailing_address_line_2" placeholder="Address Line 2" value="<?php echo htmlspecialchars($_POST['mailing_address_line_2'] ?? ''); ?>">
                </div>
                <div>
                    <input type="text" name="mailing_city" placeholder="City" value="<?php echo htmlspecialchars($_POST['mailing_city'] ?? ''); ?>">
                </div>
                <div>
                    <input type="text" name="mailing_state" placeholder="State/Province" value="<?php echo htmlspecialchars($_POST['mailing_state'] ?? ''); ?>">
                </div>
                <div>
                    <input type="text" name="mailing_zipcode" placeholder="Zip/Postal" value="<?php echo htmlspecialchars($_POST['mailing_zipcode'] ?? ''); ?>">
                </div>
                <div class="address-full-width">
                    <select name="mailing_country">
                        <option value="">Select Country</option>
                        <option value="USA" <?php echo (isset($_POST['mailing_country']) && $_POST['mailing_country'] === 'USA') ? 'selected' : ''; ?>>United States</option>
                        <option value="CAN" <?php echo (isset($_POST['mailing_country']) && $_POST['mailing_country'] === 'CAN') ? 'selected' : ''; ?>>Canada</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="section-heading">Contact(s)</div>
        <div class="address-section">
            <div id="contacts-container">
                <div class="contact-entry">
                    <div class="contact-fields">
                        <div class="name-phone-row">
                            <div class="form-group">
                                <label id="first_name_label">First Name</label>
                                <input type="text" name="contact_first_name" id="contact_first_name" autocomplete="given-name" value="<?php echo htmlspecialchars($_POST['contact_first_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div class="form-group">
                                <label id="last_name_label">Last Name</label>
                                <input type="text" name="contact_last_name" id="contact_last_name" autocomplete="family-name" value="<?php echo htmlspecialchars($_POST['contact_last_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div class="form-group">
                                <label>Phone</label>
                                <div class="phone-input-group" data-phone-input-group>
                                    <?php echo phoneCountryPicker('contact_phone_country_code', $contact_phone_country_code_value, 'Contact phone country code'); ?>
                                    <input type="tel" name="contact_phone" id="contact_phone" value="<?php echo htmlspecialchars($contact_phone_local_value, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(111) 111-1111" autocomplete="tel-national" inputmode="tel" data-phone-number>
                                </div>
                            </div>
                        </div>

                        <div class="role-container">
                            <div class="form-group">
                                <label id="role_label">Role</label>
                                <select name="contact_role" id="contact_role" class="narrow-select" data-contact-role-id="">
                                    <option value="">Select Role</option>
                                    <?php foreach (\Dnr\Domain\ReferenceData::contactRoles() as $role): ?>
                                        <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($_POST['contact_role'] ?? '') === $role ? 'selected' : ''; ?>><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($role), ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group" id="other_role_group" <?php echo ($_POST['contact_role'] ?? '') === 'other' ? '' : 'hidden'; ?>>
                                <label>Describe Other Role</label>
                                <input type="text" name="contact_role_other" id="contact_role_other" value="<?php echo htmlspecialchars($_POST['contact_role_other'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>

                        <div class="email-container">
                            <div class="form-group">
                                <label id="email_label">Email</label>
                                <input type="email" name="contact_email" id="contact_email" value="<?php echo htmlspecialchars($_POST['contact_email'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>

                            <div class="form-group">
                                <label id="email_confirm_label">Confirm Email</label>
                                <input type="email" name="contact_email_confirm" id="contact_email_confirm" value="<?php echo htmlspecialchars($_POST['contact_email_confirm'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="contact_notes">Notes</label>
                            <textarea name="contact_notes" id="contact_notes" rows="4" placeholder="Add incidental notes about this person."><?php echo htmlspecialchars($_POST['contact_notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
            <template id="contact-entry-template">
                <div class="contact-entry" id="contact-__CONTACT_ID__">
                    <div class="contact-fields">
                        <div class="name-phone-row">
                            <div class="form-group">
                                <label class="required">First Name</label>
                                <input type="text" name="contacts[__CONTACT_INDEX__][first_name]" autocomplete="given-name" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Last Name</label>
                                <input type="text" name="contacts[__CONTACT_INDEX__][last_name]" autocomplete="family-name" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Phone</label>
                                <div class="phone-input-group" data-phone-input-group>
                                    <?php echo phoneCountryPicker('contacts[__CONTACT_INDEX__][phone_country_code]', '+1', 'Contact phone country code'); ?>
                                    <input type="tel" name="contacts[__CONTACT_INDEX__][phone]" placeholder="(111) 111-1111" autocomplete="tel-national" inputmode="tel" data-phone-number required>
                                </div>
                            </div>
                        </div>
                        <div class="role-container">
                            <div class="form-group">
                                <label class="required">Role</label>
                                <select name="contacts[__CONTACT_INDEX__][role]" class="narrow-select" required data-contact-role-id="__CONTACT_ID__">
                                    <option value="">Select Role</option>
                                    <?php foreach (\Dnr\Domain\ReferenceData::contactRoles() as $role): ?>
                                        <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($role), ENT_QUOTES, 'UTF-8'); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="form-group" hidden data-additional-other-role>
                                <label class="required">Describe Other Role</label>
                                <input type="text" name="contacts[__CONTACT_INDEX__][role_other]">
                            </div>
                        </div>
                        <div class="email-container">
                            <div class="form-group">
                                <label class="required">Email</label>
                                <input type="email" name="contacts[__CONTACT_INDEX__][email]" required>
                            </div>
                            <div class="form-group">
                                <label class="required">Confirm Email</label>
                                <input type="email" name="contacts[__CONTACT_INDEX__][email_confirm]" required>
                            </div>
                        </div>
                        <div class="form-group">
                            <label>Notes</label>
                            <textarea name="contacts[__CONTACT_INDEX__][notes]" rows="4" placeholder="Add incidental notes about this person."></textarea>
                        </div>
                    </div>
                    <button type="button" data-remove-contact class="remove-contact-btn">Remove</button>
                </div>
            </template>
            <button type="button" data-add-contact class="add-contact-btn">Add Another Contact</button>
        </div>

        <div class="form-group create-form-actions create-form-actions-end">
            <a href="organizations.php" class="cancel-button">Cancel</a>
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

<?php include 'templates/footer.php'; ?>
</body>
</html>
