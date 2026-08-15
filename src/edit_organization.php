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

    if ($organization_name === '') {
        $error = true;
        $errorMessages[] = "Organization name is required.";
    } elseif ($website_url !== '' && !filter_var($website_url, FILTER_VALIDATE_URL)) {
        $error = true;
        $errorMessages[] = "Please provide a valid website URL.";
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
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Organization - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css?v=0.0.3.1">
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
            font-size: 16px;
            height: 44px;
            box-sizing: border-box;
        }
        .back-button {
            background-color: var(--button-neutral-color);
        }
        .save-button {
            background-color: var(--button-save-color);
        }
        .action-button:hover {
            background-color: var(--button-hover-color);
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <?php if (isset($error) && $error && !empty($errorMessages)) echo "<p class='error'>" . implode("<br>", array_map('htmlspecialchars', $errorMessages)) . "</p>"; ?>

    <h2>Edit Organization</h2>
    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $org_id); ?>">
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
            <input type="text" name="phone" value="<?php echo htmlspecialchars($organization['phone']); ?>">
        </div>

        <div class="form-group">
            <label>Fax</label>
            <input type="text" name="fax" value="<?php echo htmlspecialchars($organization['fax']); ?>">
        </div>

        <div class="address-section">
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

        <div class="address-section">
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
</body>
</html>
