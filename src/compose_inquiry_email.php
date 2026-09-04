<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$conn = applicationDatabaseConnection();
require_once __DIR__ . '/booking_inquiry_helpers.php';
startSecureSession();
requireLogin();

if (!canManageBookingInquiries((string) ($_SESSION['role'] ?? ''))) {
    http_response_code(403);
    exit('Forbidden.');
}
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
$inquiryId = \Dnr\Http\RequestInput::positiveInt(
    $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET,
    'id'
);
$inquiry = $inquiryId ? fetchBookingInquiry($conn, $inquiryId) : null;
if (!$inquiry) {
    header('Location: inquiries.php');
    exit();
}
if (!in_array((string) $inquiry['stage'], bookingInquiryActiveStages(), true)) {
    http_response_code(409);
    exit('Email can be sent only for an active inquiry.');
}
$templates = bookingInquiryEmailTemplates($inquiry);
$templateKey = is_scalar($_POST['template_key'] ?? $_GET['template'] ?? null)
    ? trim((string) ($_POST['template_key'] ?? $_GET['template']))
    : 'initial_response';
if (!isset($templates[$templateKey])) {
    $templateKey = 'initial_response';
}
$subject = is_scalar($_POST['subject'] ?? null)
    ? (string) $_POST['subject']
    : $templates[$templateKey]['subject'];
$body = is_scalar($_POST['body'] ?? null)
    ? (string) $_POST['body']
    : $templates[$templateKey]['body'];
$error = '';
$mailTransport = strtolower(trim((string) (getenv('DNR_MAIL_TRANSPORT') ?: 'disabled')));
$deliveryAvailable = in_array($mailTransport, ['smtp', 'log'], true);
$recipientAvailable = filter_var(
    trim((string) ($inquiry['contact_email'] ?? '')),
    FILTER_VALIDATE_EMAIL
) !== false && empty($inquiry['contact_deleted']);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    try {
        if (!$deliveryAvailable) {
            throw new DomainException('Email delivery is unavailable. Ask an administrator to check the mail setup.');
        }
        $messageId = queueBookingInquiryEmail(
            $conn,
            $inquiry,
            $templateKey,
            $subject,
            $body,
            (int) $_SESSION['user_id'],
            (string) $_SESSION['username']
        );
        $_SESSION['inquiry_action_message'] = ($mailTransport === 'log'
            ? 'The message was accepted by the development mail transport.'
            : 'The message was queued for delivery.') . ' Outbound message #' . $messageId . '.';
        header('Location: view_inquiry.php?id=' . $inquiryId . '#correspondence');
        exit();
    } catch (InvalidArgumentException | DomainException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to queue inquiry correspondence', [
            'inquiry_id' => $inquiryId,
            'error' => $exception->getMessage(),
        ]);
        $error = 'The message could not be queued. Check the mail setup and try again.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Send Inquiry Email'), [
    'styles' => [
        'assets/css/style.min.css', 'assets/css/modern.min.css',
        'assets/css/pages/engagement_email.min.css',
        'assets/css/pages/booking_inquiries.min.css',
    ],
    'scripts' => ['assets/js/engagement-email.min.js'],
]); ?>
<body class="inquiry-workflow-body inquiry-form-body">
<?php include 'templates/header.php'; ?>
<main class="container engagement-email-page inquiry-email-page">
    <nav class="breadcrumb" aria-label="Breadcrumb"><a href="inquiries.php">Booking Pipeline</a><span aria-hidden="true">/</span><a href="view_inquiry.php?id=<?php echo $inquiryId; ?>">Inquiry</a><span aria-hidden="true">/</span><span>Send Email</span></nav>
    <header class="page-heading"><div><p class="eyebrow">Outbound Correspondence</p><h1>Send an Inquiry Email</h1><p class="page-intro"><?php echo htmlspecialchars($inquiry['title'], ENT_QUOTES, 'UTF-8'); ?> · <?php echo htmlspecialchars((string) ($inquiry['organization_name'] ?: 'Organization not identified'), ENT_QUOTES, 'UTF-8'); ?></p></div><a href="view_inquiry.php?id=<?php echo $inquiryId; ?>#correspondence" class="button-secondary">Cancel</a></header>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if (!$deliveryAvailable): ?><p class="warning">Email delivery is not available. The message can be reviewed but not queued until mail is enabled.</p><?php endif; ?>
    <?php if (!$recipientAvailable): ?><p class="warning">Add a valid email address to the inquiry’s primary contact before sending correspondence.</p><?php endif; ?>
    <form method="post" action="compose_inquiry_email.php" class="engagement-email-form" data-engagement-email-form>
        <?php echo csrfInput(); ?><input type="hidden" name="id" value="<?php echo $inquiryId; ?>">
        <section class="email-compose-card"><div class="email-section-heading"><div><span>01</span><h2>Choose a Template</h2></div><p>Start with a common inquiry response, then edit it freely.</p></div><label for="template_key">Message Template</label><select name="template_key" id="template_key" data-email-template><?php foreach ($templates as $key => $template): ?><option value="<?php echo htmlspecialchars($key, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $key === $templateKey ? ' selected' : ''; ?>><?php echo htmlspecialchars($template['label'], ENT_QUOTES, 'UTF-8'); ?></option><?php endforeach; ?></select></section>
        <section class="email-compose-card"><div class="email-section-heading"><div><span>02</span><h2>Recipient</h2></div><p>Inquiry correspondence goes to the primary contact and is recorded in the Inquiry, Contact, and Organization Chron logs where applicable.</p></div><div class="inquiry-email-recipient"><strong><?php echo htmlspecialchars((string) ($inquiry['contact_name'] ?: 'Primary contact not selected'), ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo htmlspecialchars((string) ($inquiry['contact_email'] ?: 'No valid email address'), ENT_QUOTES, 'UTF-8'); ?></span></div></section>
        <section class="email-compose-card"><div class="email-section-heading"><div><span>03</span><h2>Write the Message</h2></div><p>The signed inquiry marker is appended automatically so replies return to this inquiry.</p></div><div class="form-field"><label for="subject">Subject <span class="required" aria-hidden="true">*</span></label><input type="text" name="subject" id="subject" maxlength="255" required value="<?php echo htmlspecialchars($subject, ENT_QUOTES, 'UTF-8'); ?>" data-email-subject></div><div class="form-field"><label for="body">Plain-text message <span class="required" aria-hidden="true">*</span></label><textarea name="body" id="body" rows="14" maxlength="100000" required data-email-body><?php echo htmlspecialchars($body, ENT_QUOTES, 'UTF-8'); ?></textarea></div></section>
        <div class="email-compose-actions"><a href="view_inquiry.php?id=<?php echo $inquiryId; ?>#correspondence" class="button-secondary">Cancel</a><button type="submit" class="save-button"<?php echo !$deliveryAvailable || !$recipientAvailable ? ' disabled' : ''; ?> data-confirm="Queue this inquiry message for delivery?">Queue Email</button></div>
    </form>
</main>
<script nonce="<?php echo htmlspecialchars(contentSecurityPolicyNonce(), ENT_QUOTES, 'UTF-8'); ?>" type="application/json" id="engagement-email-template-data"><?php echo json_encode($templates, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>
<?php include 'templates/footer.php'; ?>
</body>
</html>
