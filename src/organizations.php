<?php
// We assume session_start() is already called earlier (e.g., in index.php or header.php)
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
    $mailing_address = $conn->real_escape_string($_POST['mailing_address']);
    $physical_address = $conn->real_escape_string($_POST['physical_address']);

    // Insert new organization into the database
    $sql = "INSERT INTO organizations (organization_name, notes, affiliation, distinctives, website_url, phone, fax, email, mailing_address, physical_address)
            VALUES ('$organization_name', '$notes', '$affiliation', '$distinctives', '$website_url', '$phone', '$fax', '$email', '$mailing_address', '$physical_address')";

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
        <label>Mailing Address: <input type="text" name="mailing_address"></label><br>
        <label>Physical Address: <input type="text" name="physical_address"></label><br>
        <input type="submit" name="save_org" value="Save Organization">
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>

