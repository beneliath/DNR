<?php
include 'config.php';
include 'functions.php';
startSecureSession();
requireLogin();

// Get user role from session
$user_role = $_SESSION['role'] ?? '';

// Only admins and editors can edit organizations
if ($user_role !== 'admin' && $user_role !== 'editor') {
    header("Location: organizations.php");
    exit();
}

// Check if ID is provided and is numeric
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: organizations.php");
    exit();
}

$org_id = intval($_GET['id']);
$from = $_GET['from'] ?? '';
if ($from === 'view') {
    $cancel_url = "view_organization.php?id=$org_id";
} else {
    $cancel_url = "organizations.php";
}

// Fetch organization details
$query = "SELECT * FROM organizations WHERE id = ? AND is_deleted = 0";

$stmt = $conn->prepare($query);
if ($stmt === false) {
    die("Error preparing statement: " . $conn->error);
}

$stmt->bind_param("i", $org_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    header("Location: organizations.php");
    exit();
}

$organization = $result->fetch_assoc();
$address_pairs = [
    ['mailing_address_line_1', 'physical_address_line_1'],
    ['mailing_address_line_2', 'physical_address_line_2'],
    ['mailing_city', 'physical_city'],
    ['mailing_state', 'physical_state'],
    ['mailing_zipcode', 'physical_zipcode'],
    ['mailing_country', 'physical_country'],
];
$same_address = true;
foreach ($address_pairs as [$mailing_field, $physical_field]) {
    if ((string) $organization[$mailing_field] !== (string) $organization[$physical_field]) {
        $same_address = false;
        break;
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();

    $error = false;
    $errorMessages = array();

    $organization_name = trim($_POST['organization_name'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $affiliation = trim($_POST['affiliation'] ?? '');
    $distinctives = trim($_POST['distinctives'] ?? '');
    $website_url = trim($_POST['website_url'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $phone_country_code = trim($_POST['phone_country_code'] ?? '+1');
    $fax = trim($_POST['fax'] ?? '');
    $fax_country_code = trim($_POST['fax_country_code'] ?? '+1');
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
    $same_address = ($_POST['same_address'] ?? 'no') === 'yes';
    if ($same_address) {
        $mailing_address_line_1 = $physical_address_line_1;
        $mailing_address_line_2 = $physical_address_line_2;
        $mailing_city = $physical_city;
        $mailing_state = $physical_state;
        $mailing_zipcode = $physical_zipcode;
        $mailing_country = $physical_country;
    }

    if ($organization_name === '') {
        $error = true;
        $errorMessages[] = "Organization name is required.";
    } elseif (normalizedHttpUrl($website_url) === null) {
        $error = true;
        $errorMessages[] = "Please provide a valid website URL.";
    } elseif ($physical_address_line_1 === '' || $physical_city === '' || $physical_state === ''
        || $physical_zipcode === '' || $physical_country === ''
    ) {
        $error = true;
        $errorMessages[] = 'A complete physical address is required.';
    } elseif (!$same_address && ($mailing_address_line_1 === '' || $mailing_city === ''
        || $mailing_state === '' || $mailing_zipcode === '' || $mailing_country === '')
    ) {
        $error = true;
        $errorMessages[] = 'A complete mailing address is required when it differs from the physical address.';
    } else {
        $website_url = normalizedHttpUrl($website_url);
    }
    try {
        $phone = normalizePhoneNumber($phone_country_code, $phone, 'Organization phone');
    } catch (InvalidArgumentException $exception) {
        $error = true;
        $errorMessages[] = $exception->getMessage();
    }
    try {
        $fax = normalizePhoneNumber($fax_country_code, $fax, 'Organization fax');
    } catch (InvalidArgumentException $exception) {
        $error = true;
        $errorMessages[] = $exception->getMessage();
    }

    $check_stmt = $conn->prepare("SELECT id FROM organizations WHERE organization_name = ? AND id != ?");
    $check_stmt->bind_param("si", $organization_name, $org_id);
    $check_stmt->execute();

    if (!$error && $check_stmt->get_result()->num_rows > 0) {
        $error = true;
        $errorMessages[] = "An organization with this name already exists.";
    }

    if (!$error) {
        $update_stmt = $conn->prepare(
            "UPDATE organizations SET
                organization_name = ?, notes = ?, affiliation = ?, distinctives = ?, website_url = ?,
                phone = ?, fax = ?, mailing_address_line_1 = ?, mailing_address_line_2 = ?,
                mailing_city = ?, mailing_state = ?, mailing_zipcode = ?, mailing_country = ?,
                physical_address_line_1 = ?, physical_address_line_2 = ?, physical_city = ?,
                physical_state = ?, physical_zipcode = ?, physical_country = ?
             WHERE id = ?"
        );
        $update_stmt->bind_param(
            "sssssssssssssssssssi",
            $organization_name, $notes, $affiliation, $distinctives, $website_url, $phone, $fax,
            $mailing_address_line_1, $mailing_address_line_2, $mailing_city, $mailing_state,
            $mailing_zipcode, $mailing_country, $physical_address_line_1, $physical_address_line_2,
            $physical_city, $physical_state, $physical_zipcode, $physical_country, $org_id
        );

        if ($update_stmt->execute()) {
            $_SESSION['success_message'] = "Organization updated successfully.";
            header("Location: view_organization.php?id=$org_id");
            exit();
        } else {
            $error = true;
            $errorMessages[] = "Unable to update the organization.";
        }
    }

    if ($error) {
        foreach ([
            'organization_name', 'notes', 'affiliation', 'distinctives', 'website_url',
            'phone', 'fax', 'mailing_address_line_1', 'mailing_address_line_2',
            'mailing_city', 'mailing_state', 'mailing_zipcode', 'mailing_country',
            'physical_address_line_1', 'physical_address_line_2', 'physical_city',
            'physical_state', 'physical_zipcode', 'physical_country',
        ] as $field_name) {
            $organization[$field_name] = ${$field_name};
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone_country_code_value = trim($_POST['phone_country_code'] ?? '+1');
    [, $phone_local_value] = phoneNumberInputParts($_POST['phone'] ?? '', $phone_country_code_value);
    $fax_country_code_value = trim($_POST['fax_country_code'] ?? '+1');
    [, $fax_local_value] = phoneNumberInputParts($_POST['fax'] ?? '', $fax_country_code_value);
} else {
    [$phone_country_code_value, $phone_local_value] = phoneNumberInputParts($organization['phone'] ?? '');
    [$fax_country_code_value, $fax_local_value] = phoneNumberInputParts($organization['fax'] ?? '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Edit Organization - DNR</title>
    <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
    <style>
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
        }
        .form-group input[type="text"],
        .form-group input[type="url"],
        .form-group textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
            background-color: #fff;
            color: #000;
        }
        .dark-mode .form-group input[type="text"],
        .dark-mode .form-group input[type="url"],
        .dark-mode .form-group textarea {
            background-color: #1e1e1e;
            color: #fff;
            border-color: #444;
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
        .action-buttons {
            margin-top: 20px;
            display: flex;
            gap: 10px;
        }
        .action-button, .action-button[type="submit"] {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 12px 20px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            color: white;
            height: 44px;
            box-sizing: border-box;
        }
        .back-button {
            background-color: var(--button-neutral-color);
        }
        .save-button {
            background-color: var(--button-save-color);
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <?php if (isset($error) && $error && !empty($errorMessages)) echo "<p class='error'>" . implode("<br>", array_map('htmlspecialchars', $errorMessages)) . "</p>"; ?>

    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="organizations.php">Organizations</a><span aria-hidden="true">/</span><span>Edit Organization</span></nav>
    <div class="page-heading form-page-heading"><div><h1>Edit Organization</h1><p class="page-intro">Update organization information and addresses.</p></div></div>
    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $org_id); ?>" class="organization-form">
        <?php echo csrfInput(); ?>
        <div class="form-group">
            <label class="required">Organization Name</label>
            <input type="text" name="organization_name" required value="<?php echo htmlspecialchars($organization['organization_name']); ?>">
        </div>

        <div class="form-group">
            <label>Notes</label>
            <textarea name="notes" rows="4"><?php echo htmlspecialchars($organization['notes']); ?></textarea>
        </div>

        <div class="form-group">
            <label>Affiliation</label>
            <input type="text" name="affiliation" value="<?php echo htmlspecialchars($organization['affiliation']); ?>">
        </div>

        <div class="form-group">
            <label>Distinctives</label>
            <input type="text" name="distinctives" value="<?php echo htmlspecialchars($organization['distinctives']); ?>">
        </div>

        <div class="form-group">
            <label>Website URL</label>
            <input type="url" name="website_url" value="<?php echo htmlspecialchars($organization['website_url']); ?>">
        </div>

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

        <div class="radio-group">
            <label class="required">Mailing and Physical Address the Same</label>
            <div>
                <label><input type="radio" name="same_address" value="yes" <?php echo $same_address ? 'checked' : ''; ?>> Yes</label>
                <label><input type="radio" name="same_address" value="no" <?php echo !$same_address ? 'checked' : ''; ?>> No</label>
            </div>
        </div>

        <div class="address-section" id="physical_address_section">
            <h3 class="required">Physical Address</h3>
            <div class="address-grid">
                <div class="address-full-width">
                    <input type="text" name="physical_address_line_1" placeholder="Address Line 1" value="<?php echo htmlspecialchars($organization['physical_address_line_1']); ?>">
                </div>
                <div class="address-full-width">
                    <input type="text" name="physical_address_line_2" placeholder="Address Line 2" value="<?php echo htmlspecialchars($organization['physical_address_line_2']); ?>">
                </div>
                <div>
                    <input type="text" name="physical_city" placeholder="City" value="<?php echo htmlspecialchars($organization['physical_city']); ?>">
                </div>
                <div>
                    <input type="text" name="physical_state" placeholder="State/Province" value="<?php echo htmlspecialchars($organization['physical_state']); ?>">
                </div>
                <div>
                    <input type="text" name="physical_zipcode" placeholder="Zip/Postal" value="<?php echo htmlspecialchars($organization['physical_zipcode']); ?>">
                </div>
                <div class="address-full-width">
                    <select name="physical_country">
                        <option value="">Select Country</option>
                        <option value="USA" <?php echo $organization['physical_country'] === 'USA' ? 'selected' : ''; ?>>United States</option>
                        <option value="CAN" <?php echo $organization['physical_country'] === 'CAN' ? 'selected' : ''; ?>>Canada</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="address-section" id="mailing_address_section">
            <h3>Mailing Address</h3>
            <div class="address-grid">
                <div class="address-full-width">
                    <input type="text" name="mailing_address_line_1" placeholder="Address Line 1" value="<?php echo htmlspecialchars($organization['mailing_address_line_1']); ?>">
                </div>
                <div class="address-full-width">
                    <input type="text" name="mailing_address_line_2" placeholder="Address Line 2" value="<?php echo htmlspecialchars($organization['mailing_address_line_2']); ?>">
                </div>
                <div>
                    <input type="text" name="mailing_city" placeholder="City" value="<?php echo htmlspecialchars($organization['mailing_city']); ?>">
                </div>
                <div>
                    <input type="text" name="mailing_state" placeholder="State/Province" value="<?php echo htmlspecialchars($organization['mailing_state']); ?>">
                </div>
                <div>
                    <input type="text" name="mailing_zipcode" placeholder="Zip/Postal" value="<?php echo htmlspecialchars($organization['mailing_zipcode']); ?>">
                </div>
                <div class="address-full-width">
                    <select name="mailing_country">
                        <option value="">Select Country</option>
                        <option value="USA" <?php echo $organization['mailing_country'] === 'USA' ? 'selected' : ''; ?>>United States</option>
                        <option value="CAN" <?php echo $organization['mailing_country'] === 'CAN' ? 'selected' : ''; ?>>Canada</option>
                    </select>
                </div>
            </div>
        </div>

        <div class="action-buttons">
            <a href="<?php echo $cancel_url; ?>" class="action-button back-button cancel-button">Cancel</a>
            <input type="submit" value="Save Changes" class="action-button save-button">
        </div>
    </form>
</div>
<?php include 'templates/footer.php'; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const mailingSection = document.getElementById('mailing_address_section');
    const sameAddressInputs = document.querySelectorAll('input[name="same_address"]');
    function updateMailingVisibility() {
        const sameAddress = document.querySelector('input[name="same_address"]:checked')?.value === 'yes';
        mailingSection.hidden = sameAddress;
        mailingSection.querySelectorAll('input, select').forEach(function (input) {
            input.required = !sameAddress;
        });
    }
    sameAddressInputs.forEach(function (input) {
        input.addEventListener('change', updateMailingVisibility);
    });
    updateMailingVisibility();
});
</script>
</body>
</html>
