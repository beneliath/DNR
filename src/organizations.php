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
    $email = $conn->real_escape_string($_POST['email']);
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
    $sql = "INSERT INTO organizations (organization_name, notes, affiliation, distinctives, website_url, phone, fax, email, mailing_address_line_1, mailing_address_line_2, mailing_city, mailing_state, mailing_zipcode, mailing_country, physical_address_line_1, physical_address_line_2, physical_city, physical_state, physical_zipcode, physical_country)
            VALUES ('$organization_name', '$notes', '$affiliation', '$distinctives', '$website_url', '$phone', '$fax', '$email', '$mailing_address_line_1', '$mailing_address_line_2', '$mailing_city', '$mailing_state', '$mailing_zipcode', '$mailing_country', '$physical_address_line_1', '$physical_address_line_2', '$physical_city', '$physical_state', '$physical_zipcode', '$physical_country')";

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
</head>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <h1>Organizations</h1>
    <?php if (isset($message)) echo "<p class='success'>$message</p>"; ?>
    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>
    <h2>Add Organization</h2>
    <form method="post" action="organizations.php">
        <label>Organization Name: <input type="text" name="organization_name" required></label><br>
        <label>Notes:<br>
            <textarea name="notes" rows="3" cols="50"></textarea>
        </label><br>
        <label>Affiliation: <input type="text" name="affiliation"></label><br>
        <label>Distinctives: <input type="text" name="distinctives"></label><br>
        <label>Website URL: <input type="url" name="website_url"></label><br>
        <label>Phone: <input type="text" name="phone"></label><br>
        <label>Fax: <input type="text" name="fax"></label><br>
        <label>Email: <input type="email" name="email"></label><br>
        <label>Mailing Address Line 1: <input type="text" name="mailing_address_line_1" required></label><br>
        <label>Mailing Address Line 2: <input type="text" name="mailing_address_line_2"></label><br>
        <label>Mailing City: <input type="text" name="mailing_city" required></label><br>
        <label>Mailing State: <input type="text" name="mailing_state" required></label><br>
        <label>Mailing Zipcode: <input type="text" name="mailing_zipcode" required></label><br>
        <label>Mailing Country: <input type="text" name="mailing_country" required></label><br>
        <label>Physical Address Line 1: <input type="text" name="physical_address_line_1" required></label><br>
        <label>Physical Address Line 2: <input type="text" name="physical_address_line_2"></label><br>
        <label>Physical City: <input type="text" name="physical_city" required></label><br>
        <label>Physical State: <input type="text" name="physical_state" required></label><br>
        <label>Physical Zipcode: <input type="text" name="physical_zipcode" required></label><br>
        <label>Physical Country: <input type="text" name="physical_country" required></label><br>
        <input type="submit" name="save_org" value="Save Organization">
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>

