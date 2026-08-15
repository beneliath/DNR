<?php
include 'config.php';
include 'functions.php';
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

    $organization_name = trim($_POST['organization_name'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $affiliation = trim($_POST['affiliation'] ?? '');
    $distinctives = trim($_POST['distinctives'] ?? '');
    $website_url = trim($_POST['website_url'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $fax = trim($_POST['fax'] ?? '');
    $mailing_address_line_1 = trim($_POST['mailing_address_line_1'] ?? '');
    $mailing_address_line_2 = trim($_POST['mailing_address_line_2'] ?? '');
    $mailing_city = trim($_POST['mailing_city'] ?? '');
    $mailing_state = trim($_POST['mailing_state'] ?? '');
    $mailing_zipcode = trim($_POST['mailing_zipcode'] ?? '');
    $mailing_country = trim($_POST['mailing_country'] ?? '');
    $physical_address_line_1 = trim($_POST['physical_address_line_1'] ?? '');
    $physical_address_line_2 = trim($_POST['physical_address_line_2'] ?? '');
    $physical_city = trim($_POST['physical_city'] ?? '');
    $physical_state = trim($_POST['physical_state'] ?? '');
    $physical_zipcode = trim($_POST['physical_zipcode'] ?? '');
    $physical_country = trim($_POST['physical_country'] ?? '');

    $contact_first_name = trim($_POST['contact_first_name'] ?? '');
    $contact_last_name = trim($_POST['contact_last_name'] ?? '');
    $contact_role = strtolower(trim($_POST['contact_role'] ?? ''));
    $contact_role_other = trim($_POST['contact_role_other'] ?? '');
    $contact_email = trim($_POST['contact_email'] ?? '');
    $contact_email_confirm = trim($_POST['contact_email_confirm'] ?? '');
    $contact_phone = trim($_POST['contact_phone'] ?? '');

    $contact_candidates = [[
        'first_name' => $contact_first_name,
        'last_name' => $contact_last_name,
        'role' => $contact_role,
        'role_other' => $contact_role_other,
        'email' => $contact_email,
        'email_confirm' => $contact_email_confirm,
        'phone' => $contact_phone
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
                'phone' => trim($submitted_contact['phone'] ?? '')
            ];
        }
    }

    if ($organization_name === '') {
        $errorMessages[] = "Organization name is required.";
    }
    if ($website_url !== '' && !filter_var($website_url, FILTER_VALIDATE_URL)) {
        $errorMessages[] = "Please provide a valid website URL.";
    }
    $contacts_to_create = [];
    foreach ($contact_candidates as $contact_index => $candidate) {
        $contact_number = $contact_index + 1;
        $has_contact_data = implode('', $candidate) !== '';
        if (!$has_contact_data) {
            continue;
        }
        if ($candidate['first_name'] === '') {
            $errorMessages[] = "Contact {$contact_number} requires a first name.";
        }
        if ($candidate['last_name'] === '') {
            $errorMessages[] = "Contact {$contact_number} requires a last name.";
        }
        if (!in_array($candidate['role'], ['pastor', 'admin', 'other'], true)) {
            $errorMessages[] = "Contact {$contact_number} requires a valid role.";
        }
        if ($candidate['role'] === 'other' && $candidate['role_other'] === '') {
            $errorMessages[] = "Contact {$contact_number} requires an other-role description.";
        }
        if (!filter_var($candidate['email'], FILTER_VALIDATE_EMAIL)) {
            $errorMessages[] = "Contact {$contact_number} requires a valid email address.";
        } elseif (!hash_equals($candidate['email'], $candidate['email_confirm'])) {
            $errorMessages[] = "Contact {$contact_number} email addresses do not match.";
        }
        $contacts_to_create[] = $candidate;
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
                            contact_role_other, contact_email, contact_phone
                         ) VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $saved_contact_first_name = '';
                    $saved_contact_last_name = '';
                    $saved_contact_role = '';
                    $saved_contact_role_other = '';
                    $saved_contact_email = '';
                    $saved_contact_phone = '';
                    $contact_stmt->bind_param(
                        "issssss",
                        $organization_id,
                        $saved_contact_first_name,
                        $saved_contact_last_name,
                        $saved_contact_role,
                        $saved_contact_role_other,
                        $saved_contact_email,
                        $saved_contact_phone
                    );

                    foreach ($contacts_to_create as $contact_to_create) {
                        $saved_contact_first_name = $contact_to_create['first_name'];
                        $saved_contact_last_name = $contact_to_create['last_name'];
                        $saved_contact_role = $contact_to_create['role'];
                        $saved_contact_role_other = $contact_to_create['role_other'];
                        $saved_contact_email = $contact_to_create['email'];
                        $saved_contact_phone = $contact_to_create['phone'];
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
                error_log($exception->getMessage());
                $error = true;
                $errorMessages[] = "Unable to save the organization.";
            }
        }
    }
}

// Display success message if it exists in session
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']); // Clear the message after displaying
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Organizations - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.10">
    <style>
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        /* Add box-sizing to ensure consistent sizing */
        *, *:before, *:after {
            box-sizing: border-box;
        }
        .form-group input[type="text"],
        .form-group input[type="url"],
        .form-group input[type="email"],
        .form-group input[type="tel"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #fff;
            color: #000;
            margin: 0;
            box-sizing: border-box;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
        }

        /* Dark mode styles */
        .dark-mode .form-group input[type="text"],
        .dark-mode .form-group input[type="url"],
        .dark-mode .form-group input[type="email"],
        .dark-mode .form-group input[type="tel"],
        .dark-mode .form-group textarea,
        .dark-mode .form-group select {
            background-color: #1e1e1e;
            color: #fff;
            border-color: #444;
        }
        
        .form-group input[type="text"]:focus,
        .form-group input[type="url"]:focus,
        .form-group input[type="email"]:focus,
        .form-group input[type="tel"]:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #4a9eff;
        }

        .address-section {
            margin: 20px 0;
            padding: 15px;
            border: 1px solid #ddd;
            border-radius: 5px;
        }

        .dark-mode .address-section {
            border-color: #444;
        }

        .address-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-top: 10px;
        }
        .address-full-width {
            grid-column: 1 / -1;
        }
        .required {
            color: inherit;
        }
        .required::after {
            content: " *";
            color: red;
            display: inline;
        }
        #mailing_address_section {
            display: none;
        }
        .radio-group {
            margin: 15px 0;
        }
        .radio-group label {
            margin-right: 20px;
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--text-color);
            cursor: pointer;
        }
        /* Style for radio buttons */
        .radio-group input[type="radio"] {
            appearance: none;
            -webkit-appearance: none;
            width: 16px;
            height: 16px;
            border: 2px solid #666;
            border-radius: 50%;
            margin: 0;
            cursor: pointer;
            position: relative;
        }

        .dark-mode .radio-group input[type="radio"] {
            border-color: #888;
        }

        .radio-group input[type="radio"]:checked {
            border-color: #357abd;
            background-color: transparent;
        }

        .radio-group input[type="radio"]:checked::after {
            content: '';
            position: absolute;
            width: 8px;
            height: 8px;
            background-color: #357abd;
            border-radius: 50%;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
        }

        .radio-group input[type="radio"]:focus {
            outline: none;
            box-shadow: 0 0 0 2px rgba(53, 122, 189, 0.3);
        }
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
        .narrow-select {
            width: 200px !important;
        }
        .role-container {
            display: flex;
            align-items: center;
            gap: 30px;
            margin-bottom: 15px;
        }
        
        .role-container .form-group {
            margin-bottom: 0;
        }
        
        .role-container .form-group:first-child {
            flex: 1;
        }
        
        .role-container .form-group:last-child {
            flex: 0 0 200px;
        }
        .email-container {
            display: flex;
            gap: 30px;
            align-items: flex-start;
        }
        .email-container .form-group {
            flex: 1;
            margin-bottom: 0;
        }
        .form-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #fff;
            color: #000;
            margin: 0;
            box-sizing: border-box;
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 8px center;
            background-size: 1em;
            padding-right: 32px;
        }

        /* Dark mode styles */
        .dark-mode .form-group select {
            background-color: #1e1e1e;
            color: #fff;
            border-color: #444;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='white' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'%3e%3cpolyline points='6 9 12 15 18 9'%3e%3c/polyline%3e%3c/svg%3e");
        }

        .contact-fields {
            display: grid;
            gap: 15px;
        }

        .name-phone-row {
            display: flex;
            gap: 30px;
            align-items: flex-start;
        }

        .name-phone-row .form-group:first-child {
            flex: 1;
        }

        .name-phone-row .form-group:nth-child(2) {
            flex: 1;
        }

        .name-phone-row .form-group:last-child {
            flex: 0 0 200px;
        }

        .add-contact-btn {
            background-color: var(--button-add-color);
            color: white;
            padding: 5px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 15px;
        }
        .add-contact-btn:hover {
            background-color: var(--button-hover-color);
        }
        .remove-contact-btn {
            background-color: var(--button-delete-color);
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
        }
        .remove-contact-btn:hover {
            background-color: var(--button-hover-color);
        }
        .contact-entry {
            margin-bottom: 15px;
            padding: 15px;
            border: 1px solid #444;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <?php if (isset($message)) echo "<p class='success'>$message</p>"; ?>
    <?php if (isset($error) && $error && !empty($errorMessages)) echo "<p class='error'>" . implode("<br>", array_map('htmlspecialchars', $errorMessages)) . "</p>"; ?>
    <h2>Add Organization</h2>
    <form method="post" action="add_organization.php">
        <?php echo csrfInput(); ?>
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
                <input type="text" name="phone" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
            </div>
            
            <div class="form-group">
                <label>Fax</label>
                <input type="text" name="fax" value="<?php echo htmlspecialchars($_POST['fax'] ?? ''); ?>">
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
                                <input type="text" name="contact_first_name" id="contact_first_name" autocomplete="given-name">
                            </div>

                            <div class="form-group">
                                <label id="last_name_label">Last Name</label>
                                <input type="text" name="contact_last_name" id="contact_last_name" autocomplete="family-name">
                            </div>

                            <div class="form-group">
                                <label>Phone</label>
                                <input type="tel" name="contact_phone" id="contact_phone">
                            </div>
                        </div>

                        <div class="role-container">
                            <div class="form-group">
                                <label id="role_label">Role</label>
                                <select name="contact_role" id="contact_role" class="narrow-select" onchange="toggleOtherRole()">
                                    <option value="">Select Role</option>
                                    <option value="pastor">Pastor</option>
                                    <option value="admin">Admin</option>
                                    <option value="other">Other</option>
                                </select>
                            </div>

                            <div class="form-group" id="other_role_group" style="display: none;">
                                <label>Describe Other Role</label>
                                <input type="text" name="contact_role_other" id="contact_role_other">
                            </div>
                        </div>

                        <div class="email-container">
                            <div class="form-group">
                                <label id="email_label">Email</label>
                                <input type="email" name="contact_email" id="contact_email">
                            </div>

                            <div class="form-group">
                                <label id="email_confirm_label">Confirm Email</label>
                                <input type="email" name="contact_email_confirm" id="contact_email_confirm">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <button type="button" onclick="addContact()" class="add-contact-btn">Add Another Contact</button>
        </div>

        <div class="form-group" style="display: flex; justify-content: flex-end; padding: 0; margin: 0;">
            <input type="submit" name="save_org" value="SAVE ORGANIZATION" class="save-button">
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
    }
}

document.addEventListener('DOMContentLoaded', function() {
    // Auto-hide success message after 7 seconds
    const successMessage = document.querySelector('.success');
    if (successMessage) {
        setTimeout(function() {
            successMessage.style.transition = 'opacity 1s';
            successMessage.style.opacity = '0';
            setTimeout(function() {
                successMessage.remove();
            }, 1000);
        }, 7000);
    }

    const sameAddressRadios = document.querySelectorAll('input[name="same_address"]');
    const mailingSection = document.getElementById('mailing_address_section');
    const mailingInputs = mailingSection.querySelectorAll('input, select');

    function toggleMailingAddress(showMailing) {
        mailingSection.style.display = showMailing ? 'block' : 'none';
        mailingInputs.forEach(input => {
            input.required = showMailing;
        });
    }

    sameAddressRadios.forEach(radio => {
        radio.addEventListener('change', function() {
            toggleMailingAddress(this.value === 'no');
        });
    });

    // Initial state
    toggleMailingAddress(false);

    // A partially entered contact still requires both name fields and contact details.
    const contactFirstNameInput = document.getElementById('contact_first_name');
    const contactLastNameInput = document.getElementById('contact_last_name');
    const contactRoleSelect = document.getElementById('contact_role');
    const contactEmailInput = document.getElementById('contact_email');
    const contactEmailConfirmInput = document.getElementById('contact_email_confirm');
    
    // Get the labels for the required fields
    const firstNameLabel = document.getElementById('first_name_label');
    const lastNameLabel = document.getElementById('last_name_label');
    const roleLabel = document.getElementById('role_label');
    const emailLabel = document.getElementById('email_label');
    const emailConfirmLabel = document.getElementById('email_confirm_label');

    function updateContactFieldRequirements() {
        const hasContactName = contactFirstNameInput.value.trim() !== '' || contactLastNameInput.value.trim() !== '';
        
        // Update required attribute
        contactFirstNameInput.required = hasContactName;
        contactLastNameInput.required = hasContactName;
        contactRoleSelect.required = hasContactName;
        contactEmailInput.required = hasContactName;
        contactEmailConfirmInput.required = hasContactName;
        
        // Update labels with asterisk
        if (hasContactName) {
            firstNameLabel.classList.add('required');
            lastNameLabel.classList.add('required');
            roleLabel.classList.add('required');
            emailLabel.classList.add('required');
            emailConfirmLabel.classList.add('required');
        } else {
            firstNameLabel.classList.remove('required');
            lastNameLabel.classList.remove('required');
            roleLabel.classList.remove('required');
            emailLabel.classList.remove('required');
            emailConfirmLabel.classList.remove('required');
        }
    }

    contactFirstNameInput.addEventListener('input', updateContactFieldRequirements);
    contactLastNameInput.addEventListener('input', updateContactFieldRequirements);
    // Initial check
    updateContactFieldRequirements();
});

let contactCount = 1;

function addContact() {
    contactCount++;
    const container = document.getElementById('contacts-container');
    const newContact = document.createElement('div');
    newContact.className = 'contact-entry';
    newContact.id = `contact-${contactCount}`;
    
    newContact.innerHTML = `
        <div class="contact-fields">
            <div class="name-phone-row">
                <div class="form-group">
                    <label class="required">First Name</label>
                    <input type="text" name="contacts[${contactCount-1}][first_name]" autocomplete="given-name" required>
                </div>

                <div class="form-group">
                    <label class="required">Last Name</label>
                    <input type="text" name="contacts[${contactCount-1}][last_name]" autocomplete="family-name" required>
                </div>

                <div class="form-group">
                    <label class="required">Phone</label>
                    <input type="tel" name="contacts[${contactCount-1}][phone]" required>
                </div>
            </div>

            <div class="role-container">
                <div class="form-group">
                    <label class="required">Role</label>
                    <select name="contacts[${contactCount-1}][role]" class="narrow-select" required onchange="toggleOtherRole(${contactCount})">
                        <option value="">Select Role</option>
                        <option value="pastor">Pastor</option>
                        <option value="admin">Admin</option>
                        <option value="other">Other</option>
                    </select>
                </div>

                <div class="form-group" id="other_role_group_${contactCount}" style="display: none;">
                    <label class="required">Describe Other Role</label>
                    <input type="text" name="contacts[${contactCount-1}][role_other]">
                </div>
            </div>

            <div class="email-container">
                <div class="form-group">
                    <label class="required">Email</label>
                    <input type="email" name="contacts[${contactCount-1}][email]" required>
                </div>

                <div class="form-group">
                    <label class="required">Confirm Email</label>
                    <input type="email" name="contacts[${contactCount-1}][email_confirm]" required>
                </div>
            </div>
        </div>
        <button type="button" onclick="removeContact(${contactCount})" class="remove-contact-btn">Remove</button>
    `;
    
    container.appendChild(newContact);
}

function removeContact(id) {
    if (id === 1) return; // Prevent removing the first contact
    const contact = document.getElementById(`contact-${id}`);
    if (contact) {
        contact.remove();
    }
}

function toggleOtherRole(id = '') {
    const suffix = id ? `_${id}` : '';
    const roleSelect = document.querySelector(`select[name="contacts[${id-1}][role]"]`) || document.getElementById('contact_role');
    const otherRoleGroup = document.getElementById(`other_role_group${suffix}`);
    const otherRoleInput = otherRoleGroup.querySelector('input');
    
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
