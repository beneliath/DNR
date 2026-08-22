<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/profile_helpers.php';
startSecureSession();
requireLogin();

$user_id = (int) $_SESSION['user_id'];

function fetchCurrentUserProfile(mysqli $conn, $user_id) {
    $stmt = $conn->prepare(
        'SELECT id, username, role, first_name, last_name, phone, email,
                profile_picture_mime, profile_picture_sha256, profile_picture_updated_at
         FROM users
         WHERE id = ?'
    );
    if (!$stmt) {
        applicationLog('error', 'User profile schema is unavailable', ['error' => $conn->error]);
        http_response_code(503);
        exit('DNR is being upgraded. The user profile database migration is required.');
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

    $first_name = trim((string) ($_POST['first_name'] ?? ''));
    $last_name = trim((string) ($_POST['last_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $phone_country_code = trim((string) ($_POST['phone_country_code'] ?? '+1'));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $remove_profile_picture = isset($_POST['remove_profile_picture']);
    $picture = null;

    try {
        if (mb_strlen($first_name, 'UTF-8') > 100 || mb_strlen($last_name, 'UTF-8') > 100) {
            throw new InvalidArgumentException('First and last names must be 100 characters or fewer.');
        }
        if (mb_strlen($email, 'UTF-8') > 254 || ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            throw new InvalidArgumentException('Enter a valid email address.');
        }

        $phone = normalizePhoneNumber($phone_country_code, $phone, 'Phone number');
        $picture = profilePictureFromUpload($_FILES['profile_picture'] ?? []);
        if ($picture !== null && $remove_profile_picture) {
            throw new InvalidArgumentException('Choose either a new profile picture or remove the current picture.');
        }

        if ($picture !== null) {
            $stmt = $conn->prepare(
                'UPDATE users
                 SET first_name = ?, last_name = ?, phone = ?, email = ?,
                     profile_picture = ?, profile_picture_thumbnail = ?,
                     profile_picture_thumbnail_mime = ?, profile_picture_mime = ?,
                     profile_picture_sha256 = ?,
                     profile_picture_updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
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
                'sssssssssi',
                $first_name,
                $last_name,
                $phone,
                $email,
                $picture_data,
                $picture_thumbnail,
                $picture_thumbnail_mime,
                $picture_mime,
                $picture_sha256,
                $user_id
            );
        } elseif ($remove_profile_picture) {
            $stmt = $conn->prepare(
                'UPDATE users
                 SET first_name = ?, last_name = ?, phone = ?, email = ?,
                     profile_picture = NULL, profile_picture_thumbnail = NULL,
                     profile_picture_thumbnail_mime = NULL, profile_picture_mime = NULL,
                     profile_picture_sha256 = NULL,
                     profile_picture_updated_at = UTC_TIMESTAMP()
                 WHERE id = ?'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the profile update.');
            }
            $stmt->bind_param('ssssi', $first_name, $last_name, $phone, $email, $user_id);
        } else {
            $stmt = $conn->prepare(
                'UPDATE users
                 SET first_name = ?, last_name = ?, phone = ?, email = ?
                 WHERE id = ?'
            );
            if (!$stmt) {
                throw new RuntimeException('Unable to prepare the profile update.');
            }
            $stmt->bind_param('ssssi', $first_name, $last_name, $phone, $email, $user_id);
        }

        if (!$stmt->execute()) {
            throw new RuntimeException('Unable to update the user profile.');
        }
        $stmt->close();

        $_SESSION['profile_display_name'] = profileDisplayName([
            'first_name' => $first_name,
            'last_name' => $last_name,
            'username' => $user['username'],
        ]);
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
    $user['email'] = $email;
}

[$phone_country_code_value, $phone_local_value] = phoneNumberInputParts(
    $user['phone'] ?? '',
    $_POST['phone_country_code'] ?? '+1'
);
$stored_picture_version = strtotime((string) ($user['profile_picture_updated_at'] ?? '')) ?: 0;
$profile_picture_version = (string) ($_SESSION['profile_picture_version'] ?? $stored_picture_version);
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('My Profile - DNR', array (
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
<body class="profile-page">
<?php include 'templates/header.php'; ?>
<main class="container profile-container">
    <div class="page-heading"><div><h1>My Profile</h1><p class="page-intro">Manage your personal details and profile picture.</p></div></div>

    <?php if (isset($error)): ?>
        <p class="error"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php elseif (isset($_GET['updated'])): ?>
        <p class="success">Your profile was updated.</p>
    <?php endif; ?>

    <form method="post" action="profile.php" enctype="multipart/form-data" class="profile-form">
        <?php echo csrfInput(); ?>
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
                <div class="form-group">
                    <label for="email">Email address</label>
                    <input type="email" id="email" name="email" maxlength="254" autocomplete="email" value="<?php echo htmlspecialchars((string) ($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                </div>
                <div class="form-group">
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

        <div class="action-buttons profile-actions">
            <a href="engagements.php" class="cancel-button">Cancel</a>
            <button type="submit" class="save-button">Save Changes</button>
        </div>
    </form>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
