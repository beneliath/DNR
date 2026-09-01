<?php
require_once __DIR__ . '/bootstrap.php';
include 'two_factor_helpers.php';
include 'notification_helpers.php';
startSecureSession();
requireAdmin();

// Fetch the user ID from the URL parameter
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $user_id = (int) $_GET['id'];

    // Fetch user details from the database
    $stmt = $conn->prepare(
        "SELECT id, username, role, email, email_verified_at,
                task_digest_enabled, task_digest_time, task_digest_days
         FROM users WHERE id = ?"
    );
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
    $task_digest_enabled = ($_POST['task_digest_enabled'] ?? '') === '1' ? 1 : 0;
    $task_digest_time = taskDigestDeliveryTimeFromInput(
        taskDigestDeliveryTimeInputValue($user['task_digest_time'] ?? null)
    );
    $task_digest_days = (int) ($user['task_digest_days'] ?? TASK_DIGEST_WEEKDAYS);
    if ($task_digest_days < 1 || $task_digest_days > TASK_DIGEST_EVERY_DAY) {
        $task_digest_days = TASK_DIGEST_WEEKDAYS;
    }
    try {
        $task_digest_time = taskDigestDeliveryTimeFromInput(
            $_POST['task_digest_time'] ?? null
        );
        $task_digest_days = taskDigestDaysFromInput(
            $_POST['task_digest_days'] ?? null
        );
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    }

    if (isset($error)) {
        // Keep the submitted values below so the administrator can correct them.
        $user['username'] = $username;
        $user['role'] = $role;
        $user['task_digest_enabled'] = $task_digest_enabled;
        $user['task_digest_time'] = $task_digest_time;
        $user['task_digest_days'] = $task_digest_days;
    } elseif ($username === '' || mb_strlen($username, 'UTF-8') > 50) {
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
                 SET username = ?, role = ?,
                     task_digest_enabled = ?, task_digest_time = ?, task_digest_days = ?,
                     auth_version = auth_version + 1
                 WHERE id = ?'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the user update.');
            }
            $stmt->bind_param(
                'ssisii',
                $username,
                $role,
                $task_digest_enabled,
                $task_digest_time,
                $task_digest_days,
                $user_id
            );
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
            $user['task_digest_enabled'] = $task_digest_enabled;
            $user['task_digest_time'] = $task_digest_time;
            $user['task_digest_days'] = $task_digest_days;
        }
    }
}

$task_digest_time_value = taskDigestDeliveryTimeInputValue(
    $user['task_digest_time'] ?? null
);
$task_digest_days_value = (int) ($user['task_digest_days'] ?? TASK_DIGEST_WEEKDAYS);
if ($task_digest_days_value < 1 || $task_digest_days_value > TASK_DIGEST_EVERY_DAY) {
    $task_digest_days_value = TASK_DIGEST_WEEKDAYS;
}
$task_digest_day_options = [
    1 => ['short' => 'M', 'label' => 'Monday'],
    2 => ['short' => 'T', 'label' => 'Tuesday'],
    4 => ['short' => 'W', 'label' => 'Wednesday'],
    8 => ['short' => 'Th', 'label' => 'Thursday'],
    16 => ['short' => 'F', 'label' => 'Friday'],
    32 => ['short' => 'Sa', 'label' => 'Saturday'],
    64 => ['short' => 'Su', 'label' => 'Sunday'],
];
?>

<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Edit User'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
  ),
  'scripts' =>
  array (
    0 =>
    array (
      'path' => 'assets/js/profile.min.js',
    ),
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="users.php">Users</a><span aria-hidden="true">/</span><span>Edit User</span></nav>
    <div class="page-heading form-page-heading"><div><h1>Edit User</h1><p class="page-intro">Change account access and daily work digest settings.</p></div></div>

    <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</p>"; ?>

    <form method="post" action="edit_user.php?id=<?php echo $user['id']; ?>">
        <?php echo csrfInput(); ?>
        <div class="form-group"><label for="username">Username</label><input type="text" id="username" name="username" autocomplete="username" value="<?php echo htmlspecialchars($user['username']); ?>" required></div>
        <div class="form-group"><label for="role">Role</label><select id="role" name="role" required>
            <?php foreach (\Dnr\Domain\ReferenceData::userRoles() as $available_role): ?>
                <option value="<?php echo htmlspecialchars($available_role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $user['role'] === $available_role ? 'selected' : ''; ?>><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($available_role), ENT_QUOTES, 'UTF-8'); ?></option>
            <?php endforeach; ?>
        </select></div>
        <section class="form-section" aria-labelledby="digest-settings-heading">
            <h2 id="digest-settings-heading">Daily Work Digest</h2>
            <label class="profile-notification-option">
                <input type="checkbox" name="task_digest_enabled" value="1" data-task-digest-enabled
                    <?php echo !empty($user['task_digest_enabled']) ? 'checked' : ''; ?>>
                <span>
                    <strong>Enable daily work digest</strong>
                    <small>Delivery requires an active account with a verified email address<?php echo empty($user['email_verified_at']) ? '; this user’s email is not currently verified' : ''; ?>.</small>
                </span>
            </label>
            <div class="profile-notification-schedule" data-task-digest-schedule>
                <div class="profile-notification-time">
                    <label for="task_digest_time">Delivery time</label>
                    <input type="time" id="task_digest_time" name="task_digest_time"
                        value="<?php echo htmlspecialchars($task_digest_time_value, ENT_QUOTES, 'UTF-8'); ?>"
                        step="60" required>
                    <small>Uses <?php echo htmlspecialchars(applicationTimezoneName(), ENT_QUOTES, 'UTF-8'); ?> time.</small>
                </div>
                <fieldset class="profile-notification-days">
                    <legend>Delivery days</legend>
                    <div class="profile-notification-presets" aria-label="Delivery day presets">
                        <button type="button" class="button-secondary" data-task-digest-days="31">Weekdays</button>
                        <button type="button" class="button-secondary" data-task-digest-days="96">Weekends</button>
                        <button type="button" class="button-secondary" data-task-digest-days="127">Every day</button>
                    </div>
                    <div class="profile-notification-day-options">
                        <?php foreach ($task_digest_day_options as $day_value => $day_option): ?>
                            <label title="<?php echo htmlspecialchars($day_option['label'], ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="checkbox" name="task_digest_days[]"
                                    value="<?php echo $day_value; ?>"
                                    <?php echo ($task_digest_days_value & $day_value) !== 0 ? 'checked' : ''; ?>>
                                <span><?php echo htmlspecialchars($day_option['short'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
            </div>
        </section>
        <div class="action-buttons"><a href="users.php" class="cancel-button">Cancel</a><input type="submit" value="Save changes" class="save-button"></div>
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
