<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/engagement_email_helpers.php';
startSecureSession();
requireLogin();

$userRole = (string) ($_SESSION['role'] ?? '');
if (!in_array($userRole, ['admin', 'editor'], true)) {
    http_response_code(403);
    exit('Forbidden.');
}
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

$engagementId = \Dnr\Http\RequestInput::positiveInt(
    $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET,
    'id'
);
if ($engagementId === null) {
    header('Location: engagements.php');
    exit();
}

$stmt = $conn->prepare(
    'SELECT engagement.*, organization.organization_name,
            organization.is_deleted AS organization_deleted
     FROM engagements engagement
     INNER JOIN organizations organization ON organization.id = engagement.organization_id
     WHERE engagement.id = ?'
);
if (!$stmt) {
    abortApplication(503, 'The engagement email composer is temporarily unavailable.');
}
$stmt->bind_param('i', $engagementId);
$stmt->execute();
$engagement = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$engagement) {
    header('Location: engagements.php');
    exit();
}
if (!empty($engagement['is_deleted']) || !empty($engagement['organization_deleted'])) {
    http_response_code(409);
    exit('Email can be sent only for an active engagement and organization.');
}

try {
    $contacts = fetchEngagementContacts($conn, $engagementId);
    $presentationStmt = $conn->prepare(
        'SELECT topic_title, presentation_date, presentation_time,
                speaker_name, duration_minutes
         FROM presentations
         WHERE engagement_id = ? AND is_archived = 0
         ORDER BY presentation_date, presentation_time, id'
    );
    if (!$presentationStmt) {
        throw new RuntimeException('Unable to prepare the presentation schedule.');
    }
    $presentationStmt->bind_param('i', $engagementId);
    $presentationStmt->execute();
    $presentations = $presentationStmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $presentationStmt->close();
    $templates = engagementEmailTemplates($engagement, $presentations);
} catch (Throwable $exception) {
    abortApplication(503, 'The engagement email composer is temporarily unavailable.', [
        'engagement_id' => $engagementId,
        'error' => $exception->getMessage(),
    ]);
}

$templateKey = is_scalar($_POST['template_key'] ?? $_GET['template'] ?? null)
    ? trim((string) ($_POST['template_key'] ?? $_GET['template']))
    : 'booking_confirmation';
if (!isset($templates[$templateKey])) {
    $templateKey = 'booking_confirmation';
}
$selectedContactIds = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_array($_POST['contact_ids'] ?? null)) {
    foreach ($_POST['contact_ids'] as $contactId) {
        if (is_scalar($contactId) && ctype_digit(trim((string) $contactId))) {
            $selectedContactIds[(int) $contactId] = true;
        }
    }
} else {
    $suggestedRoles = $templates[$templateKey]['suggested_roles'];
    foreach ($contacts as $contact) {
        if (array_intersect($suggestedRoles, (array) ($contact['engagement_contact_roles'] ?? []))) {
            $selectedContactIds[(int) $contact['id']] = true;
        }
    }
}
$subject = is_scalar($_POST['subject'] ?? null)
    ? (string) $_POST['subject']
    : $templates[$templateKey]['subject'];
$body = is_scalar($_POST['body'] ?? null)
    ? (string) $_POST['body']
    : $templates[$templateKey]['body'];
$includeEventBrief = isset($_POST['include_event_brief']);
$error = '';
$mailTransport = strtolower(trim((string) (getenv('DNR_MAIL_TRANSPORT') ?: 'disabled')));
$deliveryAvailable = in_array($mailTransport, ['smtp', 'log'], true);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    try {
        if (!$deliveryAvailable) {
            throw new DomainException(
                'Email delivery is unavailable. Ask an administrator to check the mail setup.'
            );
        }
        $contactIds = normalizeEngagementEmailContactIds($_POST['contact_ids'] ?? null);
        $messageId = queueEngagementEmail(
            $conn,
            $engagement,
            $presentations,
            $contactIds,
            $templateKey,
            $subject,
            $body,
            $includeEventBrief,
            (int) $_SESSION['user_id'],
            (string) ($_SESSION['username'] ?? '')
        );
        $_SESSION['engagement_email_message'] = $mailTransport === 'log'
            ? 'The message was accepted by the development mail transport.'
            : 'The message was queued for delivery.';
        header('Location: outbound_mail.php?id=' . $messageId);
        exit();
    } catch (InvalidArgumentException | DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to queue engagement correspondence', [
            'engagement_id' => $engagementId,
            'error' => $exception->getMessage(),
        ]);
        $error = 'The message could not be queued. Check the mail configuration and try again.';
    }
}

$contactsWithEmail = array_values(array_filter(
    $contacts,
    static fn(array $contact): bool => filter_var(
        trim((string) ($contact['contact_email'] ?? '')),
        FILTER_VALIDATE_EMAIL
    ) !== false
));
$safeBrief = engagementEmailSafeEventBrief($engagement, $presentations);
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Send Engagement Email'), [
    'styles' => [
        'assets/css/style.min.css',
        'assets/css/modern.min.css',
        'assets/css/pages/engagement_email.min.css',
    ],
    'scripts' => ['assets/js/engagement-email.min.js'],
]); ?>
<body class="compose-engagement-email-body">
<?php include 'templates/header.php'; ?>
<main class="container engagement-email-page compose-engagement-email-page">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="engagements.php">Engagements</a><span aria-hidden="true">/</span>
        <a href="view_engagement.php?id=<?php echo $engagementId; ?>">Engagement Details</a><span aria-hidden="true">/</span>
        <span>Send Email</span>
    </nav>
    <header class="page-heading compose-engagement-email-heading">
        <div>
            <p class="eyebrow">Outbound Correspondence</p>
            <h1>Send an Engagement Email</h1>
            <p class="page-intro"><?php echo htmlspecialchars(engagementEmailEventLabel($engagement), ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string) $engagement['organization_name'], ENT_QUOTES, 'UTF-8'); ?></p>
        </div>
        <a href="view_engagement.php?id=<?php echo $engagementId; ?>#correspondence" class="button-secondary">Cancel</a>
    </header>

    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if (!$deliveryAvailable): ?>
        <p class="warning" role="status">Email delivery is not available. The form can be reviewed, but a message cannot be queued until an administrator enables mail.</p>
    <?php endif; ?>
    <?php if ($contactsWithEmail === []): ?>
        <p class="warning" role="status">This engagement has no assigned contacts with an email address. Add an email to an assigned event contact before sending correspondence.</p>
    <?php endif; ?>

    <form method="post" action="compose_engagement_email.php" class="engagement-email-form" data-engagement-email-form>
        <?php echo csrfInput(); ?>
        <input type="hidden" name="id" value="<?php echo $engagementId; ?>">

        <section class="email-compose-card">
            <div class="email-section-heading">
                <div><span>01</span><h2>Choose a Template</h2></div>
                <p>Templates provide a starting point; subject and message remain editable.</p>
            </div>
            <label for="template_key">Message template</label>
            <select name="template_key" id="template_key" data-email-template>
                <?php foreach ($templates as $key => $template): ?>
                    <option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $templateKey === $key ? ' selected' : ''; ?>><?php echo htmlspecialchars($template['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                <?php endforeach; ?>
            </select>
        </section>

        <section class="email-compose-card">
            <div class="email-section-heading">
                <div><span>02</span><h2>Select Recipients</h2></div>
                <p>Each unique address receives a separate message; recipients are never exposed to one another.</p>
            </div>
            <div class="recipient-shortcuts" aria-label="Recipient selection shortcuts">
                <?php foreach (engagementContactRoles() as $role => $label): ?>
                    <button type="button" class="button-secondary" data-select-recipient-role="<?php echo htmlspecialchars($role, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></button>
                <?php endforeach; ?>
                <button type="button" class="button-secondary" data-select-all-recipients>All</button>
                <button type="button" class="button-secondary" data-clear-recipients>Clear</button>
            </div>
            <fieldset class="recipient-list">
                <legend class="visually-hidden">Event contacts</legend>
                <?php foreach ($contacts as $contact): ?>
                    <?php
                    $contactId = (int) $contact['id'];
                    $contactName = trim((string) $contact['contact_first_name'] . ' ' . (string) $contact['contact_last_name']);
                    $contactEmail = trim((string) ($contact['contact_email'] ?? ''));
                    $contactEmailAvailable = filter_var($contactEmail, FILTER_VALIDATE_EMAIL) !== false;
                    $contactRoles = (array) ($contact['engagement_contact_roles'] ?? []);
                    ?>
                    <label class="recipient-option<?php echo !$contactEmailAvailable ? ' is-unavailable' : ''; ?>">
                        <input type="checkbox" name="contact_ids[]" value="<?php echo $contactId; ?>"
                               data-email-recipient data-contact-roles="<?php echo htmlspecialchars(implode(' ', $contactRoles), ENT_QUOTES, 'UTF-8'); ?>"
                               <?php echo isset($selectedContactIds[$contactId]) && $contactEmailAvailable ? 'checked' : ''; ?>
                               <?php echo !$contactEmailAvailable ? 'disabled' : ''; ?>>
                        <span class="recipient-copy">
                            <strong><?php echo htmlspecialchars($contactName !== '' ? $contactName : 'Unnamed contact', ENT_QUOTES, 'UTF-8'); ?></strong>
                            <small><?php echo htmlspecialchars($contactEmailAvailable ? $contactEmail : 'No valid email address', ENT_QUOTES, 'UTF-8'); ?></small>
                            <span class="recipient-role-list">
                                <?php foreach ($contactRoles as $role): ?><span><?php echo htmlspecialchars(engagementContactRoleLabel($role), ENT_QUOTES, 'UTF-8'); ?></span><?php endforeach; ?>
                            </span>
                        </span>
                    </label>
                <?php endforeach; ?>
                <?php if ($contacts === []): ?><p>No event contacts are assigned.</p><?php endif; ?>
            </fieldset>
            <p class="recipient-count" data-recipient-count role="status" aria-live="polite"></p>
        </section>

        <section class="email-compose-card">
            <div class="email-section-heading">
                <div><span>03</span><h2>Write the Message</h2></div>
                <p>The engagement routing marker is required and will be appended automatically if removed.</p>
            </div>
            <div class="form-field">
                <label for="subject">Subject <span class="required" aria-hidden="true">*</span></label>
                <input type="text" name="subject" id="subject" maxlength="255" required value="<?php echo htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'); ?>" data-email-subject>
            </div>
            <div class="form-field">
                <label for="body">Plain-text message <span class="required" aria-hidden="true">*</span></label>
                <textarea name="body" id="body" rows="14" maxlength="100000" required data-email-body><?php echo htmlspecialchars($body, ENT_QUOTES, 'UTF-8'); ?></textarea>
            </div>
            <label class="email-brief-option">
                <input type="checkbox" name="include_event_brief" value="1"<?php echo $includeEventBrief ? ' checked' : ''; ?>>
                <span><strong>Append the share-safe event brief</strong><small>Includes schedule, venue, description, and presentations. It excludes Chron, internal notes, compensation, and financial information.</small></span>
            </label>
            <details class="email-brief-preview">
                <summary>Preview Event Brief</summary>
                <pre><?php echo htmlspecialchars($safeBrief, ENT_QUOTES, 'UTF-8'); ?></pre>
            </details>
        </section>

        <div class="email-compose-actions">
            <a href="view_engagement.php?id=<?php echo $engagementId; ?>#correspondence" class="button-secondary">Cancel</a>
            <button type="submit" class="save-button"<?php echo !$deliveryAvailable || $contactsWithEmail === [] ? ' disabled' : ''; ?> data-confirm="Queue this message for delivery to the selected contacts?">Queue Email</button>
        </div>
    </form>
</main>
<script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>" type="application/json" id="engagement-email-template-data"><?php echo json_encode($templates, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<?php include 'templates/footer.php'; ?>
</body>
</html>
