<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/engagement_email_helpers.php';
startSecureSession();
requireLogin();
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

$userRole = (string) ($_SESSION['role'] ?? '');
$messageId = \Dnr\Http\RequestInput::positiveInt(
    $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET,
    'id'
);
if ($messageId === null) {
    header('Location: engagements.php');
    exit();
}

$messageText = (string) ($_SESSION['engagement_email_message'] ?? '');
unset($_SESSION['engagement_email_message']);
$error = '';
try {
    $message = fetchEngagementEmailMessage($conn, $messageId);
} catch (Throwable $exception) {
    abortApplication(503, 'The outbound message is temporarily unavailable.', [
        'message_id' => $messageId,
        'error' => $exception->getMessage(),
    ]);
}
if ($message === null) {
    http_response_code(404);
    exit('Outbound message not found.');
}
$isInquiryMessage = !empty($message['booking_inquiry_id']);
$inquiryActive = $isInquiryMessage && in_array((string) ($message['inquiry_stage'] ?? ''), [
    'new', 'contacted', 'qualified', 'awaiting_details', 'proposal_sent',
], true);
$parentUnavailable = $isInquiryMessage
    ? !$inquiryActive
    : !empty($message['engagement_deleted']);
$parentId = (int) ($isInquiryMessage
    ? $message['booking_inquiry_id']
    : $message['engagement_id']);
$parentLabel = $isInquiryMessage ? 'Inquiry' : 'Engagement';
$parentTitle = trim((string) ($isInquiryMessage
    ? ($message['inquiry_title'] ?? '')
    : ($message['event_title'] ?? '')));
if ($parentTitle === '') {
    $parentTitle = (string) $message['organization_name'];
}
$parentUrl = $isInquiryMessage
    ? 'view_inquiry.php?id=' . $parentId . '#correspondence'
    : 'view_engagement.php?id=' . $parentId . '#correspondence';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    if (!in_array($userRole, ['admin', 'editor'], true) || $parentUnavailable) {
        http_response_code(403);
        exit('Forbidden.');
    }
    try {
        $retried = retryFailedEngagementEmailDeliveries($conn, $messageId);
        $_SESSION['engagement_email_message'] = sprintf(
            '%d failed deliver%s re-queued.',
            $retried,
            $retried === 1 ? 'y was' : 'ies were'
        );
        header('Location: outbound_mail.php?id=' . $messageId);
        exit();
    } catch (InvalidArgumentException $exception) {
        $error = $exception->getMessage();
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to retry engagement correspondence', [
            'message_id' => $messageId,
            'error' => $exception->getMessage(),
        ]);
        $error = 'The failed deliveries could not be re-queued.';
    }
    $message = fetchEngagementEmailMessage($conn, $messageId) ?? $message;
}

$status = engagementEmailAggregateStatus($message);
$statusLabels = [
    'sent' => 'Sent',
    'failed' => 'Failed',
    'partial' => 'Partially Sent',
    'pending' => 'Delivery Pending',
];
$templateDefinitions = engagementEmailTemplateDefinitions();
$inquiryTemplateLabels = [
    'initial_response' => 'Initial Response',
    'request_details' => 'Request Details',
    'date_options' => 'Date Options',
    'proposal_follow_up' => 'Proposal Follow-Up',
];
$templateLabel = $isInquiryMessage
    ? ($inquiryTemplateLabels[(string) $message['template_key']] ?? 'Custom Message')
    : ($templateDefinitions[(string) $message['template_key']]['label'] ?? 'Custom Message');
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead(applicationPageTitle('Outbound Message'), [
    'styles' => [
        'assets/css/style.min.css',
        'assets/css/modern.min.css',
        'assets/css/pages/engagement_email.min.css',
    ],
]); ?>
<body>
<?php include 'templates/header.php'; ?>
<main class="container engagement-email-page outbound-message-page">
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="<?php echo $isInquiryMessage ? 'inquiries.php' : 'engagements.php'; ?>"><?php echo $isInquiryMessage ? 'Booking Pipeline' : 'Engagements'; ?></a><span aria-hidden="true">/</span>
        <a href="<?php echo htmlspecialchars($parentUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $parentLabel; ?> Details</a><span aria-hidden="true">/</span>
        <span>Outbound Message</span>
    </nav>
    <header class="page-heading">
        <div>
            <p class="eyebrow">Outbound Correspondence</p>
            <h1><?php echo htmlspecialchars((string) $message['subject'], ENT_QUOTES, 'UTF-8'); ?></h1>
            <p class="page-intro"><?php echo htmlspecialchars((string) $message['organization_name'], ENT_QUOTES, 'UTF-8'); ?> · Message #<?php echo $messageId; ?></p>
        </div>
        <span class="email-status email-status-<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusLabels[$status], ENT_QUOTES, 'UTF-8'); ?></span>
    </header>

    <?php if ($messageText !== ''): ?><p class="success" role="status"><?php echo htmlspecialchars($messageText, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="error" role="alert"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <section class="outbound-message-summary">
        <dl>
            <div><dt><?php echo $parentLabel; ?></dt><dd><a href="<?php echo htmlspecialchars($parentUrl, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($parentTitle, ENT_QUOTES, 'UTF-8'); ?></a></dd></div>
            <div><dt>Template</dt><dd><?php echo htmlspecialchars($templateLabel, ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <div><dt>Created</dt><dd><?php echo htmlspecialchars(applicationTimestampLabel($message['created_at'], 'F j, Y \a\t g:i A T'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <div><dt>Created by</dt><dd><?php echo htmlspecialchars((string) ($message['created_by_username'] ?: 'System'), ENT_QUOTES, 'UTF-8'); ?></dd></div>
            <div><dt>Replies</dt><dd><?php echo !empty($message['reply_to']) ? 'Return to ' . htmlspecialchars((string) $message['reply_to'], ENT_QUOTES, 'UTF-8') : 'Return to the application sender'; ?></dd></div>
            <?php if (!$isInquiryMessage): ?><div><dt>Event Brief</dt><dd><?php echo !empty($message['included_event_brief']) ? 'Included' : 'Not Included'; ?></dd></div><?php endif; ?>
        </dl>
    </section>

    <section class="outbound-delivery-section">
        <div class="email-section-heading">
            <div><span><?php echo count($message['deliveries']); ?></span><h2>Deliveries</h2></div>
            <p>Each recipient is delivered independently.</p>
        </div>
        <div class="outbound-delivery-list">
            <?php foreach ($message['deliveries'] as $delivery): ?>
                <?php
                $roles = json_decode((string) $delivery['recipient_roles_json'], true);
                $roles = is_array($roles) ? $roles : [];
                ?>
                <article class="outbound-delivery-card">
                    <div>
                        <strong><?php echo htmlspecialchars((string) $delivery['recipient_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                        <a href="mailto:<?php echo htmlspecialchars((string) $delivery['recipient_email'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $delivery['recipient_email'], ENT_QUOTES, 'UTF-8'); ?></a>
                        <?php if ($roles !== []): ?><small><?php echo htmlspecialchars(implode(' · ', array_map('engagementContactRoleLabel', $roles)), ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                    </div>
                    <div class="outbound-delivery-state">
                        <span class="email-status email-status-<?php echo htmlspecialchars((string) $delivery['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(ucfirst((string) $delivery['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                        <?php if (!empty($delivery['sent_at'])): ?><small><?php echo htmlspecialchars(applicationTimestampLabel($delivery['sent_at'], 'M j, Y g:i A T'), ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                        <?php if (!empty($delivery['last_error'])): ?><small class="delivery-error"><?php echo htmlspecialchars((string) $delivery['last_error'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php if ((int) $message['failed_count'] > 0 && in_array($userRole, ['admin', 'editor'], true) && !$parentUnavailable): ?>
            <form method="post" action="outbound_mail.php" class="outbound-retry-form">
                <?php echo csrfInput(); ?>
                <input type="hidden" name="id" value="<?php echo $messageId; ?>">
                <button type="submit" class="button-secondary" data-confirm="Re-queue every failed recipient for a fresh delivery attempt?">Retry Failed Deliveries</button>
            </form>
        <?php endif; ?>
    </section>

    <section class="outbound-content-section">
        <h2>Plain-Text Content</h2>
        <pre><?php echo htmlspecialchars((string) $message['body_text'], ENT_QUOTES, 'UTF-8'); ?></pre>
    </section>

    <div class="email-compose-actions">
        <a href="<?php echo htmlspecialchars($parentUrl, ENT_QUOTES, 'UTF-8'); ?>" class="button-secondary">Back to <?php echo $parentLabel; ?></a>
        <?php if (in_array($userRole, ['admin', 'editor'], true) && !$parentUnavailable): ?><a href="<?php echo $isInquiryMessage ? 'compose_inquiry_email.php' : 'compose_engagement_email.php'; ?>?id=<?php echo $parentId; ?>" class="button-add">New Message</a><?php endif; ?>
    </div>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
