<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/profile_helpers.php';
require_once __DIR__ . '/email_helpers.php';
require_once __DIR__ . '/notification_helpers.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/account_email_change_helpers.php';
startSecureSession();
requireLogin();

$user_id = (int) $_SESSION['user_id'];

function fetchCurrentUserProfile(mysqli $conn, $user_id) {
    $stmt = $conn->prepare(
        'SELECT id, username, role, first_name, last_name, phone, email, pending_email, email_verified_at, auth_version, two_factor_enabled,
                task_digest_enabled, task_digest_time, task_digest_days,
                profile_picture_mime, profile_picture_sha256, profile_picture_updated_at
         FROM users
         WHERE id = ?'
    );
    if (!$stmt) {
        applicationLog('error', 'User profile schema is unavailable', ['error' => $conn->error]);
        http_response_code(503);
        exit(applicationBrandName() . ' is being upgraded. The user profile database migration is required.');
    }
    $stmt->bind_param('i', $user_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load the user profile.');
    }
    $profile = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $profile ?: null;
}

$user = fetchCurrentUserProfile($conn, $user_id);
if (!$user) {
    session_unset();
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $profile_action = is_string($_POST['action'] ?? null) ? $_POST['action'] : 'save';

    if ($profile_action === 'change_email') {
        try {
            $issued = requestAccountEmailChange(
                $conn, $user_id, (int) $_SESSION['auth_version'],
                \Dnr\Http\RequestInput::string($_POST, 'email'),
                \Dnr\Http\RequestInput::string($_POST, 'password'),
                \Dnr\Http\RequestInput::string($_POST, 'authentication_code')
            );
            header('Location: profile.php?' . (!empty($issued['queued']) ? 'verification_queued=1' : 'verification_test_only=1'));
            exit();
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            applicationLog('error', 'Unable to request account email change', ['error' => $exception->getMessage()]);
            $error = 'The email change could not be requested. Try again.';
        }
    } elseif ($profile_action === 'cancel_email_change') {
        $conn->begin_transaction();
        $cancel = $conn->prepare('UPDATE users SET pending_email = NULL WHERE id = ? AND auth_version = ?');
        $auth_version = (int) $_SESSION['auth_version'];
        $cancel->bind_param('ii', $user_id, $auth_version);
        $cancel->execute();
        $cancelled = $cancel->affected_rows === 1;
        $cancel->close();
        if ($cancelled) {
        $invalidate = $conn->prepare("UPDATE user_email_tokens SET consumed_at = UTC_TIMESTAMP()
            WHERE user_id = ? AND purpose = 'verification' AND consumed_at IS NULL");
        $invalidate->bind_param('i', $user_id);
        $invalidate->execute();
        $invalidate->close();
        }
        $conn->commit();
        header('Location: profile.php');
        exit();
    } elseif ($profile_action === 'resend_verification') {
        try {
            $email = normalizeAccountEmail($user['pending_email'] ?: ($user['email'] ?? ''));
            if (empty($user['pending_email']) && !empty($user['email_verified_at'])) {
                throw new InvalidArgumentException('Your current email address is already verified.');
            }
            $issued = issueUserEmailToken($conn, $user_id, 'verification', $email, $user_id);
            logSecurityEvent($conn, 'email_verification_queued', $user_id, $user_id);
            header(
                'Location: profile.php?'
                . (!empty($issued['queued'])
                    ? 'verification_queued=1'
                    : 'verification_test_only=1')
            );
            exit();
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            applicationLog('error', 'Unable to resend email verification', ['error' => $exception->getMessage()]);
            $error = 'The verification message could not be queued. Check the address or contact an administrator.';
        }
    } else {
    $first_name = trim((string) ($_POST['first_name'] ?? ''));
    $last_name = trim((string) ($_POST['last_name'] ?? ''));
    // Recovery destinations are changed only by the reauthenticated action above.
    $phone_country_code = trim((string) ($_POST['phone_country_code'] ?? applicationDefaultPhoneCountryCode()));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $task_digest_enabled_requested = ($_POST['task_digest_enabled'] ?? '') === '1';
    $task_digest_time = taskDigestDeliveryTimeFromInput(
        taskDigestDeliveryTimeInputValue($user['task_digest_time'] ?? null)
    );
    $task_digest_days = (int) ($user['task_digest_days'] ?? TASK_DIGEST_WEEKDAYS);
    if ($task_digest_days < 1 || $task_digest_days > TASK_DIGEST_EVERY_DAY) {
        $task_digest_days = TASK_DIGEST_WEEKDAYS;
    }
    $remove_profile_picture = isset($_POST['remove_profile_picture']);
    $picture = null;

    try {
        if (mb_strlen($first_name, 'UTF-8') > 100 || mb_strlen($last_name, 'UTF-8') > 100) {
            throw new InvalidArgumentException('First and last names must be 100 characters or fewer.');
        }
        if ($task_digest_enabled_requested && empty($user['email_verified_at'])) {
            throw new InvalidArgumentException('Verify your email address before enabling the daily work digest.');
        }
        $task_digest_enabled = $task_digest_enabled_requested ? 1 : 0;
        if (isset($_POST['task_digest_schedule_present'])) {
            $task_digest_time = taskDigestDeliveryTimeFromInput(
                $_POST['task_digest_time'] ?? null
            );
            $task_digest_days = taskDigestDaysFromInput(
                $_POST['task_digest_days'] ?? null
            );
        }

        $phone = normalizePhoneNumber($phone_country_code, $phone, 'Phone number');
        $picture = profilePictureFromUpload($_FILES['profile_picture'] ?? []);
        if ($picture !== null && $remove_profile_picture) {
            throw new InvalidArgumentException('Choose either a new profile picture or remove the current picture.');
        }

        $profile_auth_version = (int) $_SESSION['auth_version'];
        if ($picture !== null) {
            $stmt = $conn->prepare(
                'UPDATE users
                 SET first_name = ?, last_name = ?, phone = ?,
                     task_digest_enabled = ?,
                     task_digest_time = ?, task_digest_days = ?,
                     profile_picture = ?, profile_picture_thumbnail = ?,
                     profile_picture_thumbnail_mime = ?, profile_picture_mime = ?,
                     profile_picture_sha256 = ?,
                     profile_picture_updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND auth_version = ? AND account_status = \'active\''
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the profile update.');
            }
            $picture_data = $picture['data'];
            $picture_thumbnail = $picture['thumbnail_data'];
            $picture_thumbnail_mime = $picture['thumbnail_mime_type'];
            $picture_mime = $picture['mime_type'];
            $picture_sha256 = $picture['sha256'];
            $stmt->bind_param(
                'sssisisssssii',
                $first_name,
                $last_name,
                $phone,
                $task_digest_enabled,
                $task_digest_time,
                $task_digest_days,
                $picture_data,
                $picture_thumbnail,
                $picture_thumbnail_mime,
                $picture_mime,
                $picture_sha256,
                $user_id,
                $profile_auth_version
            );
        } elseif ($remove_profile_picture) {
            $stmt = $conn->prepare(
                'UPDATE users
                 SET first_name = ?, last_name = ?, phone = ?,
                     task_digest_enabled = ?,
                     task_digest_time = ?, task_digest_days = ?,
                     profile_picture = NULL, profile_picture_thumbnail = NULL,
                     profile_picture_thumbnail_mime = NULL, profile_picture_mime = NULL,
                     profile_picture_sha256 = NULL,
                     profile_picture_updated_at = UTC_TIMESTAMP()
                 WHERE id = ? AND auth_version = ? AND account_status = \'active\''
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the profile update.');
            }
            $stmt->bind_param(
                'sssisiii',
                $first_name,
                $last_name,
                $phone,
                $task_digest_enabled,
                $task_digest_time,
                $task_digest_days,
                $user_id,
                $profile_auth_version
            );
        } else {
            $stmt = $conn->prepare(
                'UPDATE users
                 SET first_name = ?, last_name = ?, phone = ?,
                     task_digest_enabled = ?,
                     task_digest_time = ?, task_digest_days = ?,
                     last_updated_at = last_updated_at
                 WHERE id = ? AND auth_version = ? AND account_status = \'active\''
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the profile update.');
            }
            $stmt->bind_param(
                'sssisiii',
                $first_name,
                $last_name,
                $phone,
                $task_digest_enabled,
                $task_digest_time,
                $task_digest_days,
                $user_id,
                $profile_auth_version
            );
        }

        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to update the user profile.');
        }
        $changed = $stmt->affected_rows;
        $stmt->close();
        if ($changed === 0) {
            $current = fetchAuthenticationUserById($conn, $user_id);
            if (!$current || (int) $current['auth_version'] !== $profile_auth_version || $current['account_status'] !== 'active') {
                throw new InvalidArgumentException('Your sign-in changed. Sign in again before saving your profile.');
            }
        }

        $_SESSION['profile_display_name'] = profileDisplayName([
            'first_name' => $first_name,
            'last_name' => $last_name,
            'username' => $user['username'],
        ]);
        $_SESSION['profile_first_name'] = $first_name;
        if ($picture !== null || $remove_profile_picture) {
            $_SESSION['profile_picture_version'] = time();
        }

        header('Location: profile.php?updated=1');
        exit();
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to update user profile', ['error' => $exception->getMessage()]);
        $error = 'Your profile could not be updated. Try again.';
    }

    $user['first_name'] = $first_name;
    $user['last_name'] = $last_name;
    $user['phone'] = $phone;
    $user['task_digest_enabled'] = $task_digest_enabled_requested ? 1 : 0;
    $user['task_digest_time'] = $task_digest_time;
    $user['task_digest_days'] = $task_digest_days;
    }
}

[$phone_country_code_value, $phone_local_value] = phoneNumberInputParts(
    $user['phone'] ?? '',
    $_POST['phone_country_code'] ?? applicationDefaultPhoneCountryCode()
);
$stored_picture_version = strtotime((string) ($user['profile_picture_updated_at'] ?? '')) ?: 0;
$profile_picture_version = (string) ($_SESSION['profile_picture_version'] ?? $stored_picture_version);
$task_digest_time_value = taskDigestDeliveryTimeInputValue($user['task_digest_time'] ?? null);
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
<?php renderPageHead(applicationPageTitle('My Profile'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/profile.min.css',
  ),
  'scripts' =>
  array (
    0 =>
    array (
      'path' => 'assets/js/profile.min.js',
    ),
  ),
)); ?>
<body class="profile-page">
<?php include 'templates/header.php'; ?>
<main class="container profile-container">
    <div class="page-heading profile-heading"><div><h1>My Profile</h1><p class="page-intro">Manage your personal details and profile picture.</p></div></div>

    <?php if (isset($error)): ?>
        <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php elseif (isset($_GET['updated'])): ?>
        <p class="success">Your profile was updated.</p>
    <?php endif; ?>
    <?php if (isset($_GET['verification_queued']) || isset($_GET['verification_sent'])): ?>
        <p class="success">A single-use verification link was queued for delivery to your email address.</p>
    <?php elseif (isset($_GET['verification_test_only'])): ?>
        <p class="error">No external email was sent because the development test transport is active. Enable the SMTP delivery worker and retry.</p>
    <?php elseif (isset($_GET['verification_failed'])): ?>
        <p class="error">Your email change was saved, but the verification message could not be delivered. Retry below or contact an administrator.</p>
    <?php endif; ?>
    <?php if (isset($_GET['digest_paused'])): ?>
        <p class="success">Your daily work digest was paused until the new email address is verified.</p>
    <?php endif; ?>

    <form method="post" action="profile.php" enctype="multipart/form-data" class="profile-form">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="action" value="save">
        <section class="profile-card profile-picture-card" aria-labelledby="profile-picture-heading">
            <div class="profile-picture-preview">
                <img src="profile_picture.php?size=full&amp;v=<?php echo rawurlencode($profile_picture_version); ?>" alt="Current profile picture" data-profile-picture-preview>
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

        <section class="profile-card" aria-labelledby="personal-details-heading">
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
                <div class="form-group profile-email-field">
                    <label for="email">Email address</label>
                    <input type="email" id="email" readonly maxlength="254" autocomplete="email" value="<?php echo htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if (!empty($user['email'])): ?><p class="field-help"><?php echo !empty($user['email_verified_at']) ? 'Verified for password recovery.' : 'Unverified. Password recovery remains unavailable until verification.'; ?></p><?php endif; ?>
                </div>
                <div class="form-group profile-phone-field">
                    <label for="phone">Phone number</label>
                    <div class="phone-input-group" data-phone-input-group>
                        <?php echo phoneCountryPicker('phone_country_code', $phone_country_code_value, 'Phone country code'); ?>
                        <input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($phone_local_value, ENT_QUOTES, 'UTF-8'); ?>" autocomplete="tel-national" inputmode="tel" placeholder="(555) 555-0123" data-phone-number>
                    </div>
                </div>
            </div>
            <div class="profile-account-meta">
                <span><strong>Username</strong><?php echo htmlspecialchars((string) $user['username'], ENT_QUOTES, 'UTF-8'); ?></span>
                <span><strong>Role</strong><?php echo htmlspecialchars(ucfirst((string) $user['role']), ENT_QUOTES, 'UTF-8'); ?></span>
            </div>
        </section>

        <section class="profile-card" aria-labelledby="notification-preferences-heading">
            <h2 id="notification-preferences-heading">Notifications</h2>
            <label class="profile-notification-option">
                <input type="checkbox" name="task_digest_enabled" value="1" data-task-digest-enabled
                    <?php echo !empty($user['task_digest_enabled']) ? 'checked' : ''; ?>
                    <?php echo empty($user['email_verified_at']) ? 'disabled' : ''; ?>>
                <span>
                    <strong>Daily work digest</strong>
                    <small>Email me on the selected days with a Dashboard-style snapshot of upcoming engagements, My Work, event readiness, and financial closeouts<?php echo in_array((string) $user['role'], ['admin', 'editor'], true) ? ', plus inbound mail awaiting review' : ''; ?>. Overdue and due-today tasks are highlighted</small>
                </span>
            </label>
            <div class="profile-notification-schedule" data-task-digest-schedule>
                <?php if (!empty($user['email_verified_at'])): ?>
                    <input type="hidden" name="task_digest_schedule_present" value="1">
                <?php endif; ?>
                <div class="profile-notification-time">
                    <label for="task_digest_time">Delivery time</label>
                    <input type="time" id="task_digest_time" name="task_digest_time"
                        value="<?php echo htmlspecialchars($task_digest_time_value, ENT_QUOTES, 'UTF-8'); ?>"
                        step="60" required
                        <?php echo empty($user['email_verified_at']) ? 'disabled' : ''; ?>>
                    <small>Uses <?php echo htmlspecialchars(applicationTimezoneName(), ENT_QUOTES, 'UTF-8'); ?> time.</small>
                </div>
                <fieldset class="profile-notification-days" <?php echo empty($user['email_verified_at']) ? 'disabled' : ''; ?>>
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
            <?php if (empty($user['email_verified_at'])): ?>
                <p class="field-help">Verify your email address to enable daily digests.</p>
            <?php else: ?>
                <p class="field-help">Delivery begins at the selected time. The mail worker may send a few minutes later.</p>
            <?php endif; ?>
        </section>

        <div class="action-buttons profile-actions">
            <?php if (!empty($user['pending_email']) || (!empty($user['email']) && empty($user['email_verified_at']))): ?>
                <button type="submit" form="profile-resend-verification-form" class="security-button profile-verification-button">Resend email verification</button>
            <?php endif; ?>
            <div class="profile-save-actions">
                <a href="engagements.php" class="cancel-button">Cancel</a>
                <button type="submit" class="save-button">Save Changes</button>
            </div>
        </div>
    </form>
    <?php if (!empty($user['pending_email']) || (!empty($user['email']) && empty($user['email_verified_at']))): ?>
        <form id="profile-resend-verification-form" method="post" action="profile.php" hidden>
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="resend_verification">
        </form>
    <?php endif; ?>
    <section class="profile-card" aria-labelledby="recovery-email-heading">
        <h2 id="recovery-email-heading">Change Recovery Email</h2>
        <p>Your current address stays in use until the new address is verified. Verification signs out all existing sessions and pauses the daily digest.</p>
        <?php if (!empty($user['pending_email'])): ?>
            <p>Awaiting verification: <strong><?php echo htmlspecialchars($user['pending_email'], ENT_QUOTES, 'UTF-8'); ?></strong></p>
            <form method="post" action="profile.php">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="action" value="cancel_email_change">
                <button type="submit" class="button-secondary">Cancel Pending Email Change</button>
            </form>
        <?php endif; ?>
        <form method="post" action="profile.php">
            <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="change_email">
            <div class="profile-field-grid">
                <div class="form-group"><label for="new-email">New email address</label><input type="email" id="new-email" name="email" maxlength="254" autocomplete="email" required></div>
                <div class="form-group"><label for="email-password">Current password</label><input type="password" id="email-password" name="password" autocomplete="current-password" maxlength="72" required></div>
                <?php if (!empty($user['two_factor_enabled'])): ?>
                    <div class="form-group"><label for="email-authentication-code">Fresh authenticator code</label><input type="text" id="email-authentication-code" name="authentication_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></div>
                <?php endif; ?>
            </div>
            <button type="submit" class="save-button">Verify New Email Address</button>
        </form>
    </section>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
