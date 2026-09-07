<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/speaker_helpers.php';
require_once __DIR__ . '/short_link_helpers.php';
require_once __DIR__ . '/record_workspace_helpers.php';
$conn = applicationDatabaseConnection();
startSecureSession();
requireLogin();
if (!hasRole(['admin', 'editor'])) {
    http_response_code(403);
    exit('Forbidden.');
}
$return_to = safeRecordReturnUrl($_POST['return_to'] ?? $_GET['return_to'] ?? null, 'speakers.php');
$speaker_id = \Dnr\Http\RequestInput::positiveInt($_GET, 'id');
$speaker = $speaker_id === null ? null : fetchSpeaker($conn, $speaker_id);
if (isset($_GET['id']) && !$speaker) {
    http_response_code(404);
    exit('Speaker not found.');
}
$form_values = $speaker ?? ['name' => '', 'email' => '', 'phone' => '', 'bio' => '', 'version' => ''];
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    foreach (array_merge(['name', 'email', 'phone', 'bio', 'version'], array_keys(SPEAKER_URL_FIELDS)) as $field) {
        $form_values[$field] = \Dnr\Http\RequestInput::string($_POST, $field);
    }
    $input = $_POST;
    if (isset($_POST['custom_links_present'])) $input['custom_links'] = $_POST['custom_links'] ?? [];
    if (array_key_exists('custom_links', $input)) {
        $form_values['custom_links'] = is_array($input['custom_links']) ? $input['custom_links'] : [];
    }
    try {
        $photo = speakerPhotoFromUpload(is_array($_FILES['speaker_photo'] ?? null) ? $_FILES['speaker_photo'] : []);
        $conn->begin_transaction();
        $saved_id = saveSpeaker($conn, $input, $speaker_id, \Dnr\Http\RequestInput::positiveInt($_POST, 'version'), $photo, isset($_POST['remove_speaker_photo']));
        if (!empty($input['custom_links'])) ensureSpeakerCustomShortLinks($conn, $saved_id);
        $conn->commit();
        $_SESSION['speaker_message'] = $speaker_id === null ? 'Speaker added.' : 'Speaker updated.';
        header('Location: ' . recordUrlWithQuery('view_speaker.php?id=' . $saved_id, ['return_to' => $return_to]));
        exit();
    } catch (Throwable $exception) {
        $conn->rollback();
        $error_message = $exception instanceof InvalidArgumentException
            ? $exception->getMessage() : 'Unable to save the speaker. Please try again.';
        if (!$exception instanceof InvalidArgumentException) {
            applicationLog('error', 'Unable to save speaker', ['error' => $exception->getMessage()]);
        }
    }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone_country_code = \Dnr\Http\RequestInput::string($_POST, 'phone_country_code', applicationDefaultPhoneCountryCode());
    [, $phone_local_value] = phoneNumberInputParts($form_values['phone'], $phone_country_code);
} else {
    [$phone_country_code, $phone_local_value] = phoneNumberInputParts($form_values['phone']);
}
$page_title = $speaker_id === null ? 'Add Speaker' : 'Edit Speaker';
$cancel_url = $return_to;
$photo_url = $speaker_id === null ? 'data:image/svg+xml;base64,' . base64_encode(speakerInitialsSvg($form_values))
    : 'speaker_photo.php?id=' . $speaker_id . '&size=full&v=' . (int) ($speaker['version'] ?? 0);
$custom_links = array_values(array_filter($form_values['custom_links'] ?? [], 'is_array'));
if (!$custom_links) $custom_links = [['key' => '', 'label' => '', 'url' => '']];
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle($page_title), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/speakers.min.css'], 'scripts' => [['path' => 'assets/js/contact-photo.min.js', 'defer' => true], ['path' => 'assets/js/speaker-links.min.js', 'defer' => true]]]); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container" role="main">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="speakers.php">Speakers</a><span aria-hidden="true">/</span><span><?php echo $page_title; ?></span></nav>
    <div class="page-heading form-page-heading"><div><h1><?php echo $page_title; ?></h1><p class="page-intro">Keep the speaker’s name and contact details together. Changes appear on all associated presentations.</p></div></div>
    <?php if ($error_message !== ''): ?><p class="error" role="alert"><?php echo htmlspecialchars($error_message, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <p class="required-fields-note"><span aria-hidden="true">*</span> Required fields</p>
    <form method="post" enctype="multipart/form-data" action="<?php echo htmlspecialchars('edit_speaker.php' . ($speaker_id === null ? '' : '?id=' . $speaker_id), ENT_QUOTES, 'UTF-8'); ?>">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="version" value="<?php echo htmlspecialchars((string) $form_values['version'], ENT_QUOTES, 'UTF-8'); ?>">
        <section class="form-section">
            <h2>Speaker Details</h2>
            <div class="speaker-details-row">
                <div class="form-field"><label for="speaker_name" class="required">Name</label><input type="text" id="speaker_name" name="name" maxlength="255" autocomplete="name" required value="<?php echo htmlspecialchars($form_values['name'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="form-field"><label for="speaker_email" class="required">Email Address</label><input type="email" id="speaker_email" name="email" maxlength="254" autocomplete="email" required value="<?php echo htmlspecialchars($form_values['email'], ENT_QUOTES, 'UTF-8'); ?>"></div>
                <div class="form-group">
                    <label for="speaker_phone">Phone Number</label>
                    <div class="phone-input-group" data-phone-input-group>
                        <?php echo phoneCountryPicker('phone_country_code', $phone_country_code); ?>
                        <input type="tel" id="speaker_phone" name="phone" maxlength="64" required value="<?php echo htmlspecialchars($phone_local_value, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(111) 111-1111" autocomplete="tel-national" inputmode="tel" data-phone-number>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label for="speaker_bio">Bio</label>
                <textarea name="bio" id="speaker_bio" rows="6" placeholder="Add a biography for this speaker."><?php echo htmlspecialchars($form_values['bio'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
        </section>
        <section class="form-section" aria-labelledby="speaker-links-heading">
            <h2 id="speaker-links-heading">Links</h2>
            <div class="speaker-links-grid">
                <?php foreach (SPEAKER_URL_FIELDS as $field => $label): ?>
                    <div class="form-group">
                        <label for="speaker_<?php echo $field; ?>"><?php echo $label; ?></label>
                        <input type="url" id="speaker_<?php echo $field; ?>" name="<?php echo $field; ?>" maxlength="<?php echo SPEAKER_URL_MAX_LENGTH; ?>" placeholder="https://example.com" value="<?php echo htmlspecialchars($form_values[$field] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="form-section speaker-custom-links" aria-labelledby="speaker-custom-links-heading" data-speaker-custom-links data-max-links="<?php echo SPEAKER_CUSTOM_LINK_MAX_COUNT; ?>">
            <h2 id="speaker-custom-links-heading">Custom Links</h2>
            <p class="field-help" id="speaker-custom-links-help">Add named links for resources, videos, social profiles, or other pages. Saving creates a unique link and QR code for each associated presentation.</p>
            <p class="field-help">Published links keep their names, destinations, and statistics when you edit or remove a profile link. Manage a published destination from its Statistics page under Presentations.</p>
            <input type="hidden" name="custom_links_present" value="1">
            <div data-custom-link-rows>
                <?php foreach ($custom_links as $custom_index => $custom_link): ?>
                    <?php include __DIR__ . '/templates/speaker_custom_link_row.php'; ?>
                <?php endforeach; ?>
            </div>
            <template data-custom-link-template>
                <?php $custom_index = '__INDEX__'; $custom_link = []; include __DIR__ . '/templates/speaker_custom_link_row.php'; ?>
            </template>
            <button type="button" class="button-secondary" data-add-custom-link>Add Custom Link</button>
            <p class="field-help" role="status" aria-live="polite" data-custom-link-status></p>
        </section>
        <div class="form-group contact-photo-field">
            <div class="contact-photo-preview"><img src="<?php echo htmlspecialchars($photo_url, ENT_QUOTES, 'UTF-8'); ?>" alt="Current speaker photo" data-contact-photo-preview></div>
            <div>
                <label for="speaker_photo">Speaker Photo</label>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo SPEAKER_PHOTO_MAX_BYTES; ?>">
                <input type="file" id="speaker_photo" name="speaker_photo" accept="image/jpeg,image/png,image/webp" data-max-bytes="<?php echo SPEAKER_PHOTO_MAX_BYTES; ?>" data-photo-label="speaker" data-contact-photo-input>
                <p class="field-help">JPEG, PNG, or WebP; maximum 5 MB.</p>
                <p class="contact-photo-preview-status" hidden aria-live="polite" data-contact-photo-preview-status></p>
                <?php if (!empty($speaker['photo_mime'])): ?><label class="contact-photo-remove"><input type="checkbox" name="remove_speaker_photo" value="1" <?php echo isset($_POST['remove_speaker_photo']) ? 'checked' : ''; ?> data-remove-contact-photo> Remove current photo</label><?php endif; ?>
            </div>
        </div>
        <div class="form-actions speaker-form-actions"><button type="submit" class="save-button">Save speaker</button><a class="button button-secondary" href="<?php echo htmlspecialchars($cancel_url, ENT_QUOTES, 'UTF-8'); ?>">Cancel</a></div>
    </form>
</div>
<?php include 'templates/footer.php'; ?>
</body>
</html>
