<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/profile_helpers.php';
include 'two_factor_helpers.php';
include 'notification_helpers.php';
startSecureSession();
requireAdmin();

// Fetch the user ID from the URL parameter
$user_id = \Dnr\Http\RequestInput::positiveInt($_GET, 'id');
if ($user_id !== null) {

    // Fetch user details from the database
    $stmt = $conn->prepare(
        "SELECT id, username, role, first_name, last_name, phone, email, email_verified_at,
                profile_picture_mime, profile_picture_updated_at,
                task_digest_enabled, task_digest_time, task_digest_days
         FROM users WHERE id = ?"
    );
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

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

    $username = \Dnr\Http\RequestInput::string($_POST, 'username');
    $role = \Dnr\Http\RequestInput::string($_POST, 'role');
    $first_name = \Dnr\Http\RequestInput::string($_POST, 'first_name');
    $last_name = \Dnr\Http\RequestInput::string($_POST, 'last_name');
    $phone = \Dnr\Http\RequestInput::string($_POST, 'phone');
    $phone_country_code = \Dnr\Http\RequestInput::string($_POST, 'phone_country_code', applicationDefaultPhoneCountryCode());
    $remove_profile_picture = isset($_POST['remove_profile_picture']);
    $picture = null;
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
        if ($username === '' || mb_strlen($username, 'UTF-8') > 50) {
            throw new InvalidArgumentException('Username is required and must be 50 characters or fewer.');
        }
        if (!in_array($role, $valid_roles, true)) {
            throw new InvalidArgumentException('Invalid role selected.');
        }
        if (mb_strlen($first_name, 'UTF-8') > 100 || mb_strlen($last_name, 'UTF-8') > 100) {
            throw new InvalidArgumentException('First and last names must be 100 characters or fewer.');
        }
        $phone = normalizePhoneNumber($phone_country_code, $phone, 'Phone number');
        $picture = profilePictureFromUpload($_FILES['profile_picture'] ?? []);
        if ($picture !== null && $remove_profile_picture) {
            throw new InvalidArgumentException('Choose either a new profile picture or remove the current picture.');
        }
        // Disabled schedule controls are omitted by the browser; keep the saved schedule.
        if ($task_digest_enabled) {
            $task_digest_time = taskDigestDeliveryTimeFromInput(
                $_POST['task_digest_time'] ?? null
            );
            $task_digest_days = taskDigestDaysFromInput(
                $_POST['task_digest_days'] ?? null
            );
        }
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    }

    if (!isset($error)) {
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
                 SET username = ?, role = ?, first_name = ?, last_name = ?, phone = ?,
                     task_digest_enabled = ?, task_digest_time = ?, task_digest_days = ?,
                     auth_version = auth_version + 1
                 WHERE id = ?'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the user update.');
            }
            $stmt->bind_param(
                'sssssisii',
                $username,
                $role,
                $first_name,
                $last_name,
                $phone,
                $task_digest_enabled,
                $task_digest_time,
                $task_digest_days,
                $user_id
            );
            if (!$stmt->execute() || $stmt->affected_rows !== 1) {
                throw new RuntimeException('Unable to update the user.');
            }
            $stmt->close();
            if ($picture !== null) {
                $conn->execute_query(
                    'UPDATE users SET profile_picture = ?, profile_picture_thumbnail = ?,
                        profile_picture_thumbnail_mime = ?, profile_picture_mime = ?,
                        profile_picture_sha256 = ?, profile_picture_updated_at = UTC_TIMESTAMP()
                     WHERE id = ?',
                    [$picture['data'], $picture['thumbnail_data'], $picture['thumbnail_mime_type'],
                        $picture['mime_type'], $picture['sha256'], $user_id]
                );
            } elseif ($remove_profile_picture) {
                $conn->execute_query(
                    'UPDATE users SET profile_picture = NULL, profile_picture_thumbnail = NULL,
                        profile_picture_thumbnail_mime = NULL, profile_picture_mime = NULL,
                        profile_picture_sha256 = NULL, profile_picture_updated_at = UTC_TIMESTAMP()
                     WHERE id = ?',
                    [$user_id]
                );
            }
            if (!logSecurityEvent($conn, 'user_profile_updated', $user_id, (int) $_SESSION['user_id'])) {
                throw new RuntimeException('Unable to audit the user update.');
            }
            $conn->commit();
            header("Location: users.php");
            exit();
        } catch (Throwable $exception) {
            $conn->rollback();
            applicationLog('error', 'Unable to update user details', ['error' => $exception->getMessage()]);
            $error = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'Unable to update user details. The username may already exist.';
        }
    }
    $user['username'] = $username;
    $user['role'] = $role;
    $user['first_name'] = $first_name;
    $user['last_name'] = $last_name;
    $user['phone'] = $phone;
    $user['task_digest_enabled'] = $task_digest_enabled;
    $user['task_digest_time'] = $task_digest_time;
    $user['task_digest_days'] = $task_digest_days;
}

[$phone_country_code_value, $phone_local_value] = phoneNumberInputParts(
    $user['phone'] ?? '',
    $phone_country_code ?? applicationDefaultPhoneCountryCode()
);
$profile_picture_version = (string) (strtotime((string) ($user['profile_picture_updated_at'] ?? '')) ?: 0);
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
<div class="container" role="main">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="users.php">Users</a><span aria-hidden="true">/</span><span>Edit User</span></nav>
    <div class="page-heading form-page-heading"><div><h1>Edit User</h1><p class="page-intro">Manage this user's profile, account access, and daily work digest settings.</p></div></div>

    <?php if (isset($error)) echo "<p class='error'>" . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . "</p>"; ?>

    <form method="post" action="edit_user.php?id=<?php echo (int) $user['id']; ?>" enctype="multipart/form-data">
        <?php echo csrfInput(); ?>
        <section class="form-section" aria-labelledby="personal-details-heading">
            <h2 id="personal-details-heading">Personal Details</h2>
            <div class="profile-field-grid">
                <div class="form-group">
                    <label for="first_name">First name</label>
                    <input type="text" id="first_name" name="first_name" maxlength="100" autocomplete="given-name" value="<?php echo htmlspecialchars((string) ($user['first_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group">
                    <label for="last_name">Last name</label>
                    <input type="text" id="last_name" name="last_name" maxlength="100" autocomplete="family-name" value="<?php echo htmlspecialchars((string) ($user['last_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group">
                    <label for="email">Email address</label>
                    <input type="email" id="email" readonly value="<?php echo htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <p class="field-help">The user can change their recovery email in My Profile after confirming their password and authenticator code.</p>
                </div>
                <div class="form-group">
                    <label for="phone">Phone number</label>
                    <div class="phone-input-group" data-phone-input-group>
                        <?php echo phoneCountryPicker('phone_country_code', $phone_country_code_value, 'Phone country code'); ?>
                        <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($phone_local_value, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="tel-national" inputmode="tel" data-phone-number>
                    </div>
                </div>
            </div>
        </section>
        <section class="form-section profile-picture-card" aria-labelledby="profile-picture-heading">
            <div class="profile-picture-preview">
                <img src="profile_picture.php?id=<?php echo (int) $user['id']; ?>&amp;size=full&amp;v=<?php echo rawurlencode($profile_picture_version); ?>" alt="Current profile picture" data-profile-picture-preview>
            </div>
            <div class="profile-picture-controls">
                <h2 id="profile-picture-heading">Profile Picture</h2>
                <label for="profile_picture">Choose a new picture</label>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo PROFILE_PICTURE_MAX_BYTES; ?>">
                <input type="file" id="profile_picture" name="profile_picture" accept="image/jpeg,image/png,image/webp" data-max-bytes="<?php echo PROFILE_PICTURE_MAX_BYTES; ?>" data-profile-picture-input>
                <p class="field-help">JPEG, PNG, or WebP. Maximum file size: 5 MB.</p>
                <p class="profile-picture-preview-status" hidden aria-live="polite" data-profile-picture-preview-status></p>
                <?php if (!empty($user['profile_picture_mime'])): ?>
                    <label class="profile-picture-remove"><input type="checkbox" name="remove_profile_picture" value="1" data-remove-profile-picture> Remove current picture</label>
                <?php endif; ?>
            </div>
        </section>
        <div class="profile-field-grid">
            <div class="form-group"><label for="username">Username</label><input type="text" id="username" name="username" autocomplete="username" value="<?php echo htmlspecialchars($user['username']); ?>" required></div>
            <div class="form-group"><label for="role">Role</label><select id="role" name="role" required>
                <?php foreach (\Dnr\Domain\ReferenceData::userRoles() as $available_role): ?>
                    <option value="<?php echo htmlspecialchars($available_role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $user['role'] === $available_role ? 'selected' : ''; ?>><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($available_role), ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select></div>
        </div>
        <section class="form-section" aria-labelledby="digest-settings-heading">
            <h2 id="digest-settings-heading">Daily Work Digest</h2>
            <label class="profile-notification-option">
                <input type="checkbox" name="task_digest_enabled" value="1" data-task-digest-enabled
                    <?php echo !empty($user['task_digest_enabled']) ? 'checked' : ''; ?>>
                <span>
                    <strong>Enable daily work digest</strong>
                    <small>Delivery requires an active account with a verified email address<?php echo empty($user['email_verified_at']) ? '; this user’s email is not currently verified' : ''; ?></small>
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
