<?php

require_once __DIR__ . '/../src/profile_helpers.php';

function expectUserProfile($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "User profile feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static fn($path) => file_get_contents($root . '/' . $path);

expectUserProfile(
    profileDisplayName([
        'first_name' => 'Avery',
        'last_name' => 'Morgan',
        'username' => 'amorgan',
    ]) === 'Avery Morgan',
    'first and last names should form the profile display name.'
);
expectUserProfile(
    profileDisplayName(['username' => 'amorgan']) === 'amorgan'
        && profileInitials(['first_name' => 'Avery', 'last_name' => 'Morgan']) === 'AM',
    'profiles without names should fall back to the username and named profiles should have initials.'
);

$png_path = tempnam(sys_get_temp_dir(), 'dnr-profile-');
file_put_contents(
    $png_path,
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)
);
$picture = validatedProfilePictureFile($png_path);
$thumbnail_dimensions = getimagesizefromstring($picture['thumbnail_data']);
expectUserProfile(
    $picture['mime_type'] === 'image/png'
        && $picture['width'] === 1
        && $picture['height'] === 1
        && $picture['size'] > 0
        && in_array($picture['thumbnail_mime_type'], ['image/png', 'image/webp'], true)
        && ($thumbnail_dimensions[0] ?? 0) === 1
        && ($thumbnail_dimensions[1] ?? 0) === 1
        && str_starts_with(uploadedImageDataUrl($picture['thumbnail_mime_type'], $picture['thumbnail_data']), 'data:image/'),
    'a real PNG should be accepted and accompanied by a decodable embeddable thumbnail.'
);
unlink($png_path);

$invalid_path = tempnam(sys_get_temp_dir(), 'dnr-profile-invalid-');
file_put_contents($invalid_path, 'not an image');
try {
    validatedProfilePictureFile($invalid_path);
    expectUserProfile(false, 'a non-image upload should be rejected.');
} catch (InvalidArgumentException $exception) {
    expectUserProfile(true, 'the non-image upload was rejected.');
}
unlink($invalid_path);

$profile_page = $read('src/profile.php');
expectUserProfile(
    str_contains($profile_page, 'enctype="multipart/form-data"')
        && str_contains($profile_page, 'requireValidCsrfToken()')
        && str_contains($profile_page, 'normalizePhoneNumber(')
        && str_contains($profile_page, 'profilePictureFromUpload(')
        && str_contains($profile_page, 'first_name = ?')
        && str_contains($profile_page, 'last_name = ?')
        && str_contains($profile_page, 'phone = ?')
        && str_contains($profile_page, 'email = ?'),
    'the profile form should securely save every requested personal field and the uploaded picture.'
);
expectUserProfile(
    str_contains($profile_page, "'path' => 'assets/js/profile.min.js'")
        && str_contains($profile_page, 'data-profile-picture-preview')
        && str_contains($profile_page, 'data-profile-picture-input')
        && str_contains($profile_page, 'aria-live="polite"'),
    'the profile page should load an accessible immediate picture preview.'
);
expectUserProfile(
    str_contains($profile_page, 'form="profile-resend-verification-form"')
        && str_contains($profile_page, '<form id="profile-resend-verification-form" method="post" action="profile.php" hidden>')
        && str_contains($profile_page, "require_once __DIR__ . '/two_factor_helpers.php';")
        && str_contains($profile_page, "logSecurityEvent(\$conn, 'email_verification_queued'")
        && str_contains($profile_page, 'verification_queued=1')
        && str_contains($profile_page, 'verification_test_only=1')
        && str_contains($profile_page, 'No external email was sent because the development test transport is active.')
        && strpos($profile_page, 'profile-verification-button') < strpos($profile_page, 'profile-save-actions'),
    'the resend-verification action should load its audit dependency and distinguish queued SMTP mail from test-only acceptance.'
);

$profile_script = $read('src/assets/js/profile.js');
expectUserProfile(
    str_contains($profile_script, "input.addEventListener('change'")
        && str_contains($profile_script, 'new FileReader()')
        && str_contains($profile_script, 'reader.readAsDataURL(file)')
        && !str_contains($profile_script, 'URL.createObjectURL(file)')
        && str_contains($profile_script, 'Preview of selected profile picture')
        && str_contains($profile_script, 'Save changes to apply this picture.')
        && str_contains($profile_script, 'removeCheckbox.checked = false'),
    'selecting a valid picture should immediately replace the on-page preview before saving.'
);

$picture_endpoint = $read('src/profile_picture.php');
expectUserProfile(
    str_contains($picture_endpoint, 'requireLogin()')
        && str_contains($picture_endpoint, "!checkRole('admin')")
        && str_contains($picture_endpoint, "header('Cache-Control: private")
        && str_contains($picture_endpoint, "'image/jpeg', 'image/png', 'image/webp'")
        && str_contains($picture_endpoint, "header('X-Content-Type-Options: nosniff')"),
    'profile pictures should be served only to authenticated users with private, type-safe responses.'
);

$users_page = $read('src/users.php');
expectUserProfile(
    str_contains($users_page, 'profile_picture_thumbnail')
        && str_contains($users_page, 'uploadedImageDataUrl(')
        && str_contains($users_page, 'class="user-list-avatar"')
        && str_contains($users_page, 'profile_picture.php?id=')
        && str_contains($users_page, 'formatPhoneNumberForDisplay(')
        && str_contains($users_page, 'Email: ')
        && str_contains($users_page, 'Phone: '),
    'the administrator user list should show each profile picture or initials, email, and telephone number.'
);
expectUserProfile(
    str_contains($read('src/functions.php'), "'profile_picture.php',"),
    'the authenticated picture response should remain available while a temporary password is being replaced.'
);

$header = $read('src/templates/header.php');
expectUserProfile(
    str_contains($header, 'href="profile.php" class="sidebar-account-link')
        && str_contains($header, 'src="profile_picture.php?v=')
        && str_contains($header, "'profile' => ['profile.php']"),
    'the account block at the bottom of the sidebar should open and identify the active profile page.'
);

$migration = $read('migrations/20260818_add_user_profiles.sql');
expectUserProfile(
    str_contains($migration, 'first_name VARCHAR(100)')
        && str_contains($migration, 'last_name VARCHAR(100)')
        && str_contains($migration, 'phone VARCHAR(50)')
        && str_contains($migration, 'email VARCHAR(254)')
        && str_contains($migration, 'profile_picture MEDIUMBLOB'),
    'the forward migration should include the user profile fields.'
);

$styles = $read('src/assets/css/modern.css');
$account_security_page = $read('src/two_factor_settings.php');
expectUserProfile(
    str_contains($styles, '.sidebar-account-link')
        && str_contains($styles, '.profile-picture-preview')
        && str_contains($styles, '.profile-field-grid')
        && str_contains($styles, '.profile-save-actions')
        && preg_match('/\.profile-actions\s*\{[^}]*justify-content:\s*space-between;/s', $styles) === 1,
    'the clickable sidebar account and responsive profile form should use shared application styling.'
);
expectUserProfile(
    str_contains($account_security_page, '<main class="container security-container">')
        && preg_match('/html body main\.container,[^{]*\{[^}]*background-color:\s*transparent\s*!important;/s', $styles) === 1
        && preg_match('/\.security-card,[^{]*\{[^}]*background:\s*var\(--surface\)\s*!important;/s', $styles) === 1,
    'Account Security should use a transparent page root while preserving intentional security-card surfaces.'
);

echo "User profile feature tests passed.\n";
