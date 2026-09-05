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
$profile_styles = $read('src/assets/css/pages/profile.css');
expectUserProfile(
    str_contains($profile_page, 'enctype="multipart/form-data"')
        && str_contains($profile_page, 'requireValidCsrfToken()')
        && str_contains($profile_page, 'normalizePhoneNumber(')
        && str_contains($profile_page, 'profilePictureFromUpload(')
        && str_contains($profile_page, 'first_name = ?')
        && str_contains($profile_page, 'last_name = ?')
        && str_contains($profile_page, 'phone = ?')
        && str_contains($profile_page, 'requestAccountEmailChange(')
        && !str_contains($profile_page, 'SET email = ?'),
    'the profile form should securely save every requested personal field and the uploaded picture.'
);
expectUserProfile(
    str_contains($profile_page, "'path' => 'assets/js/profile.min.js'")
        && str_contains($profile_page, 'assets/css/pages/profile.min.css')
        && str_contains($profile_page, 'data-profile-picture-preview')
        && str_contains($profile_page, 'data-profile-picture-input')
        && str_contains($profile_page, 'aria-live="polite"'),
    'the profile page should load an accessible immediate picture preview.'
);
expectUserProfile(
    str_contains($profile_page, '<body class="profile-page">')
        && str_contains($profile_page, '<main class="container profile-container">')
        && str_contains($profile_page, 'page-heading profile-heading')
        && preg_match('/\.profile-page \.profile-container\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);/s', $profile_styles) === 1
        && preg_match('/\.profile-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $profile_styles) === 1
        && preg_match('/\.profile-page \.profile-form\s*\{[^}]*grid-template-columns:\s*repeat\(3,\s*minmax\(0,\s*1fr\)\);[^}]*align-items:\s*stretch;/s', $profile_styles) === 1
        && preg_match('/html body\.profile-page \.profile-container > \.profile-form\s*\{[^}]*padding:\s*0 !important;[^}]*border:\s*0 !important;[^}]*background:\s*transparent !important;[^}]*box-shadow:\s*none !important;/s', $profile_styles) === 1
        && preg_match('/\.profile-page \.profile-form > \.profile-card\s*\{[^}]*height:\s*100%;/s', $profile_styles) === 1
        && preg_match('/\.profile-page \.profile-picture-card\s*\{[^}]*align-items:\s*start;/s', $profile_styles) === 1
        && preg_match('/\.profile-page \.profile-form > \.profile-actions\s*\{[^}]*grid-column:\s*1 \/ -1;/s', $profile_styles) === 1
        && str_contains($profile_page, 'class="form-group profile-email-field"')
        && str_contains($profile_page, 'class="form-group profile-phone-field"')
        && preg_match('/\.profile-page :is\(\.profile-email-field, \.profile-phone-field\)\s*\{[^}]*grid-column:\s*1 \/ -1;/s', $profile_styles) === 1
        && preg_match('/\.profile-page \.app-footer\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $profile_styles) === 1,
    'My Profile should use the Dashboard canvas and arrange its three equal-height settings panes in one row without an outer form pane.'
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
$users_styles = $read('src/assets/css/pages/users.css');
expectUserProfile(
    str_contains($users_page, 'class="users-body"')
        && str_contains($users_page, 'class="container users-page"')
        && str_contains($users_page, 'class="page-heading users-heading"')
        && preg_match('/\.users-page\s*\{[^}]*width:\s*min\(100%,\s*var\(--app-content-max\)\);[^}]*max-width:\s*var\(--app-content-max\);[^}]*padding-inline:\s*var\(--app-content-padding\);/s', $users_styles) === 1
        && preg_match('/\.users-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $users_styles) === 1
        && preg_match('/\.users-body \.app-footer\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $users_styles) === 1,
    'the Users page should use the Dashboard canvas width, heading scale, and footer alignment.'
);
expectUserProfile(
    str_contains($users_page, 'class="users-admin-grid"')
        && str_contains($users_page, 'class="users-admin-actions"')
        && strpos($users_page, 'class="page-heading users-heading"') < strpos($users_page, 'class="users-admin-grid"')
        && strpos($users_page, 'class="security-card admin-elevation-card"') < strpos($users_page, 'class="users-admin-actions"')
        && preg_match('/\.users-admin-grid,[^{]*\.users-list\s*\{[^}]*display:\s*grid;[^}]*grid-template-columns:\s*repeat\(2,\s*minmax\(0,\s*1fr\)\);/s', $users_styles) === 1
        && preg_match('/\.admin-elevation-card\s*\{[^}]*margin:\s*0 !important;/s', $users_styles) === 1
        && preg_match('/\.users-list > \.user-details\s*\{[^}]*height:\s*100%;[^}]*margin-bottom:\s*0 !important;/s', $users_styles) === 1
        && preg_match('/\.users-admin-actions\s*\{[^}]*justify-content:\s*flex-start;/s', $users_styles) === 1
        && preg_match('/@media \(max-width:\s*760px\)\s*\{\s*\.users-admin-grid\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\);/s', $users_styles) === 1
        && preg_match('/@media \(max-width:\s*1180px\)\s*\{\s*\.users-list\s*\{[^}]*grid-template-columns:\s*minmax\(0,\s*1fr\);/s', $users_styles) === 1,
    'the Users page should keep admin actions in the right column until the mobile breakpoint while allowing user cards to stack sooner.'
);
expectUserProfile(
    !str_contains($users_page, 'profile_picture_thumbnail')
        && !str_contains($users_page, 'uploadedImageDataUrl(')
        && str_contains($users_page, 'class="user-list-avatar"')
        && str_contains($users_page, 'profile_picture.php?id=')
        && str_contains($users_page, 'decodePaginationCursor(')
        && str_contains($users_page, 'LIMIT ?')
        && str_contains($users_page, 'First Page')
        && str_contains($picture_endpoint, 'profile_picture_thumbnail_size')
        && str_contains($picture_endpoint, 'profile_picture_sha256 = UNHEX(?)')
        && strpos($picture_endpoint, 'HTTP_IF_NONE_MATCH')
            < strpos($picture_endpoint, '$picture_stmt = $conn->prepare')
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
$account_security_styles = $read('src/assets/css/pages/two_factor_settings.css');
expectUserProfile(
    str_contains($styles, '.sidebar-account-link')
        && str_contains($styles, '.profile-picture-preview')
        && str_contains($styles, '.profile-field-grid')
        && str_contains($styles, '.profile-save-actions')
        && preg_match('/\.profile-actions\s*\{[^}]*justify-content:\s*space-between;/s', $styles) === 1,
    'the clickable sidebar account and responsive profile form should use shared application styling.'
);
expectUserProfile(
    str_contains($account_security_page, '<body class="two-factor-settings-page">')
        && str_contains($account_security_page, '<main class="container security-container">')
        && str_contains($account_security_page, 'assets/css/pages/two_factor_settings.min.css')
        && str_contains($account_security_page, 'page-heading two-factor-settings-heading')
        && str_contains($account_security_page, '<div class="account-security-grid">')
        && preg_match('/html body main\.container,[^{]*\{[^}]*background-color:\s*transparent\s*!important;/s', $styles) === 1
        && preg_match('/\.security-card,[^{]*\{[^}]*background:\s*var\(--surface\)\s*!important;/s', $styles) === 1
        && preg_match('/\.two-factor-settings-page \.security-container\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $account_security_styles) === 1
        && preg_match('/\.two-factor-settings-heading h1\s*\{[^}]*font-size:\s*clamp\(1\.8rem,\s*3vw,\s*2\.3rem\);/s', $account_security_styles) === 1
        && preg_match('/\.account-security-grid\s*\{[^}]*grid-template-columns:\s*repeat\(3,\s*minmax\(0,\s*1fr\)\);/s', $account_security_styles) === 1
        && preg_match('/\.two-factor-settings-page \.app-footer\s*\{[^}]*max-width:\s*var\(--app-content-max\);/s', $account_security_styles) === 1,
    'Account Security should use the Dashboard canvas, preserve intentional security-card surfaces, and arrange its settings cards in three columns.'
);

echo "User profile feature tests passed.\n";
