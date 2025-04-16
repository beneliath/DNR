<?php
session_start();
include 'config.php';
include 'functions.php';
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
    $error = false;
    $errorMessages = array();

    // Sanitize user input
    $organization_name = $conn->real_escape_string($_POST['organization_name']);
    
    // Check if organization name already exists (excluding current organization)
    $check_sql = "SELECT id FROM organizations WHERE organization_name = '$organization_name' AND id != $org_id";
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

        // Update organization in the database
        $sql = "UPDATE organizations SET 
                organization_name = '$organization_name',
                notes = '$notes',
                affiliation = '$affiliation',
                distinctives = '$distinctives',
                website_url = '$website_url',
                phone = '$phone',
                fax = '$fax',
                mailing_address_line_1 = '$mailing_address_line_1',
                mailing_address_line_2 = '$mailing_address_line_2',
                mailing_city = '$mailing_city',
                mailing_state = '$mailing_state',
                mailing_zipcode = '$mailing_zipcode',
                mailing_country = '$mailing_country',
                physical_address_line_1 = '$physical_address_line_1',
                physical_address_line_2 = '$physical_address_line_2',
                physical_city = '$physical_city',
                physical_state = '$physical_state',
                physical_zipcode = '$physical_zipcode',
                physical_country = '$physical_country'
                WHERE id = $org_id";

        if ($conn->query($sql) === TRUE) {
            $_SESSION['success_message'] = "Organization updated successfully.";
            header("Location: view_organization.php?id=$org_id");
            exit();
        } else {
            $error = true;
            $errorMessages[] = "Error updating organization: " . $conn->error;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Edit Organization - DNR</title>
    <link rel="stylesheet" href="assets/css/style.css">
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
        .action-button {
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            color: white;
        }
        .back-button {
            background-color: #666;
        }
        .save-button {
            background-color: #4CAF50;
        }
        .action-button:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <?php if (isset($error) && $error && !empty($errorMessages)) echo "<p class='error'>" . implode("<br>", $errorMessages) . "</p>"; ?>
    
    <h2>Edit Organization</h2>
    <form method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF'] . '?id=' . $org_id); ?>">
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
            <a href="view_organization.php?id=<?php echo $org_id; ?>" class="action-button back-button">Cancel</a>
            <input type="submit" value="Save Changes" class="action-button save-button">
        </div>
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html> 