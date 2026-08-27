<?php
require_once __DIR__ . '/bootstrap.php';
include 'two_factor_helpers.php';
startSecureSession();
requireAdmin();

// Fetch the user ID from the URL parameter
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $user_id = (int) $_GET['id'];

    // Fetch user details from the database
    $stmt = $conn->prepare("SELECT id, username, role FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();

    if (!$user) {
        // If no user is found, redirect to the users list
        header("Location: users.php");
        exit();
    }
} else {
    header("Location: users.php");
    exit();
}

// Handle the form submission for editing user
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    requireValidCsrfToken();
    requireRecentAdminElevation('edit_user.php?id=' . $user_id);

    $username = trim($_POST['username'] ?? '');
    $role = $_POST['role'] ?? '';
    $valid_roles = \Dnr\Domain\ReferenceData::userRoles();

    if ($username === '' || mb_strlen($username, 'UTF-8') > 50) {
        $error = "Username is required and must be 50 characters or fewer.";
    } elseif (!in_array($role, $valid_roles, true)) {
        $error = "Invalid role selected.";
    } else {
        $conn->begin_transaction();
        try {
            $lock_stmt = $conn->prepare(
                'SELECT id, username, role FROM users WHERE id = ? FOR UPDATE'
            );
            if (!$lock_stmt) {
                throw new RuntimeException('Unable to prepare the user update.');
            }
            $lock_stmt->bind_param('i', $user_id);
            $lock_stmt->execute();
            $locked_user = $lock_stmt->get_result()->fetch_assoc();
            $lock_stmt->close();
            if (!$locked_user) {
                throw new InvalidArgumentException('That user is no longer available.');
            }

            if ($locked_user['role'] === 'admin' && $role !== 'admin') {
                $admins_stmt = $conn->prepare(
                    "SELECT id FROM users
                     WHERE role = 'admin' AND account_status = 'active' FOR UPDATE"
                );
                if (!$admins_stmt) {
                    throw new RuntimeException('Unable to verify the administrator roster.');
                }
                $admins_stmt->execute();
                $admin_count = $admins_stmt->get_result()->num_rows;
                $admins_stmt->close();
                if ($admin_count <= 1) {
                    throw new InvalidArgumentException(applicationBrandName() . ' must retain at least one administrator.');
                }
            }

            $stmt = $conn->prepare(
                'UPDATE users
                 SET username = ?, role = ?, auth_version = auth_version + 1
                 WHERE id = ?'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the user update.');
            }
            $stmt->bind_param('ssi', $username, $role, $user_id);
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                throw new RuntimeException('Unable to update the user.');
            }
            $stmt->close();
            $conn->commit();
            header("Location: users.php");
            exit();
        } catch (Throwable $exception) {
            $conn->rollback();
            $error = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'Unable to update user details. The username may already exist.';
            $user['username'] = $username;
            $user['role'] = $role;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Edit User'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="users.php">Users</a><span aria-hidden="true">/</span><span>Edit User</span></nav>
    <div class="page-heading form-page-heading"><div><h1>Edit User</h1><p class="page-intro">Change the account username or access level.</p></div></div>

    <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</p>"; ?>

    <form method="post" action="edit_user.php?id=<?php echo $user['id']; ?>">
        <?php echo csrfInput(); ?>
        <div class="form-group"><label for="username">Username</label><input type="text" id="username" name="username" autocomplete="username" value="<?php echo htmlspecialchars($user['username']); ?>" required></div>
        <div class="form-group"><label for="role">Role</label><select id="role" name="role" required>
            <?php foreach (\Dnr\Domain\ReferenceData::userRoles() as $available_role): ?>
                <option value="<?php echo htmlspecialchars($available_role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $user['role'] === $available_role ? 'selected' : ''; ?>><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($available_role), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select></div>
        <div class="action-buttons"><a href="users.php" class="cancel-button">Cancel</a><input type="submit" value="Save changes" class="save-button"></div>
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
