<?php
// Include required files
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireAdmin();

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireValidCsrfToken();
    requireRecentAdminElevation('register.php');

    $username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
    $password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';
    $password_confirm = is_string($_POST['password_confirm'] ?? null)
        ? $_POST['password_confirm']
        : '';
    $role = is_string($_POST['role'] ?? null) ? $_POST['role'] : '';
    $valid_roles = \Dnr\Domain\ReferenceData::userRoles();

    $password_error = \Dnr\Security\PasswordPolicy::validationError($password);
    if ($username === '' || mb_strlen($username, 'UTF-8') > 50) {
        $error = "Username is required and must be 50 characters or fewer.";
    } elseif ($password_error !== null) {
        $error = $password_error;
    } elseif (!hash_equals($password, $password_confirm)) {
        $error = "Passwords do not match.";
    } elseif (!in_array($role, $valid_roles, true)) {
        $error = "Invalid role selected.";
    } else {
        $check = $conn->prepare("SELECT id FROM users WHERE username = ?");
        $check->bind_param("s", $username);
        $check->execute();
        $existing_user = $check->get_result();

        if ($existing_user->num_rows > 0) {
            $error = "Username already exists.";
        } else {
            $hashedPassword = \Dnr\Security\PasswordPolicy::hash($password);
            $stmt = $conn->prepare(
                "INSERT INTO users (username, password, must_change_password, role) VALUES (?, ?, 1, ?)"
            );
            $stmt->bind_param("sss", $username, $hashedPassword, $role);

            if ($stmt->execute()) {
                $message = "User registered successfully.";
            } else {
                $error = "Unable to create the user.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Register - DNR', array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="users.php">Users</a><span aria-hidden="true">/</span><span>New User</span></nav>
    <div class="page-heading form-page-heading"><div><h1>New User</h1><p class="page-intro">Create an account and assign its access level.</p></div></div>

    <?php if (isset($message)) echo "<p class='success'>$message</p>"; ?>
    <?php if (isset($error)) echo "<p class='error'>$error</p>"; ?>

    <form method="post" action="register.php">
        <?php echo csrfInput(); ?>
        <div class="form-group"><label for="username">Username</label><input type="text" name="username" id="username" autocomplete="username" required></div>
        <div class="form-group"><label for="password">Temporary password</label><input type="password" name="password" id="password" autocomplete="new-password" minlength="12" maxlength="72" required><p class="field-help">Use at least 12 characters and no more than 72 UTF-8 bytes. The user can change it from Account security.</p></div>
        <div class="form-group"><label for="password_confirm">Confirm temporary password</label><input type="password" name="password_confirm" id="password_confirm" autocomplete="new-password" minlength="12" maxlength="72" required></div>
        <div class="form-group"><label for="role">Role</label><select name="role" id="role" required>
            <?php foreach (\Dnr\Domain\ReferenceData::userRoles() as $available_role): ?>
                <option value="<?php echo htmlspecialchars($available_role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo ($_POST['role'] ?? '') === $available_role ? 'selected' : ''; ?>><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($available_role), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select></div>
        <div class="action-buttons create-form-actions">
            <a href="users.php" class="cancel-button">Cancel</a>
            <input type="submit" value="Create user" class="register-button">
        </div>
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
