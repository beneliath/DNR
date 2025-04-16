<?php
// We assume session_start() is already called earlier (e.g., in index.php or header.php)
session_start(); // Start session to access session variables
include 'config.php';
include 'functions.php';

// Ensure the user is logged in and is an admin
requireLogin(); // Will redirect to login.php if not logged in

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_org'])) {
    $error = false;
    $errorMessages = array();

    // Remove the email validation check since it's no longer required
    if (!empty($_POST['contact_email']) && $_POST['contact_email'] !== $_POST['contact_email_confirm']) {
        $error = true;
        $errorMessages[] = "Email addresses do not match.";
    }

    // Update the role validation to only check if a role is provided
    if (!empty($_POST['contact_role']) && $_POST['contact_role'] === 'other' && empty($_POST['contact_role_other'])) {
        $error = true;
        $errorMessages[] = "Please specify the other role.";
    }

    if (!$error) {
        // Sanitize user input for organization
        $organization_name = $conn->real_escape_string($_POST['organization_name']);
        
        // Check if organization name already exists
        $check_sql = "SELECT id FROM organizations WHERE organization_name = '$organization_name'";
        $result = $conn->query($check_sql);
        
        if ($result->num_rows > 0) {
            $error = true;
            $errorMessages[] = "An organization with this name already exists.";
        } else {
            $notes = $conn->real_escape_string($_POST['notes']);
            $affiliation = $conn->real_escape_string($_POST['affiliation']);
            $distinctives = $conn->real_escape_string($_POST['distinctives']);
            $website_url = $conn->real_escape_string($_POST['website_url']);
            $phone = $conn->real_escape_string($_POST['phone']);
            $fax = $conn->real_escape_string($_POST['fax']);
            $mailing_address_line_1 = $conn->real_escape_string($_POST['mailing_address_line_1']);
            $mailing_address_line_2 = $conn->real_escape_string($_POST['mailing_address_line_2']);
            $mailing_city = $conn->real_escape_string($_POST['mailing_city']);
            $mailing_state = $conn->real_escape_string($_POST['mailing_state']);
            $mailing_zipcode = $conn->real_escape_string($_POST['mailing_zipcode']);
            $mailing_country = $conn->real_escape_string($_POST['mailing_country']);
            $physical_address_line_1 = $conn->real_escape_string($_POST['physical_address_line_1']);
            $physical_address_line_2 = $conn->real_escape_string($_POST['physical_address_line_2']);
            $physical_city = $conn->real_escape_string($_POST['physical_city']);
            $physical_state = $conn->real_escape_string($_POST['physical_state']);
            $physical_zipcode = $conn->real_escape_string($_POST['physical_zipcode']);
            $physical_country = $conn->real_escape_string($_POST['physical_country']);

            // Insert new organization into the database
            $sql = "INSERT INTO organizations (organization_name, notes, affiliation, distinctives, website_url, phone, fax, mailing_address_line_1, mailing_address_line_2, mailing_city, mailing_state, mailing_zipcode, mailing_country, physical_address_line_1, physical_address_line_2, physical_city, physical_state, physical_zipcode, physical_country)
                    VALUES ('$organization_name', '$notes', '$affiliation', '$distinctives', '$website_url', '$phone', '$fax', '$mailing_address_line_1', '$mailing_address_line_2', '$mailing_city', '$mailing_state', '$mailing_zipcode', '$mailing_country', '$physical_address_line_1', '$physical_address_line_2', '$physical_city', '$physical_state', '$physical_zipcode', '$physical_country')";

            if ($conn->query($sql) === TRUE) {
                $organization_id = $conn->insert_id;

                // Only proceed with contact information if contact name is provided
                if (!empty($_POST['contact_name'])) {
                    // Sanitize contact information
                    $contact_name = $conn->real_escape_string($_POST['contact_name']);
                    $contact_role = strtolower($conn->real_escape_string($_POST['contact_role']));
                    $contact_role_other = $conn->real_escape_string($_POST['contact_role_other']);
                    $contact_email = $conn->real_escape_string($_POST['contact_email']);
                    $contact_phone = $conn->real_escape_string($_POST['contact_phone']);

                    // Validate contact role is one of the allowed ENUM values
                    if (!in_array($contact_role, ['pastor', 'admin', 'other'])) {
                        $error = true;
                        $errorMessages[] = "Invalid contact role selected.";
                    }

                    // Insert contact information only if no errors
                    if (!$error) {
                        $contact_sql = "INSERT INTO contacts (organization_id, contact_name, contact_role, contact_role_other, contact_email, contact_phone)
                                      VALUES ('$organization_id', '$contact_name', '$contact_role', '$contact_role_other', '$contact_email', '$contact_phone')";

                        if ($conn->query($contact_sql) === TRUE) {
                            $_SESSION['success_message'] = "Organization and contact information saved successfully.";
                            header('Location: ' . $_SERVER['PHP_SELF']);
                            exit();
                        } else {
                            $error = true;
                            $errorMessages[] = "Error saving contact information: " . $conn->error;
                        }
                    }
                } else {
                    // If no contact name provided, just show success message for organization
                    $_SESSION['success_message'] = "Organization saved successfully.";
                    header('Location: ' . $_SERVER['PHP_SELF']);
                    exit();
                }
            } else {
                $error = true;
                $errorMessages[] = "Error saving organization: " . $conn->error;
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
    <title>Organizations - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css">
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

        .name-phone-row .form-group:last-child {
            width: 200px;
        }

        .add-contact-btn {
            background-color: #666;
            color: white;
            padding: 5px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 15px;
            font-family: inherit;
            font-size: inherit;
        }
        .add-contact-btn:hover {
            background-color: #FF9800;
        }
        .remove-contact-btn {
            background-color: #f44336;
            color: white;
            padding: 5px 10px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            margin-top: 10px;
        }
        .remove-contact-btn:hover {
            background-color: #da190b;
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
    <?php if (isset($error) && $error && !empty($errorMessages)) echo "<p class='error'>" . implode("<br>", $errorMessages) . "</p>"; ?>
    <h2>Add Organization</h2>
    <form method="post" action="add_organization.php">
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
                                <label>Name</label>
                                <input type="text" name="contact_name" id="contact_name">
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

    // Add event listener for contact name input
    const contactNameInput = document.getElementById('contact_name');
    const contactRoleSelect = document.getElementById('contact_role');
    const contactEmailInput = document.getElementById('contact_email');
    const contactEmailConfirmInput = document.getElementById('contact_email_confirm');
    
    // Get the labels for the required fields
    const roleLabel = document.getElementById('role_label');
    const emailLabel = document.getElementById('email_label');
    const emailConfirmLabel = document.getElementById('email_confirm_label');

    function updateContactFieldRequirements() {
        const hasContactName = contactNameInput.value.trim() !== '';
        
        // Update required attribute
        contactRoleSelect.required = hasContactName;
        contactEmailInput.required = hasContactName;
        contactEmailConfirmInput.required = hasContactName;
        
        // Update labels with asterisk
        if (hasContactName) {
            roleLabel.classList.add('required');
            emailLabel.classList.add('required');
            emailConfirmLabel.classList.add('required');
        } else {
            roleLabel.classList.remove('required');
            emailLabel.classList.remove('required');
            emailConfirmLabel.classList.remove('required');
        }
    }

    contactNameInput.addEventListener('input', updateContactFieldRequirements);
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
                    <label class="required">Name</label>
                    <input type="text" name="contacts[${contactCount-1}][name]" required>
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

