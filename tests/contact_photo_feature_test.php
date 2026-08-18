<?php

require_once __DIR__ . '/../src/contact_photo_helpers.php';

function expectContactPhoto($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Contact photo feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$read = static fn($path) => file_get_contents($root . '/' . $path);

expectContactPhoto(
    contactInitials(['contact_first_name' => 'Avery', 'contact_last_name' => 'Morgan']) === 'AM'
        && contactInitials([]) === 'C'
        && str_contains(contactInitialsSvg(['contact_first_name' => 'Avery']), '>A</text>'),
    'contact avatars should use name initials with a stable fallback.'
);

$png_path = tempnam(sys_get_temp_dir(), 'dnr-contact-photo-');
file_put_contents(
    $png_path,
    base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true)
);
$photo = validatedContactPhotoFile($png_path);
expectContactPhoto(
    $photo['mime_type'] === 'image/png'
        && $photo['width'] === 1
        && $photo['height'] === 1
        && $photo['size'] > 0,
    'a valid PNG within the upload bounds should be accepted.'
);
unlink($png_path);

$invalid_path = tempnam(sys_get_temp_dir(), 'dnr-contact-photo-invalid-');
file_put_contents($invalid_path, 'not an image');
try {
    validatedContactPhotoFile($invalid_path);
    expectContactPhoto(false, 'a non-image upload should be rejected.');
} catch (InvalidArgumentException $exception) {
    expectContactPhoto(true, 'the non-image upload was rejected.');
}
unlink($invalid_path);

$add_contact = $read('src/add_contact.php');
$edit_contact = $read('src/edit_contact.php');
expectContactPhoto(
    str_contains($add_contact, 'enctype="multipart/form-data"')
        && str_contains($add_contact, 'contactPhotoFromUpload(')
        && str_contains($add_contact, 'contact_photo_updated_at')
        && str_contains($edit_contact, 'enctype="multipart/form-data"')
        && str_contains($edit_contact, 'remove_contact_photo')
        && str_contains($edit_contact, 'contactPhotoFromUpload('),
    'contact create and edit forms should securely save and remove optional photos.'
);

expectContactPhoto(
    str_contains($add_contact, 'assets/js/contact-photo.min.js?v=1.0.0')
        && str_contains($add_contact, 'data-contact-photo-preview')
        && str_contains($add_contact, 'data-contact-photo-input')
        && str_contains($edit_contact, 'data-remove-contact-photo')
        && str_contains($edit_contact, 'aria-live="polite"'),
    'new and existing contacts should immediately preview selected photos accessibly.'
);

$contact_photo_script = $read('src/assets/js/contact-photo.js');
expectContactPhoto(
    str_contains($contact_photo_script, "input.addEventListener('change'")
        && str_contains($contact_photo_script, 'new FileReader()')
        && str_contains($contact_photo_script, 'reader.readAsDataURL(file)')
        && !str_contains($contact_photo_script, 'URL.createObjectURL(file)')
        && str_contains($contact_photo_script, 'Preview of selected contact photo')
        && str_contains($contact_photo_script, 'Save changes to apply this photo.')
        && str_contains($contact_photo_script, 'removeCheckbox.checked = false'),
    'selecting a valid contact photo should immediately replace the preview before saving.'
);

$photo_endpoint = $read('src/contact_photo.php');
expectContactPhoto(
    str_contains($photo_endpoint, 'requireLogin()')
        && str_contains($photo_endpoint, "header('Cache-Control: private")
        && str_contains($photo_endpoint, "'image/jpeg', 'image/png', 'image/webp'")
        && str_contains($photo_endpoint, "header('X-Content-Type-Options: nosniff')")
        && str_contains($photo_endpoint, 'contactInitialsSvg('),
    'contact photos should use authenticated, type-safe responses with an initials fallback.'
);

$contacts_page = $read('src/contacts.php');
$view_contact = $read('src/view_contact.php');
expectContactPhoto(
    str_contains($contacts_page, 'class="contact-list-avatar"')
        && str_contains($contacts_page, 'contact_photo.php?id=')
        && str_contains($view_contact, 'class="contact-details-photo"')
        && str_contains($view_contact, 'contact_photo.php?id='),
    'contact photos should appear on contact list and detail entries.'
);

$migration = $read('migrations/20260818_add_contact_photos.sql');
$initial_schema = $read('init.sql');
expectContactPhoto(
    str_contains($migration, 'contact_photo MEDIUMBLOB')
        && str_contains($migration, 'contact_photo_mime VARCHAR(32)')
        && str_contains($initial_schema, 'contact_photo MEDIUMBLOB')
        && str_contains($initial_schema, "'20260818_add_contact_photos.sql'"),
    'fresh and upgraded databases should both include contact photo storage.'
);

$styles = $read('src/assets/css/modern.css');
expectContactPhoto(
    str_contains($styles, '.contact-list-avatar')
        && str_contains($styles, '.contact-details-photo')
        && str_contains($styles, '.contact-photo-field'),
    'contact photo controls and avatars should use shared responsive styling.'
);
expectContactPhoto(
    preg_match(
        '/span\.contact-list-avatar\s*\{[^}]*display:\s*inline-flex\s*!important;[^}]*align-items:\s*center\s*!important;[^}]*justify-content:\s*center\s*!important;[^}]*line-height:\s*1;/s',
        $styles
    ) === 1,
    'contact initials should remain centered horizontally and vertically in their circle.'
);

echo "Contact photo feature tests passed.\n";
