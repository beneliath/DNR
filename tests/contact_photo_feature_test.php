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
$thumbnail_dimensions = getimagesizefromstring($photo['thumbnail_data']);
expectContactPhoto(
    $photo['mime_type'] === 'image/png'
        && $photo['width'] === 1
        && $photo['height'] === 1
        && $photo['size'] > 0
        && in_array($photo['thumbnail_mime_type'], ['image/png', 'image/webp'], true)
        && ($thumbnail_dimensions[0] ?? 0) === 1
        && ($thumbnail_dimensions[1] ?? 0) === 1,
    'a valid PNG should be accepted and accompanied by a decodable thumbnail.'
);
unlink($png_path);

$noise_path = tempnam(sys_get_temp_dir(), 'dnr-contact-photo-noise-');
$noise_image = imagecreatetruecolor(320, 320);
$noise_seed = 1234567;
for ($noise_y = 0; $noise_y < 320; $noise_y++) {
    for ($noise_x = 0; $noise_x < 320; $noise_x++) {
        $noise_seed = (int) (($noise_seed * 1103515245 + 12345) & 0x7fffffff);
        imagesetpixel($noise_image, $noise_x, $noise_y, $noise_seed & 0xffffff);
    }
}
imagepng($noise_image, $noise_path, 0);
$noisy_photo = validatedContactPhotoFile($noise_path);
unlink($noise_path);
expectContactPhoto(
    strlen($noisy_photo['thumbnail_data']) <= UPLOADED_IMAGE_THUMBNAIL_MAX_BYTES,
    'high-entropy thumbnails should stay within the database and list-payload byte cap.'
);

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
    str_contains($add_contact, "'path' => 'assets/js/contact-photo.min.js'")
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
        && !str_contains($contacts_page, 'contact_photo_thumbnail')
        && !str_contains($contacts_page, 'uploadedImageDataUrl(')
        && str_contains($photo_endpoint, 'contact_photo_thumbnail_size')
        && str_contains($photo_endpoint, 'contact_photo_sha256 = UNHEX(?)')
        && strpos($photo_endpoint, 'HTTP_IF_NONE_MATCH')
            < strpos($photo_endpoint, '$photo_stmt = $conn->prepare')
        && str_contains($view_contact, 'class="contact-details-photo"')
        && str_contains($view_contact, 'contact_photo.php?id=')
        && str_contains($view_contact, 'size=full'),
    'list avatars should use cached photo URLs while detail entries request the full image.'
);

$migration = $read('migrations/20260818_add_contact_photos.sql');
expectContactPhoto(
    str_contains($migration, 'contact_photo MEDIUMBLOB')
        && str_contains($migration, 'contact_photo_mime VARCHAR(32)'),
    'the forward migration should include contact photo storage.'
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
