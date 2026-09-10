<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
$conn = applicationDatabaseConnection();
require_once __DIR__ . '/inbound_email_helpers.php';
require_once __DIR__ . '/chron_log_helpers.php';
require_once __DIR__ . '/inbound_queue_helpers.php';
require_once __DIR__ . '/two_factor_helpers.php';
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
$statusFilter = \Dnr\Http\RequestInput::enum(
    $_POST,
    'status',
    $allowedStatuses,
    \Dnr\Http\RequestInput::enum($_GET, 'status', $allowedStatuses, 'review')
);

$queueInput = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$queueSearch = is_scalar($queueInput['q'] ?? null) ? mb_substr(trim((string) $queueInput['q']), 0, 200) : '';
$queueSort = \Dnr\Http\RequestInput::enum($queueInput, 'sort', ['oldest', 'newest'], $statusFilter === 'review' ? 'oldest' : 'newest');
$queuePage = max(1, (int) filter_var($queueInput['page'] ?? 1, FILTER_VALIDATE_INT));
$queuePageSize = paginationPageSizePreference('inbound_mail', $queueInput['per_page'] ?? null);
$queueContext = ['status' => $statusFilter, 'q' => $queueSearch, 'sort' => $queueSort, 'page' => $queuePage, 'per_page' => $queuePageSize];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    $messageId = filter_input(INPUT_POST, 'message_id', FILTER_VALIDATE_INT);
    $action = is_scalar($_POST['action'] ?? null) ? (string) $_POST['action'] : '';
    $redirectMessageId = $messageId;
    try {
        if (!$messageId) {
            throw new InvalidArgumentException('Select a valid inbound message.');
        }
        if ($action === 'purge') {
            if (!canDeleteEntries($userRole)) {
                http_response_code(403);
                exit('Forbidden.');
            }
            requireRecentAdminElevation('inbound_mail.php?' . http_build_query(array_merge($queueContext, ['id' => $messageId])));
            if (!purgeInboundEmailMessage($conn, $messageId)) {
                throw new InvalidArgumentException('That inbound message is no longer available.');
            }
            $redirectMessageId = null;
            $_SESSION['inbound_mail_message'] = 'The inbound mail entry was purged. Associated Chron Log entries were preserved.';
        } elseif ($action === 'approve') {
            $contactIds = \Dnr\Http\RequestInput::positiveIntList($_POST, 'contact_ids');
            $organizationIds = \Dnr\Http\RequestInput::positiveIntList(
                $_POST,
                'organization_ids'
            );
            $engagementIds = \Dnr\Http\RequestInput::positiveIntList(
                $_POST,
                'engagement_ids'
            );
            $inquiryIds = \Dnr\Http\RequestInput::positiveIntList(
                $_POST,
                'inquiry_ids'
            );
            processInboundEmailMessage(
                $conn,
                $messageId,
                $contactIds,
                $organizationIds,
                (int) $_SESSION['user_id'],
                $engagementIds,
                $inquiryIds
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
    $redirectParameters = $queueContext;
    if ($redirectMessageId) {
        $redirectParameters['id'] = $redirectMessageId;
    }
    header('Location: inbound_mail.php?' . http_build_query($redirectParameters));
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


$queueResult = fetchInboundMailQueue($conn, $statusFilter, $queueSearch, $queueSort, $queuePage, $queuePageSize);
$messages = $queueResult['messages'];
$queueTotal = $queueResult['total'];
$queuePage = $queueResult['page'];
$queuePages = $queueResult['pages'];
$queueOffset = $queueResult['offset'];
$queuePageSize = $queueResult['page_size'];
$queueContext['page'] = $queuePage;

$requestedId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$selectedId = $requestedId ?: ($messages[0]['id'] ?? null);
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
<?php renderPageHead(applicationPageTitle('Inbound Mail'), [
    'styles' => [
        'assets/css/style.min.css',
        'assets/css/modern.min.css',
        'assets/css/pages/inbound_mail.min.css',
    ],
    'scripts' => ['assets/js/inbound-mail.min.js'],
]); ?>
<body class="inbound-mail-body">
<?php include 'templates/header.php'; ?>
<main class="container inbound-mail-page">
    <div class="page-heading inbound-mail-heading">
        <div>
            <h1>Inbound Mail</h1>
            <p class="page-intro">Read incoming mail, check its destinations, and save the conversation to Chron.</p>
        </div>
        <a class="button-secondary inbound-refresh" href="<?php echo htmlspecialchars('inbound_mail.php?' . http_build_query(array_merge($queueContext, $requestedId ? ['id' => $requestedId] : [])), ENT_QUOTES, 'UTF-8'); ?>">Refresh inbox</a>
    </div>

    <?php if ($messageNotice !== ''): ?><p class="success" role="status"><?php echo htmlspecialchars($messageNotice, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>
    <?php if ($messageError !== ''): ?><p class="error" role="alert"><?php echo htmlspecialchars($messageError, ENT_QUOTES, 'UTF-8'); ?></p><?php endif; ?>

    <nav class="summary-grid task-summary-grid inbound-status-filters" aria-label="Inbound message status">
        <?php foreach ($statusLabels as $status => $label): ?>
            <?php $count = $status === 'all' ? array_sum($counts) : ($counts[$status] ?? 0); ?>
            <a class="summary-card inbound-status-filter<?php echo $statusFilter === $status ? ' is-selected' : ''; ?>"<?php echo $statusFilter === $status ? ' aria-current="page"' : ''; ?> href="<?php echo htmlspecialchars('inbound_mail.php?' . http_build_query(array_merge($queueContext, ['status' => $status, 'page' => 1])), ENT_QUOTES, 'UTF-8'); ?>">
                <span><small><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></small><strong><?php echo number_format($count); ?></strong></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <form method="get" action="inbound_mail.php" class="inbound-queue-search" id="inbound-queue-search">
        <input type="hidden" name="per_page" value="<?php echo $queuePageSize; ?>">
        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="inbound-search-field"><label for="inbox-search">Search messages</label><input type="search" name="q" id="inbox-search" value="<?php echo htmlspecialchars($queueSearch, ENT_QUOTES, 'UTF-8'); ?>" placeholder="Search subject, sender, or message content"></div>
        <div><label for="inbox-sort">Order</label><select name="sort" id="inbox-sort"><option value="oldest"<?php echo $queueSort === 'oldest' ? ' selected' : ''; ?>>Oldest first</option><option value="newest"<?php echo $queueSort === 'newest' ? ' selected' : ''; ?>>Newest first</option></select></div>
        <button type="submit" class="button-secondary">Search</button>
        <?php if ($queueSearch !== ''): ?><a class="inbound-clear-search" href="<?php echo htmlspecialchars('inbound_mail.php?' . http_build_query(array_merge($queueContext, ['q' => '', 'page' => 1])), ENT_QUOTES, 'UTF-8'); ?>">Clear search</a><?php endif; ?>
    </form>

    <div class="inbound-mail-layout<?php echo $requestedId ? ' inbound-message-open' : ''; ?>">
        <section class="inbound-queue" aria-label="Inbound messages">
            <div class="inbound-queue-heading">
                <h2><?php echo htmlspecialchars($statusLabels[$statusFilter], ENT_QUOTES, 'UTF-8'); ?></h2>
                <span><?php echo number_format($queueTotal); ?> <?php echo $queueTotal === 1 ? 'message' : 'messages'; ?></span>
            </div>
            <div class="inbound-message-list">
                <?php foreach ($messages as $message): ?>
                    <?php
                    $senderLabel = trim((string) ($message['sender_name'] ?? '')) ?: (string) $message['sender_address'];
                    $detailUrl = 'inbound_mail.php?' . http_build_query(array_merge($queueContext, ['id' => (int) $message['id']]));
                    ?>
                    <a class="inbound-message-card<?php echo (int) $selectedId === (int) $message['id'] ? ' selected' : ''; ?>"<?php echo (int) $selectedId === (int) $message['id'] ? ' aria-current="true"' : ''; ?> href="<?php echo htmlspecialchars($detailUrl, ENT_QUOTES, 'UTF-8'); ?>#inbound-detail">
                        <span class="inbound-message-card-heading"><span class="inbound-sender"><?php echo htmlspecialchars($senderLabel, ENT_QUOTES, 'UTF-8'); ?></span><span class="inbound-status inbound-status-<?php echo htmlspecialchars((string) $message['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusLabels[(string) $message['status']] ?? ucfirst((string) $message['status']), ENT_QUOTES, 'UTF-8'); ?></span></span>
                        <strong class="inbound-subject"><?php echo htmlspecialchars((string) ($message['subject'] ?: '(no subject)'), ENT_QUOTES, 'UTF-8'); ?></strong>
                        <?php if (!empty($message['review_reason'])): ?><span class="inbound-message-excerpt"><?php echo htmlspecialchars((string) $message['review_reason'], ENT_QUOTES, 'UTF-8'); ?></span><?php endif; ?>
                        <time datetime="<?php echo htmlspecialchars((string) $message['received_at'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(chronLogTimestampDetails($message['received_at'])['display'], ENT_QUOTES, 'UTF-8'); ?></time>
                    </a>
                <?php endforeach; ?>
                <?php if (!$messages): ?>
                    <div class="inbound-empty-state"><span class="inbound-empty-icon" aria-hidden="true">&#9993;</span><h3><?php echo $queueSearch !== '' ? 'No matches found' : ($statusFilter === 'review' ? 'You’re all caught up' : 'No messages here'); ?></h3><p><?php echo $queueSearch !== '' ? 'Try another search or clear it to see this inbox.' : 'Choose another status to browse your mail.'; ?></p><a href="<?php echo htmlspecialchars('inbound_mail.php?' . http_build_query(array_merge($queueContext, ['q' => '', 'status' => $queueSearch !== '' ? $statusFilter : 'all', 'page' => 1])), ENT_QUOTES, 'UTF-8'); ?>"><?php echo $queueSearch !== '' ? 'Clear search' : 'View all mail'; ?></a></div>
                <?php endif; ?>
            </div>
            <?php renderPagination($queueTotal, $queuePage, $queuePageSize, 'inbound_mail.php?' . http_build_query($queueContext), 'messages', 'Message pages'); ?>
        </section>

        <section class="inbound-message-detail" id="inbound-detail" aria-label="Selected inbound message" tabindex="-1">
            <?php if (!$selectedMessage): ?>
                <div class="inbound-empty-state inbound-reader-empty"><span class="inbound-empty-icon" aria-hidden="true">&#9993;</span><h2><?php echo $requestedId ? 'Message unavailable' : 'Your reading space'; ?></h2><p><?php echo $requestedId ? 'This message may have been removed. Choose another message from the inbox.' : 'Select a message to read it and choose where to save it.'; ?></p><a class="button-secondary" href="<?php echo htmlspecialchars('inbound_mail.php?' . http_build_query($queueContext), ENT_QUOTES, 'UTF-8'); ?>">Back to messages</a></div>
            <?php else: ?>
                <?php
                $toAddresses = inboundEmailDecodeAddressList($selectedMessage['to_addresses']);
                $ccAddresses = inboundEmailDecodeAddressList($selectedMessage['cc_addresses']);
                $attachmentNames = inboundEmailDecodeStringList($selectedMessage['attachment_names']);
                $bodyPreview = mb_substr((string) $selectedMessage['body_text'], 0, 100000, 'UTF-8');
                $canReviewMessage = in_array($selectedMessage['status'], ['review', 'failed', 'pending'], true);
                ?>
                <div class="inbound-detail-toolbar">
                    <a class="inbound-back-link" href="<?php echo htmlspecialchars('inbound_mail.php?' . http_build_query($queueContext), ENT_QUOTES, 'UTF-8'); ?>">&#8592; Back to messages</a>
                    <span class="inbound-status inbound-status-<?php echo htmlspecialchars((string) $selectedMessage['status'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($statusLabels[(string) $selectedMessage['status']] ?? ucfirst((string) $selectedMessage['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                    <span class="inbound-message-id">Message #<?php echo (int) $selectedMessage['id']; ?></span>
                </div>
                <div class="inbound-detail-workspace">
                    <div class="inbound-reader">
                        <div class="inbound-detail-heading"><h2><?php echo htmlspecialchars((string) ($selectedMessage['subject'] ?: '(no subject)'), ENT_QUOTES, 'UTF-8'); ?></h2></div>
                        <div class="inbound-sender-summary"><strong><?php echo htmlspecialchars(trim((string) $selectedMessage['sender_name']) ?: (string) $selectedMessage['sender_address'], ENT_QUOTES, 'UTF-8'); ?></strong><span><?php echo htmlspecialchars((string) $selectedMessage['sender_address'], ENT_QUOTES, 'UTF-8'); ?></span><time datetime="<?php echo htmlspecialchars((string) $selectedMessage['received_at'], ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars(chronLogTimestampDetails($selectedMessage['received_at'])['display'], ENT_QUOTES, 'UTF-8'); ?></time></div>
                        <details class="inbound-envelope-details">
                            <summary>Message details<?php echo $attachmentNames ? ' · ' . count($attachmentNames) . ' attachment' . (count($attachmentNames) === 1 ? '' : 's') : ''; ?></summary>
                            <dl class="inbound-message-headers">
                                <div><dt>To</dt><dd><?php echo htmlspecialchars($toAddresses ? implode(', ', $toAddresses) : 'None', ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <?php if ($ccAddresses): ?><div><dt>Cc</dt><dd><?php echo htmlspecialchars(implode(', ', $ccAddresses), ENT_QUOTES, 'UTF-8'); ?></dd></div><?php endif; ?>
                                <div><dt>Sent</dt><dd><?php echo htmlspecialchars($selectedMessage['sent_at'] ? chronLogTimestampDetails($selectedMessage['sent_at'])['display'] : 'Not supplied', ENT_QUOTES, 'UTF-8'); ?></dd></div>
                                <div><dt>Attachments</dt><dd><?php echo htmlspecialchars($attachmentNames ? implode(', ', $attachmentNames) : 'None', ENT_QUOTES, 'UTF-8'); ?><?php if ($attachmentNames): ?><p class="field-help">Filenames only; attachment contents are not stored</p><?php endif; ?></dd></div>
                            </dl>
                        </details>
                        <section class="inbound-message-body" aria-label="Message content">
                            <pre tabindex="0" aria-label="Message text"><?php echo renderTextWithLinks($bodyPreview !== '' ? $bodyPreview : '[No plain-text message body was available.]', false); ?></pre>
                            <?php if (mb_strlen((string) $selectedMessage['body_text'], 'UTF-8') > 100000): ?><p class="field-help">The review preview is limited to 100,000 characters; the retained source text is longer.</p><?php endif; ?>
                        </section>
                <?php if ($selectedRouting): ?>
                    <details class="inbound-routing-summary">
                        <summary>Routing details</summary>
                        <p>Sender classification: <strong><?php echo htmlspecialchars(ucfirst((string) $selectedRouting['sender']['type']), ENT_QUOTES, 'UTF-8'); ?></strong> — <?php echo htmlspecialchars((string) $selectedRouting['sender']['label'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <?php if ($selectedRouting['sender_authenticated']): ?>
                            <p class="success">Sender authentication: <?php echo match ($selectedRouting['sender_authentication_method']) {
                                'proton-internal' => 'Verified by Proton (internal message)',
                                'proton-dmarc' => 'DMARC verified by Proton',
                                default => 'DMARC verified by the trusted mailbox provider',
                            }; ?></p>
                        <?php endif; ?>
                        <?php foreach ($selectedRouting['engagements'] as $engagement): ?>
                            <a class="inbound-engagement-route" href="view_engagement.php?id=<?php echo (int) $engagement['id']; ?>">
                                <code><?php echo htmlspecialchars((string) $engagement['marker'], ENT_QUOTES, 'UTF-8'); ?></code>
                                <span><?php echo htmlspecialchars((string) $engagement['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        <?php endforeach; ?>
                        <?php foreach ($selectedRouting['inquiries'] as $inquiry): ?>
                            <a class="inbound-engagement-route" href="view_inquiry.php?id=<?php echo (int) $inquiry['id']; ?>">
                                <code><?php echo htmlspecialchars((string) $inquiry['marker'], ENT_QUOTES, 'UTF-8'); ?></code>
                                <span><?php echo htmlspecialchars((string) $inquiry['label'], ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        <?php endforeach; ?>
                        <?php if ($selectedRouting['reasons']): ?>
                            <ul><?php foreach ($selectedRouting['reasons'] as $reason): ?><li><?php echo htmlspecialchars($reason, ENT_QUOTES, 'UTF-8'); ?></li><?php endforeach; ?></ul>
                        <?php else: ?>
                            <p class="success">Every routing match is unique.</p>
                        <?php endif; ?>
                    </details>
                <?php endif; ?>

                        <?php if (canDeleteEntries($userRole)): ?>
                            <details class="inbound-admin-actions">
                                <summary>Manage retained email</summary>
                                <p class="field-help">Permanently remove this source email. Existing Chron entries are kept. Administrator confirmation is required.</p>
                            <form method="post" action="inbound_mail.php" data-confirm="Permanently purge this inbound mail entry? Associated Contact, Organization, and Engagement Chron Log entries will be preserved, but their source-email links will be removed. This cannot be undone.">
                                <?php echo csrfInput(); ?>
                                <input type="hidden" name="message_id" value="<?php echo (int) $selectedMessage['id']; ?>">
                                <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="q" value="<?php echo htmlspecialchars($queueSearch, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($queueSort, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="page" value="<?php echo $queuePage; ?>">
                        <input type="hidden" name="per_page" value="<?php echo $queuePageSize; ?>">
                                <input type="hidden" name="action" value="purge">
                                <button type="submit" class="danger-button">Purge Mail Entry</button>
                            </form>
                            </details>
                        <?php endif; ?>
                    </div>
                    <aside class="inbound-filing-panel" aria-label="Message filing">
                        <?php if ($canReviewMessage && !empty($selectedMessage['review_reason'])): ?><div class="inbound-review-notice"><strong><?php echo $selectedMessage['status'] === 'failed' ? 'Filing needs attention' : 'Review needed'; ?></strong><p><?php echo htmlspecialchars((string) $selectedMessage['review_reason'], ENT_QUOTES, 'UTF-8'); ?></p></div><?php endif; ?>
                <?php if ($canReviewMessage && $selectedRouting): ?>
                    <form method="post" action="inbound_mail.php" class="inbound-review-form">
                        <p class="inbound-eyebrow">File this message</p>
                        <h3>Choose Chron Logs</h3>
                        <p class="field-help">Review the suggested destinations, then save the conversation.</p>
                        <?php echo csrfInput(); ?>
                        <input type="hidden" name="message_id" value="<?php echo (int) $selectedMessage['id']; ?>">
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="q" value="<?php echo htmlspecialchars($queueSearch, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($queueSort, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="page" value="<?php echo $queuePage; ?>">
                        <input type="hidden" name="per_page" value="<?php echo $queuePageSize; ?>">
                        <div class="inbound-review-actions">
                            <p id="inbound-selection-summary" class="field-help" role="status" aria-live="polite">Choose at least one destination to save this message</p>
                            <button type="submit" name="action" value="approve" class="save-button" aria-describedby="inbound-selection-summary">Save to Chron logs</button>
                        </div>
                        <?php if ($selectedRouting['contacts']): ?>
                        <fieldset>
                            <legend>Contacts</legend>
                            <?php foreach ($selectedRouting['contacts'] as $contact): ?>
                                <label class="inbound-route-choice"><input type="checkbox" name="contact_ids[]" value="<?php echo (int) $contact['id']; ?>" checked> <?php echo htmlspecialchars((string) $contact['label'], ENT_QUOTES, 'UTF-8'); ?></label>
                            <?php endforeach; ?>
                        </fieldset>
                        <?php endif; ?>
                        <?php if ($selectedRouting['organizations']): ?>
                        <fieldset>
                            <legend>Organizations</legend>
                            <?php foreach ($selectedRouting['organizations'] as $organization): ?>
                                <label class="inbound-route-choice"><input type="checkbox" name="organization_ids[]" value="<?php echo (int) $organization['id']; ?>" checked> <?php echo htmlspecialchars((string) $organization['label'], ENT_QUOTES, 'UTF-8'); ?></label>
                            <?php endforeach; ?>
                        </fieldset>
                        <?php endif; ?>
                        <?php if ($selectedRouting['inquiries']): ?>
                        <fieldset>
                            <legend>Inquiries</legend>
                            <?php foreach ($selectedRouting['inquiries'] as $inquiry): ?>
                                <label class="inbound-route-choice"><input type="checkbox" name="inquiry_ids[]" value="<?php echo (int) $inquiry['id']; ?>" checked> <?php echo htmlspecialchars((string) $inquiry['label'], ENT_QUOTES, 'UTF-8'); ?></label>
                            <?php endforeach; ?>
                        </fieldset>
                        <?php endif; ?>
                        <fieldset>
                            <legend>Engagement</legend>
                            <?php $markerEngagements = $selectedRouting['engagements']; ?>
                            <label for="inbound-engagement-search">Find an active engagement</label>
                            <input type="search" id="inbound-engagement-search" class="inbound-engagement-search" aria-describedby="inbound-engagement-search-status" data-engagement-search-url="inbound_engagement_search.php" autocomplete="off" placeholder="Search by marker, ID, title, or organization">
                            <p id="inbound-engagement-search-status" class="field-help" role="status" aria-live="polite">Search by name (2+ characters), ID, or email marker</p>
                            <label for="inbound-engagement-id">Engagement</label>
                            <select id="inbound-engagement-id" name="engagement_ids[]" class="inbound-engagement-select">
                                <option value="">No engagement selected</option>
                                <?php foreach ($markerEngagements as $engagement): ?>
                                    <option value="<?php echo (int) $engagement['id']; ?>" selected><?php echo htmlspecialchars((string) $engagement['marker'] . ' · ' . (string) $engagement['label'], ENT_QUOTES, 'UTF-8'); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <p class="field-help">An engagement identified by a valid email marker is preselected. You can search to choose a different one.</p>
                            <noscript><p class="error">JavaScript is required to search for an engagement that was not selected by a valid email marker.</p></noscript>
                        </fieldset>
                        <button type="submit" name="action" value="retry" class="button-secondary">Find matches again</button>
                        <details class="inbound-other-actions">
                            <summary>Other actions</summary>
                            <p class="field-help">Reject this message if it should not be saved to Chron. Existing Chron entries are preserved.</p>
                            <button type="submit" name="action" value="reject" class="danger-button">Reject message</button>
                        </details>
                    </form>
                <?php endif; ?>
                        <?php if (!$canReviewMessage || !$selectedRouting): ?>
                            <div class="inbound-filing-state">
                                <p class="inbound-eyebrow">Message status</p>
                                <?php if ($selectedMessage['status'] === 'processed'): ?>
                                    <h3>Saved to Chron</h3><p>This message has been processed and added to its Chron destinations.</p>
                                    <?php if ($selectedMessage['processed_at']): ?><p class="field-help"><?php echo htmlspecialchars(chronLogTimestampDetails($selectedMessage['processed_at'])['display'], ENT_QUOTES, 'UTF-8'); ?><?php if ($selectedMessage['processed_by_username']): ?> by <?php echo htmlspecialchars((string) $selectedMessage['processed_by_username'], ENT_QUOTES, 'UTF-8'); ?><?php endif; ?></p><?php endif; ?>
                                <?php elseif ($selectedMessage['status'] === 'rejected'): ?>
                                    <h3>Message Rejected</h3><p>This message was rejected. Existing Chron entries were preserved.</p>
                                <?php elseif ($selectedMessage['status'] === 'processing'): ?>
                                    <h3>Filing in Progress</h3><p>Automatic routing is working on this message. Refresh the inbox to see its latest status.</p>
                                <?php else: ?>
                                    <h3>Destinations Unavailable</h3><p>We couldn’t load the filing destinations. Refresh the inbox to try again.</p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </aside>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>
<?php include 'templates/footer.php'; ?>
</body>
</html>
