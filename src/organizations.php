<?php
// We assume session_start() is already called earlier (e.g., in index.php or header.php)
session_start(); // Start session to access session variables
include 'config.php';
include 'functions.php';

// Ensure the user is logged in and is an admin
requireLogin(); // Will redirect to login.php if not logged in

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['save_org'])) {
    // Sanitize user input
    $organization_name = $conn->real_escape_string($_POST['organization_name']);
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
        $message = "Organization saved successfully.";
    } else {
        $error = "Error: " . $conn->error;
    }
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
        .dark-mode .form-group textarea,
        .dark-mode .form-group select {
            background-color: #1e1e1e;
            color: #fff;
            border-color: #444;
        }
        
        .form-group input[type="text"]:focus,
        .form-group input[type="url"]:focus,
        .form-group input[type="email"]:focus,
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
        }
        .contact-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <?php if (isset($message)) echo "<p class='success'>$message</p>"; ?>
    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
    <h2>Add Organization</h2>
    <form method="post" action="organizations.php">
        <div class="form-group">
            <label class="required">Organization Name</label>
            <input type="text" name="organization_name" required>
        </div>
        
        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" rows="3"></textarea>
        </div>
        
        <div class="form-group">
            <label>Affiliation</label>
            <input type="text" name="affiliation">
        </div>
        
        <div class="form-group">
            <label>Distinctives</label>
            <input type="text" name="distinctives">
        </div>
        
        <div class="form-group">
            <label>Website URL</label>
            <input type="url" name="website_url">
        </div>
        
        <div class="contact-grid">
            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="phone">
            </div>
            
            <div class="form-group">
                <label>Fax</label>
                <input type="text" name="fax">
            </div>
        </div>

        <div class="radio-group">
            <label class="required">Mailing and Physical Address the same?</label>
            <div>
                <label><input type="radio" name="same_address" value="yes" checked> Yes</label>
                <label><input type="radio" name="same_address" value="no"> No</label>
            </div>
        </div>

        <div id="physical_address_section" class="address-section">
            <h3 class="required">Physical Address</h3>
            <div class="address-grid">
                <div class="address-full-width">
                    <input type="text" name="physical_address_line_1" placeholder="Address Line 1" required>
                </div>
                <div class="address-full-width">
                    <input type="text" name="physical_address_line_2" placeholder="Address Line 2">
                </div>
                <div>
                    <input type="text" name="physical_city" placeholder="City" required>
                </div>
                <div>
                    <input type="text" name="physical_state" placeholder="State/Province" required>
                </div>
                <div>
                    <input type="text" name="physical_zipcode" placeholder="Zip/Postal" required>
                </div>
                <div class="address-full-width">
                    <select name="physical_country" required>
                        <option value="">Select Country</option>
                        <option value="USA">United States</option>
                        <option value="CAN">Canada</option>
                        <!-- Add more countries as needed -->
                    </select>
                </div>
            </div>
        </div>

        <div id="mailing_address_section" class="address-section">
            <h3 class="required">Mailing Address</h3>
            <div class="address-grid">
                <div class="address-full-width">
                    <input type="text" name="mailing_address_line_1" placeholder="Address Line 1">
                </div>
                <div class="address-full-width">
                    <input type="text" name="mailing_address_line_2" placeholder="Address Line 2">
                </div>
                <div>
                    <input type="text" name="mailing_city" placeholder="City">
                </div>
                <div>
                    <input type="text" name="mailing_state" placeholder="State/Province">
                </div>
                <div>
                    <input type="text" name="mailing_zipcode" placeholder="Zip/Postal">
                </div>
                <div class="address-full-width">
                    <select name="mailing_country">
                        <option value="">Select Country</option>
                        <option value="USA">United States</option>
                        <option value="CAN">Canada</option>
                        <!-- Add more countries as needed -->
                    </select>
                </div>
            </div>
        </div>

        <div class="form-group">
            <input type="submit" name="save_org" value="Save Organization">
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
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
});
</script>

<?php include 'templates/footer.php'; ?>
</body>
</html>

