<?php
// Include required files
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
startSecureSession();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Migrate Passwords - DNR</title>
        <link rel="stylesheet" href="assets/css/style.min.css?v=0.0.20">
        <link rel="stylesheet" href="assets/css/modern.min.css?v=0.1.55">
    </head>
    <body>
        <h1>Migrate Legacy Passwords</h1>
        <p>This hashes any remaining plaintext passwords. Existing password hashes are left unchanged.</p>
        <form method="post" action="migrate_passwords.php">
            <?php echo csrfInput(); ?>
            <button type="submit" class="security-button">Run migration</button>
        </form>
    </body>
    </html>
    <?php
    exit();
}

requireValidCsrfToken();

// Get all users with plain text passwords
$users = $conn->query("SELECT id, password FROM users");

if ($users) {
    $updated = 0;
    $errors = 0;

    while ($user = $users->fetch_assoc()) {
        // Preserve every hash format understood by PHP, including bcrypt
        // variants and Argon hashes. Only legacy plaintext reaches rehashing.
        $password_info = password_get_info((string) $user['password']);
        if (($password_info['algo'] ?? 0) !== 0) {
            continue;
        }

        // Hash the plain text password
        $hashedPassword = password_hash($user['password'], PASSWORD_BCRYPT, ['cost' => 12]);

        // Update the user's password
        $stmt = $conn->prepare(
            "UPDATE users
             SET password = ?, auth_version = auth_version + 1, must_change_password = 1
             WHERE id = ?"
        );
        $stmt->bind_param("si", $hashedPassword, $user['id']);

        if ($stmt->execute()) {
            $updated++;
        } else {
            $errors++;
        }
    }

    echo "Migration complete:<br>";
    echo "Updated passwords: $updated<br>";
    echo "Errors: $errors<br>";
} else {
    echo "Error fetching users: " . $conn->error;
}
?>
