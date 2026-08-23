<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/inbound_email_helpers.php';
startSecureSession();
requireLogin();

$userRole = (string) ($_SESSION['role'] ?? '');
if (!in_array($userRole, ['admin', 'editor'], true)) {
    http_response_code(403);
    exit('Forbidden.');
}
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

$allowedStatuses = ['review', 'pending', 'processing', 'failed', 'processed', 'rejected', 'all'];
$statusFilter = (string) ($_GET['status'] ?? $_POST['status'] ?? 'review');
if (!in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = 'review';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $messageId = filter_input(INPUT_POST, 'message_id', FILTER_VALIDATE_INT);
    $action = is_scalar($_POST['action'] ?? null) ? (string) $_POST['action'] : '';
    try {
        if (!$messageId) {
            throw new InvalidArgumentException('Select a valid inbound message.');
        }
        if ($action === 'approve') {
            $contactIds = is_array($_POST['contact_ids'] ?? null)
                ? array_map('intval', $_POST['contact_ids'])
                : [];
            $organizationIds = is_array($_POST['organization_ids'] ?? null)
                ? array_map('intval', $_POST['organization_ids'])
                : [];
            processInboundEmailMessage(
                $conn,
                $messageId,
                $contactIds,
                $organizationIds,
                (int) $_SESSION['user_id']
            );
            $_SESSION['inbound_mail_message'] = 'The email was added to the selected Chron logs.';
        } elseif ($action === 'retry') {
            $result = processInboundEmailMessage($conn, $messageId);
            $_SESSION['inbound_mail_message'] = $result === 'processed'
                ? 'The email now has a unique route and was added to Chron.'
                : 'The email still needs review.';
        } elseif ($action === 'reject') {
            rejectInboundEmailMessage($conn, $messageId, (int) $_SESSION['user_id']);
            $_SESSION['inbound_mail_message'] = 'The inbound email was rejected without changing Chron.';
        } else {
            throw new InvalidArgumentException('Select a valid inbound mail action.');
        }
    } catch (Throwable $exception) {
        applicationLog('error', 'Inbound mail review action failed', [
            'message_id' => (int) $messageId,
            'action' => $action,
            'error' => $exception->getMessage(),
        ]);
        $_SESSION['inbound_mail_error'] = $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : 'The inbound email could not be updated. Please try again.';
    }
    header('Location: inbound_mail.php?' . http_build_query([
        'status' => $statusFilter,
        'id' => $messageId,
    ]));
    exit();
}

$messageNotice = (string) ($_SESSION['inbound_mail_message'] ?? '');
$messageError = (string) ($_SESSION['inbound_mail_error'] ?? '');
unset($_SESSION['inbound_mail_message'], $_SESSION['inbound_mail_error']);

$counts = [
    'review' => 0,
    'pending' => 0,
    'processing' => 0,
    'failed' => 0,
    'processed' => 0,
    'rejected' => 0,
];
$countResult = $conn->query(
    'SELECT status, COUNT(*) AS total FROM inbound_email_messages GROUP BY status'
);
while ($countResult && ($row = $countResult->fetch_assoc())) {
    if (array_key_exists((string) $row['status'], $counts)) {
        $counts[(string) $row['status']] = (int) $row['total'];
    }
}

if ($statusFilter === 'all') {
    $messageStmt = $conn->prepare(
        'SELECT id, sender_name, sender_address, subject, status, review_reason,
                received_at, processed_at
         FROM inbound_email_messages
         ORDER BY received_at DESC, id DESC LIMIT 100'
    );
} else {
    $messageStmt = $conn->prepare(
        'SELECT id, sender_name, sender_address, subject, status, review_reason,
                received_at, processed_at
         FROM inbound_email_messages
         WHERE status = ?
         ORDER BY received_at DESC, id DESC LIMIT 100'
    );
}
if (!$messageStmt) {
    abortApplication(503, 'The inbound mail queue is temporarily unavailable.');
}
if ($statusFilter !== 'all') {
    $messageStmt->bind_param('s', $statusFilter);
}
$messageStmt->execute();
$messages = $messageStmt->get_result()->fetch_all(MYSQLI_ASSOC);
$messageStmt->close();

$selectedId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$selectedMessage = null;
$selectedRouting = null;
if ($selectedId) {
    $selectedStmt = $conn->prepare(
        'SELECT message.*, processor.username AS processed_by_username
         FROM inbound_email_messages message
         LEFT JOIN users processor ON processor.id = message.processed_by
         WHERE message.id = ?'
    );
    if (!$selectedStmt) {
        abortApplication(503, 'The inbound message is temporarily unavailable.');
    }
    $selectedStmt->bind_param('i', $selectedId);
    $selectedStmt->execute();
    $selectedMessage = $selectedStmt->get_result()->fetch_assoc() ?: null;
    $selectedStmt->close();
    if ($selectedMessage) {
        try {
            $selectedRouting = routeInboundEmailMessage($conn, $selectedMessage);
        } catch (Throwable $exception) {
            applicationLog('error', 'Unable to refresh inbound routing candidates', [
                'message_id' => (int) $selectedId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}

$statusLabels = [
    'review' => 'Needs review',
    'pending' => 'Pending',
    'processing' => 'Processing',
    'failed' => 'Failed',
    'processed' => 'Processed',
    'rejected' => 'Rejected',
    'all' => 'All',
];
?>
<!DOCTYPE html>
<html lang="en">
<?php renderPageHead('Inbound Mail - DNR', [
    'styles' => [
        'assets/css/style.min.css',
        'assets/css/modern.min.css',
        'assets/css/pages/inbound_mail.min.css',
    ],
]); ?>
<body>
<?php include 'templates/header.php'; ?>
<main class="container">
    <div class="page-heading">
        <div>
            <h1>Inbound Mail</h1>
            <p class="page-intro">Email copied to <?php echo htmlspecialchars((string) (getenv('DNR_INBOUND_ADDRESS') ?: 'the configured MOED mailbox'), ENT_QUOTES, 'UTF-8'); ?> and routed to Contact and Organization Chron logs.</p>
        </div>
    </div>

    <?php if ($messageNotice !== ''): ?><p class="success"><?php echo htmlspecialchars($messageNotice, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($messageError !== ''): ?><p class="error"><?php echo htmlspecialchars($messageError, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <nav class="inbound-status-filters" aria-label="Inbound message status">
        <?php foreach ($statusLabels as $status => $label): ?>
            <?php $count = $status === 'all' ? array_sum($counts) : ($counts[$status] ?? 0); ?>
            <a class="filter-button<?php echo $statusFilter === $status ? ' active' : ''; ?>" href="inbound_mail.php?status=<?php echo urlencode($status); ?>">
                <?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?> (<?php echo (int) $count; ?>)
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="inbound-mail-layout">
        <section class="inbound-message-list" aria-label="Inbound messages">
            <?php foreach ($messages as $message): ?>
                <?php
                $senderLabel = trim((string) ($message['sender_name'] ?? ''));
                if ($senderLabel === '') {
                    $senderLabel = (string) $message['sender_address'];
                }
                $detailUrl = 'inbound_mail.php?' . http_build_query([
                    'status' => $statusFilter,
                    'id' => (int) $message['id'],
                ]);
                ?>
                <a class="inbound-message-card<?php echo (int) $selectedId === (int) $message['id'] ? ' selected' : ''; ?>" href="<?php echo htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8'); ?>">
                    <span class="inbound-message-card-heading">
                        <strong><?php echo htmlspecialchars((string) ($message['subject'] ?: '(no subject)'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <span class="inbound-status inbound-status-<?php echo htmlspecialchars((string) $message['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusLabels[(string) $message['status']] ?? ucfirst((string) $message['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                    </span>
                    <span><?php echo htmlspecialchars($senderLabel, ENT_QUOTES, 'UTF-8'); ?></span>
                    <time datetime="<?php echo htmlspecialchars((string) $message['received_at'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars((string) $message['received_at'], ENT_QUOTES, 'UTF-8'); ?> UTC</time>
                    <?php if (!empty($message['review_reason'])): ?><small><?php echo htmlspecialchars((string) $message['review_reason'], ENT_QUOTES, 'UTF-8'); ?></small><?php endif; ?>
                </a>
            <?php endforeach; ?>
            <?php if (!$messages): ?><p class="inbound-empty-state">No messages have this status.</p><?php endif; ?>
        </section>

        <section class="record-section inbound-message-detail" aria-label="Selected inbound message">
            <?php if (!$selectedMessage): ?>
                <h2>Select a Message</h2>
                <p>Choose a message to inspect its participants, routing decision, and retained plain-text content.</p>
            <?php else: ?>
                <?php
                $toAddresses = inboundEmailDecodeAddressList($selectedMessage['to_addresses']);
                $ccAddresses = inboundEmailDecodeAddressList($selectedMessage['cc_addresses']);
                $attachmentNames = inboundEmailDecodeStringList($selectedMessage['attachment_names']);
                $bodyPreview = mb_substr((string) $selectedMessage['body_text'], 0, 100000, 'UTF-8');
                ?>
                <div class="inbound-detail-heading">
                    <div>
                        <h2><?php echo htmlspecialchars((string) ($selectedMessage['subject'] ?: '(no subject)'), ENT_QUOTES, 'UTF-8'); ?></h2>
                        <span class="inbound-status inbound-status-<?php echo htmlspecialchars((string) $selectedMessage['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusLabels[(string) $selectedMessage['status']] ?? ucfirst((string) $selectedMessage['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                    <small>Inbound message #<?php echo (int) $selectedMessage['id']; ?></small>
                </div>
                <dl class="inbound-message-headers">
                    <div><dt>From</dt><dd><?php echo htmlspecialchars(trim((string) $selectedMessage['sender_name']) !== '' ? $selectedMessage['sender_name'] . ' <' . $selectedMessage['sender_address'] . '>' : (string) $selectedMessage['sender_address'], ENT_QUOTES, 'UTF-8'); ?></dd></div>
                    <div><dt>To</dt><dd><?php echo htmlspecialchars($toAddresses ? implode(', ', $toAddresses) : 'None', ENT_QUOTES, 'UTF-8'); ?></dd></div>
                    <div><dt>Cc</dt><dd><?php echo htmlspecialchars($ccAddresses ? implode(', ', $ccAddresses) : 'None', ENT_QUOTES, 'UTF-8'); ?></dd></div>
                    <div><dt>Sent</dt><dd><?php echo htmlspecialchars((string) ($selectedMessage['sent_at'] ?: 'Not supplied'), ENT_QUOTES, 'UTF-8'); ?><?php echo $selectedMessage['sent_at'] ? ' UTC' : ''; ?></dd></div>
                    <div><dt>Received</dt><dd><?php echo htmlspecialchars((string) $selectedMessage['received_at'], ENT_QUOTES, 'UTF-8'); ?> UTC</dd></div>
                    <div><dt>Attachments</dt><dd><?php echo htmlspecialchars($attachmentNames ? implode(', ', $attachmentNames) : 'None', ENT_QUOTES, 'UTF-8'); ?></dd></div>
                </dl>

                <?php if ($selectedRouting): ?>
                    <section class="inbound-routing-summary">
                        <h3>Routing</h3>
                        <p>Sender classification: <strong><?php echo htmlspecialchars(ucfirst((string) $selectedRouting['sender']['type']), ENT_QUOTES, 'UTF-8'); ?></strong> — <?php echo htmlspecialchars((string) $selectedRouting['sender']['label'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php if ($selectedRouting['reasons']): ?>
                            <ul><?php foreach ($selectedRouting['reasons'] as $reason): ?><li><?php echo htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?></ul>
                        <?php else: ?>
                            <p class="success">Every routing match is unique.</p>
                        <?php endif; ?>
                    </section>
                <?php endif; ?>

                <?php if (in_array($selectedMessage['status'], ['review', 'failed', 'pending'], true) && $selectedRouting): ?>
                    <form method="post" action="inbound_mail.php" class="inbound-review-form">
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="message_id" value="<?php echo (int) $selectedMessage['id']; ?>">
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <fieldset>
                            <legend>Contact Chron Logs</legend>
                            <?php foreach ($selectedRouting['contacts'] as $contact): ?>
                                <label><input type="checkbox" name="contact_ids[]" value="<?php echo (int) $contact['id']; ?>" checked> <?php echo htmlspecialchars((string) $contact['label'], ENT_QUOTES, 'UTF-8'); ?></label>
                            <?php endforeach; ?>
                            <?php if (!$selectedRouting['contacts']): ?><p>No matching active Contacts.</p><?php endif; ?>
                        </fieldset>
                        <fieldset>
                            <legend>Organization Chron Logs</legend>
                            <?php foreach ($selectedRouting['organizations'] as $organization): ?>
                                <label><input type="checkbox" name="organization_ids[]" value="<?php echo (int) $organization['id']; ?>" checked> <?php echo htmlspecialchars((string) $organization['label'], ENT_QUOTES, 'UTF-8'); ?></label>
                            <?php endforeach; ?>
                            <?php if (!$selectedRouting['organizations']): ?><p>No matching active Organizations.</p><?php endif; ?>
                        </fieldset>
                        <div class="inbound-review-actions">
                            <?php if ($selectedRouting['contacts'] || $selectedRouting['organizations']): ?><button type="submit" name="action" value="approve" class="save-button">Approve selected routes</button><?php endif; ?>
                            <button type="submit" name="action" value="retry" class="button-secondary">Retry automatic routing</button>
                            <button type="submit" name="action" value="reject" class="danger-button">Reject message</button>
                        </div>
                    </form>
                <?php endif; ?>

                <section class="inbound-message-body">
                    <h3>Plain-Text Content</h3>
                    <pre><?php echo htmlspecialchars($bodyPreview !== '' ? $bodyPreview : '[No plain-text message body was available.]', ENT_QUOTES, 'UTF-8'); ?></pre>
                    <?php if (mb_strlen((string) $selectedMessage['body_text'], 'UTF-8') > 100000): ?><p class="field-help">The review preview is limited to 100,000 characters; the retained source text is longer.</p><?php endif; ?>
                </section>
            <?php endif; ?>
        </section>
    </div>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
