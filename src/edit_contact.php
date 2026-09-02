<?php
require_once __DIR__ . '/bootstrap.php';
include 'contact_photo_helpers.php';
include 'chron_log_helpers.php';
startSecureSession();
requireLogin();

$user_role = $_SESSION['role'] ?? '';
if ($user_role !== 'admin' && $user_role !== 'editor') {
    header('Location: contacts.php');
    exit();
}

$contact_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$contact_id) {
    header('Location: contacts.php');
    exit();
}

$contact_stmt = $conn->prepare(
    "SELECT c.id, c.organization_id, c.contact_first_name, c.contact_last_name,
            c.contact_role, c.contact_role_other, c.contact_email, c.contact_phone,
            c.contact_notes, c.contact_photo_mime, c.contact_photo_sha256,
            c.contact_photo_updated_at, c.created_at, c.updated_at, c.is_deleted
     FROM contacts c
     LEFT JOIN organizations o ON o.id = c.organization_id
     WHERE c.id = ? AND c.is_deleted = 0
       AND (o.id IS NULL OR o.is_deleted = 0)"
);
if (!$contact_stmt) {
    abortApplication(503, 'The contact is temporarily unavailable.', ['error' => $conn->error]);
}

$contact_stmt->bind_param('i', $contact_id);
$contact_stmt->execute();
$contact_result = $contact_stmt->get_result();
if ($contact_result->num_rows === 0) {
    $contact_stmt->close();
    header('Location: contacts.php');
    exit();
}

$contact = $contact_result->fetch_assoc();
$contact_stmt->close();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['chron_action'])) {
    requireValidCsrfToken();
    $chron_entry_id = \Dnr\Http\RequestInput::positiveInt($_POST, 'chron_entry_id');
    $chron_action = \Dnr\Http\RequestInput::enum(
        $_POST,
        'chron_action',
        ['archive', 'delete']
    );
    try {
        if (!$chron_entry_id) {
            throw new InvalidArgumentException('Select a valid Chron entry.');
        }
        if ($chron_action === 'archive') {
            archiveEntityChronLogEntry(
                $conn,
                'contact',
                $contact_id,
                $chron_entry_id,
                (int) $_SESSION['user_id']
            );
            $_SESSION['chron_action_message'] = 'Chron entry archived.';
        } elseif ($chron_action === 'delete') {
            if ($user_role !== 'admin') {
                http_response_code(403);
                exit('Forbidden.');
            }
            requireRecentAdminElevation('edit_contact.php?id=' . $contact_id . '#chron-log');
            deleteEntityChronLogEntry($conn, 'contact', $contact_id, $chron_entry_id);
            $_SESSION['chron_action_message'] = 'Chron entry permanently deleted.';
        } else {
            throw new InvalidArgumentException('Invalid Chron action.');
        }
    } catch (Throwable $exception) {
        if (!$exception instanceof InvalidArgumentException) {
            applicationLog('error', 'Contact Chron action failed', [
                'contact_id' => $contact_id,
                'chron_entry_id' => $chron_entry_id,
                'chron_action' => $chron_action,
                'error' => $exception->getMessage(),
            ]);
        }
        $_SESSION['chron_action_error'] = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'Unable to update the Chron log. Please try again.';
    }
    header('Location: edit_contact.php?id=' . $contact_id . '#chron-log');
    exit();
}

$error_messages = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST'
    && (isset($_POST['save_contact']) || isset($_POST['save_and_add_chron']))) {
    requireValidCsrfToken();

    $normalized_contact = \Dnr\Domain\ContactInput::normalize($_POST);
    $organization_id = $normalized_contact['data']['organization_id'];
    $contact_first_name = (string) $normalized_contact['data']['contact_first_name'];
    $contact_last_name = (string) $normalized_contact['data']['contact_last_name'];
    $contact_role = (string) $normalized_contact['data']['contact_role'];
    $contact_role_other = (string) $normalized_contact['data']['contact_role_other'];
    $contact_email = (string) $normalized_contact['data']['contact_email'];
    $contact_email_confirm = (string) $normalized_contact['data']['contact_email_confirm'];
    $contact_phone = (string) $normalized_contact['data']['contact_phone'];
    $contact_notes = (string) $normalized_contact['data']['contact_notes'];
    $contact_phone_country_code = (string) $normalized_contact['data']['contact_phone_country_code'];
    $error_messages = $normalized_contact['errors'];
    $submitted_chron_entries = [];
    $submitted_chron_versions = [];
    $new_chron_entry = '';
    try {
        $submitted_chron_entries = normalizeSubmittedChronLogEntries(
            $_POST['chron_entries'] ?? null
        );
        $submitted_chron_versions = normalizeSubmittedChronLogVersions(
            $_POST['chron_entry_versions'] ?? null
        );
        if (!is_scalar($_POST['new_chron_entry'] ?? '')) {
            throw new InvalidArgumentException('Enter a valid Chron entry.');
        }
        $new_chron_entry = trim((string) ($_POST['new_chron_entry'] ?? ''));
        if (isset($_POST['save_and_add_chron']) && $new_chron_entry === '') {
            throw new InvalidArgumentException('Enter a Chron entry before adding it.');
        }
        if ($new_chron_entry !== '') {
            $new_chron_entry = normalizeChronLogEntryText($new_chron_entry);
        }
    } catch (InvalidArgumentException $exception) {
        $error_messages[] = $exception->getMessage();
    }
    $remove_contact_photo = isset($_POST['remove_contact_photo']);
    $contact_photo = null;
    try {
        $contact_photo = contactPhotoFromUpload($_FILES['contact_photo'] ?? []);
        if ($contact_photo !== null && $remove_contact_photo) {
            throw new InvalidArgumentException('Choose either a new contact photo or remove the current photo.');
        }
    } catch (InvalidArgumentException $exception) {
        $error_messages[] = $exception->getMessage();
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to read contact photo upload', ['error' => $exception->getMessage()]);
        $error_messages[] = 'The contact photo could not be uploaded. Try again.';
    }

    if (!$error_messages) {
        $conn->begin_transaction();
        try {
            $submitted_version = is_scalar($_POST['contact_version'] ?? null)
                ? trim((string) $_POST['contact_version'])
                : '';
            $lock_stmt = $conn->prepare(
                'SELECT organization_id, updated_at
                 FROM contacts
                 WHERE id = ? AND is_deleted = 0
                 FOR UPDATE'
            );
            if (!$lock_stmt) {
                throw new RuntimeException('Unable to lock the contact.');
            }
            $lock_stmt->bind_param('i', $contact_id);
            $lock_stmt->execute();
            $locked_contact = $lock_stmt->get_result()->fetch_assoc();
            $lock_stmt->close();
            if (!$locked_contact) {
                throw new InvalidArgumentException('That contact is no longer active.');
            }
            if ($submitted_version === ''
                || !hash_equals((string) $locked_contact['updated_at'], $submitted_version)
            ) {
                throw new InvalidArgumentException(
                    'This contact changed after you opened it. Reload the page before saving so newer changes are not overwritten.'
                );
            }
            if ($organization_id !== null) {
                requireActiveOrganization($conn, $organization_id, true);
            }
            $locked_organization_id = $locked_contact['organization_id'] !== null
                ? (int) $locked_contact['organization_id']
                : null;
            if ($locked_organization_id !== $organization_id) {
                $touch_engagements_stmt = $conn->prepare(
                    'UPDATE engagements engagement
                     INNER JOIN engagement_contacts event_contact
                             ON event_contact.engagement_id = engagement.id
                     SET engagement.updated_at = CURRENT_TIMESTAMP(6)
                     WHERE event_contact.contact_id = ?'
                );
                if (!$touch_engagements_stmt) {
                    throw new RuntimeException('Unable to prepare the event contact changes.');
                }
                $touch_engagements_stmt->bind_param('i', $contact_id);
                if (!$touch_engagements_stmt->execute()) {
                    $touch_engagements_stmt->close();
                    throw new RuntimeException('Unable to update related engagements.');
                }
                $touch_engagements_stmt->close();

                $clear_assignments_stmt = $conn->prepare(
                    'DELETE FROM engagement_contacts WHERE contact_id = ?'
                );
                if (!$clear_assignments_stmt) {
                    throw new RuntimeException('Unable to prepare the event contact changes.');
                }
                $clear_assignments_stmt->bind_param('i', $contact_id);
                if (!$clear_assignments_stmt->execute()) {
                    $clear_assignments_stmt->close();
                    throw new RuntimeException('Unable to clear the prior event contact assignments.');
                }
                $clear_assignments_stmt->close();
            }
            if ($contact_photo !== null) {
                $update_stmt = $conn->prepare(
                    "UPDATE contacts SET
                        organization_id = ?,
                        contact_first_name = ?,
                        contact_last_name = ?,
                        contact_role = ?,
                        contact_role_other = ?,
                        contact_email = ?,
                        contact_phone = ?,
                        contact_notes = ?,
                        contact_photo = ?,
                        contact_photo_thumbnail = ?,
                        contact_photo_thumbnail_mime = ?,
                        contact_photo_mime = ?,
                        contact_photo_sha256 = ?,
                        contact_photo_updated_at = UTC_TIMESTAMP()
                     WHERE id = ? AND is_deleted = 0"
                );
            } elseif ($remove_contact_photo) {
                $update_stmt = $conn->prepare(
                    "UPDATE contacts SET
                        organization_id = ?,
                        contact_first_name = ?,
                        contact_last_name = ?,
                        contact_role = ?,
                        contact_role_other = ?,
                        contact_email = ?,
                        contact_phone = ?,
                        contact_notes = ?,
                        contact_photo = NULL,
                        contact_photo_thumbnail = NULL,
                        contact_photo_thumbnail_mime = NULL,
                        contact_photo_mime = NULL,
                        contact_photo_sha256 = NULL,
                        contact_photo_updated_at = UTC_TIMESTAMP()
                     WHERE id = ? AND is_deleted = 0"
                );
            } else {
                $update_stmt = $conn->prepare(
                    "UPDATE contacts SET
                        organization_id = ?,
                        contact_first_name = ?,
                        contact_last_name = ?,
                        contact_role = ?,
                        contact_role_other = ?,
                        contact_email = ?,
                        contact_phone = ?,
                        contact_notes = ?
                     WHERE id = ? AND is_deleted = 0"
                );
            }
            if (!$update_stmt) {
                throw new RuntimeException('Unable to prepare the contact update.');
            }
            if ($contact_photo !== null) {
                $contact_photo_data = $contact_photo['data'];
                $contact_photo_thumbnail = $contact_photo['thumbnail_data'];
                $contact_photo_thumbnail_mime = $contact_photo['thumbnail_mime_type'];
                $contact_photo_mime = $contact_photo['mime_type'];
                $contact_photo_sha256 = $contact_photo['sha256'];
                $update_stmt->bind_param(
                    'issssssssssssi',
                    $organization_id,
                    $contact_first_name,
                    $contact_last_name,
                    $contact_role,
                    $contact_role_other,
                    $contact_email,
                    $contact_phone,
                    $contact_notes,
                    $contact_photo_data,
                    $contact_photo_thumbnail,
                    $contact_photo_thumbnail_mime,
                    $contact_photo_mime,
                    $contact_photo_sha256,
                    $contact_id
                );
            } else {
                $update_stmt->bind_param(
                    'isssssssi',
                    $organization_id,
                    $contact_first_name,
                    $contact_last_name,
                    $contact_role,
                    $contact_role_other,
                    $contact_email,
                    $contact_phone,
                    $contact_notes,
                    $contact_id
                );
            }
            if (!$update_stmt->execute() || $update_stmt->affected_rows > 1) {
                throw new RuntimeException('Unable to update the contact.');
            }
            $update_stmt->close();
            $current_user_id = (int) $_SESSION['user_id'];
            updateEntityChronLogEntries(
                $conn,
                'contact',
                $contact_id,
                $submitted_chron_entries,
                $submitted_chron_versions,
                $current_user_id
            );
            if ($new_chron_entry !== '') {
                insertEntityChronLogEntry(
                    $conn,
                    'contact',
                    $contact_id,
                    $new_chron_entry,
                    $current_user_id,
                    (string) ($_SESSION['username'] ?? '')
                );
            }
            $conn->commit();
            $_SESSION['success_message'] = 'Contact updated successfully.';
            header("Location: view_contact.php?id={$contact_id}");
            exit();
        } catch (Throwable $exception) {
            $conn->rollback();
            if (!$exception instanceof InvalidArgumentException) {
                applicationLog('error', 'Contact update failed', [
                    'contact_id' => $contact_id,
                    'error' => $exception->getMessage(),
                ]);
            }
            $error_messages[] = $exception instanceof InvalidArgumentException
                ? $exception->getMessage()
                : 'Unable to update the contact.';
        }
    }

    $contact['organization_id'] = $organization_id;
    $contact['contact_first_name'] = $contact_first_name;
    $contact['contact_last_name'] = $contact_last_name;
    $contact['contact_role'] = $contact_role;
    $contact['contact_role_other'] = $contact_role_other;
    $contact['contact_email'] = $contact_email;
    $contact['contact_phone'] = $contact_phone;
    $contact['contact_notes'] = $contact_notes;
    if (isset($submitted_version)) {
        $contact['updated_at'] = $submitted_version;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contact_phone_country_code_value = trim($_POST['contact_phone_country_code'] ?? applicationDefaultPhoneCountryCode());
    [, $contact_phone_local_value] = phoneNumberInputParts(
        $_POST['contact_phone'] ?? '',
        $contact_phone_country_code_value
    );
} else {
    [$contact_phone_country_code_value, $contact_phone_local_value] = phoneNumberInputParts(
        $contact['contact_phone'] ?? ''
    );
}

$organizations_result = $conn->query(
    'SELECT id, organization_name FROM organizations WHERE is_deleted = 0 ORDER BY organization_name'
);
if (!$organizations_result) {
    abortApplication(503, 'Organizations are temporarily unavailable.', ['error' => $conn->error]);
}

$cancel_url = ($_GET['from'] ?? '') === 'view'
    ? "view_contact.php?id={$contact_id}"
    : 'contacts.php';
$contact_photo_version = strtotime((string) ($contact['contact_photo_updated_at'] ?? '')) ?: 0;
$chron_action_message = (string) ($_SESSION['chron_action_message'] ?? '');
$chron_action_error = (string) ($_SESSION['chron_action_error'] ?? '');
unset($_SESSION['chron_action_message'], $_SESSION['chron_action_error']);

try {
    $chron_page_size = 20;
    $chron_entry_count = countEntityChronLogEntries($conn, 'contact', $contact_id);
    $chron_total_pages = max(1, (int) ceil($chron_entry_count / $chron_page_size));
    $chron_page = min(
        filter_input(INPUT_GET, 'chron_page', FILTER_VALIDATE_INT) ?: 1,
        $chron_total_pages
    );
    $chron_entries = fetchEntityChronLogEntries(
        $conn,
        'contact',
        $contact_id,
        false,
        $chron_page_size,
        ($chron_page - 1) * $chron_page_size
    );
    $archived_chron_count = countEntityChronLogEntries($conn, 'contact', $contact_id, 1);
} catch (Throwable $exception) {
    abortApplication(503, 'The contact Chron log is temporarily unavailable.', [
        'contact_id' => $contact_id,
        'error' => $exception->getMessage(),
    ]);
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Edit Contact'), array (
  'styles' =>
  array (
    0 => 'assets/css/style.min.css',
    1 => 'assets/css/modern.min.css',
    2 => 'assets/css/pages/edit_contact.min.css',
  ),
  'scripts' =>
  array (
    0 =>
    array (
      'path' => 'assets/js/contact-photo.min.js',
    ),
  ),
)); ?>
<body>
<?php include 'templates/header.php'; ?>
<div class="container">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="contacts.php">Contacts</a><span aria-hidden="true">/</span><span>Edit Contact</span></nav>
    <div class="page-heading form-page-heading"><div><h1>Edit Contact</h1><p class="page-intro">Update contact information, role, and organization.</p></div></div>

    <?php if ($error_messages): ?>
        <p class="error"><?php echo implode(
            '<br>',
            array_map(fn($message) => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'), $error_messages)
        ); ?></p>
    <?php endif; ?>
    <?php if ($chron_action_message !== ''): ?>
        <p class="success"><?php echo htmlspecialchars($chron_action_message, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>
    <?php if ($chron_action_error !== ''): ?>
        <p class="error"><?php echo htmlspecialchars($chron_action_error, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php endif; ?>

    <form id="contact-edit-form" method="post" enctype="multipart/form-data" action="edit_contact.php?id=<?php echo $contact_id; ?><?php echo ($_GET['from'] ?? '') === 'view' ? '&amp;from=view' : ''; ?>" data-chron-form>
        <?php echo csrfInput(); ?>
        <input type="hidden" name="contact_version" value="<?php echo htmlspecialchars((string) $contact['updated_at'], ENT_QUOTES, 'UTF-8'); ?>">

        <div class="form-group">
            <label for="organization_id">Organization</label>
            <select name="organization_id" id="organization_id">
                <option value="" <?php echo $contact['organization_id'] === null ? 'selected' : ''; ?>>No organization</option>
                <?php while ($organization = $organizations_result->fetch_assoc()): ?>
                    <option value="<?php echo (int) $organization['id']; ?>" <?php echo (int) $contact['organization_id'] === (int) $organization['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($organization['organization_name'], ENT_QUOTES, 'UTF-8'); ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="contact_first_name" class="required">First Name</label>
                <input type="text" name="contact_first_name" id="contact_first_name" required autocomplete="given-name" value="<?php echo htmlspecialchars($contact['contact_first_name'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label for="contact_last_name" class="required">Last Name</label>
                <input type="text" name="contact_last_name" id="contact_last_name" required autocomplete="family-name" value="<?php echo htmlspecialchars($contact['contact_last_name'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="contact_role" class="required">Role</label>
                <select name="contact_role" id="contact_role" required>
                    <?php foreach (\Dnr\Domain\ReferenceData::contactRoles() as $role): ?>
                        <option value="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $contact['contact_role'] === $role ? 'selected' : ''; ?>><?php echo htmlspecialchars(\Dnr\Domain\ReferenceData::label($role), ENT_QUOTES, 'UTF-8'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" id="other_role_group">
                <label for="contact_role_other">Other Role Description</label>
                <input type="text" name="contact_role_other" id="contact_role_other" value="<?php echo htmlspecialchars($contact['contact_role_other'] ?? '', ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <div class="form-row">
            <div class="form-group">
                <label for="contact_email" class="required">Email</label>
                <input type="email" name="contact_email" id="contact_email" required value="<?php echo htmlspecialchars($contact['contact_email'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
            <div class="form-group">
                <label for="contact_email_confirm" class="required">Confirm Email</label>
                <input type="email" name="contact_email_confirm" id="contact_email_confirm" required value="<?php echo htmlspecialchars($_POST['contact_email_confirm'] ?? $contact['contact_email'], ENT_QUOTES, 'UTF-8'); ?>">
            </div>
        </div>

        <div class="form-group">
            <label for="contact_phone">Phone Number</label>
            <div class="phone-input-group" data-phone-input-group>
                <?php echo phoneCountryPicker('contact_phone_country_code', $contact_phone_country_code_value); ?>
                <input type="tel" name="contact_phone" id="contact_phone" value="<?php echo htmlspecialchars($contact_phone_local_value, ENT_QUOTES, 'UTF-8'); ?>" placeholder="(111) 111-1111" autocomplete="tel-national" inputmode="tel" data-phone-number>
            </div>
        </div>

        <div class="form-group">
            <label for="contact_notes">Notes</label>
            <textarea name="contact_notes" id="contact_notes" rows="5" placeholder="Add incidental notes about this person."><?php echo htmlspecialchars($contact['contact_notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
        </div>

        <div class="form-group contact-photo-field">
            <div class="contact-photo-preview">
                <img src="contact_photo.php?id=<?php echo $contact_id; ?>&amp;size=full&amp;v=<?php echo $contact_photo_version; ?>" alt="Current contact photo for <?php echo htmlspecialchars($contact['contact_first_name'] . ' ' . $contact['contact_last_name'], ENT_QUOTES, 'UTF-8'); ?>" data-contact-photo-preview>
            </div>
            <div>
                <label for="contact_photo">Contact Photo</label>
                <input type="hidden" name="MAX_FILE_SIZE" value="<?php echo CONTACT_PHOTO_MAX_BYTES; ?>">
                <input type="file" id="contact_photo" name="contact_photo" accept="image/jpeg,image/png,image/webp" data-max-bytes="<?php echo CONTACT_PHOTO_MAX_BYTES; ?>" data-contact-photo-input>
                <p class="field-help">JPEG, PNG, or WebP; maximum 5 MB.</p>
                <p class="contact-photo-preview-status" hidden aria-live="polite" data-contact-photo-preview-status></p>
                <?php if (!empty($contact['contact_photo_mime'])): ?>
                    <label class="contact-photo-remove"><input type="checkbox" name="remove_contact_photo" value="1" <?php echo isset($_POST['remove_contact_photo']) ? 'checked' : ''; ?> data-remove-contact-photo> Remove current photo</label>
                <?php endif; ?>
            </div>
        </div>

    </form>

    <?php
    $chron_entity_label = 'contact';
    $chron_edit_form_id = 'contact-edit-form';
    $chron_edit_url = 'edit_contact.php?id=' . $contact_id;
    $chron_restore_url = 'restore_entity_chron_entries.php?entity_type=contact&entity_id=' . $contact_id;
    include 'templates/entity_chron_log_edit_section.php';
    ?>

    <div class="action-buttons">
        <a href="<?php echo htmlspecialchars($cancel_url, ENT_QUOTES, 'UTF-8'); ?>" class="action-button cancel-button">Cancel</a>
        <button type="submit" name="save_contact" value="1" class="action-button save-button" form="contact-edit-form">Save Changes</button>
    </div>
</div>

<?php include 'templates/footer.php'; ?>
</body>
</html>
