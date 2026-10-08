<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/two_factor_helpers.php';
require_once __DIR__ . '/inbound_email_helpers.php';
require_once __DIR__ . '/account_mail_helpers.php';
startSecureSession();
requireSuperAdmin();
header('Cache-Control: no-store');
if (!accountMailEnabled()) { http_response_code(503); exit('Shared mail review has not been enabled yet.'); }
if (!accountIsPrimary()) { header('Location: ' . accountPrimaryPublicUrl() . '/mail_review.php'); exit; }
$error = '';
$notice = $_SESSION['mail_review_notice'] ?? '';
unset($_SESSION['mail_review_notice']);
$mailReviewAccounts = array_values(array_filter(platformAccountDirectory($conn), static fn(array $account): bool => $account['state'] === 'ready'));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    requireRecentAdminElevation('mail_review.php');
    $locked = false;
    try {
        $id = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT);
        $action = $_POST['action'] ?? '';
        if (!$id || !in_array($action, ['retry', 'reject', 'route', 'delete'], true)) throw new InvalidArgumentException('Choose a valid review action.');
        $locked = (int) $conn->query("SELECT GET_LOCK('moed-platform-mail-delivery',5)")->fetch_row()[0] === 1;
        if (!$locked) throw new InvalidArgumentException('Mail delivery is running. Please try again shortly.');
        $conn->begin_transaction();
        $row = $conn->execute_query("SELECT * FROM platform_inbound_mail WHERE id=? AND payload IS NOT NULL AND status IN ('review','pending','rejected') FOR UPDATE", [$id])->fetch_assoc();
        if (!$row) throw new InvalidArgumentException('This message is no longer available.');
        if ($action === 'delete' && $row['status'] !== 'rejected') throw new InvalidArgumentException('Only rejected messages can be deleted.');
        if ($action !== 'delete' && $row['status'] === 'rejected') throw new InvalidArgumentException('This message is no longer awaiting review.');
        $payload = json_decode($row['payload'], true, 16, JSON_THROW_ON_ERROR);
        if ($action === 'retry') {
            if ((int) $row['attempts'] > 0 && !isset($payload['delivery_account_key'])) {
                $payload['delivery_account_key'] = $row['account_key'] ?? '';
            }
            $route = platformMailPayloadRoute($conn, $payload);
            $conn->execute_query('UPDATE platform_inbound_mail SET status=?, account_key=?, review_reason=?, payload=?, retry_after=NULL, attempts=0, reviewed_by=? WHERE id=?',
                [$route['account_key'] === null ? 'review' : 'pending', $route['account_key'], $route['reason'], inboundEmailJson($payload), (int) $_SESSION['user_id'], $id]);
            $notice = $route['account_key'] === null ? 'The message still needs review.' : 'The message is queued for delivery.';
        } elseif ($action === 'route') {
            if (platformMailDeliveryHasStarted($row, $payload)) {
                throw new InvalidArgumentException('Delivery has already been attempted. Use Check Routing Again to retry the existing destination.');
            }
            $accountKey = is_string($_POST['account_key'] ?? null) ? $_POST['account_key'] : '';
            $account = array_column($mailReviewAccounts, null, 'account_key')[$accountKey] ?? null;
            if (!$account) throw new InvalidArgumentException('Choose an available Account.');
            $payload['manual_routing'] = platformMailManualAssignment($conn, accountMailUnpack($payload['message']), $accountKey, (int) $_SESSION['user_id']);
            $conn->execute_query("UPDATE platform_inbound_mail SET status='pending', account_key=?, payload=?,
                review_reason='', retry_after=NULL, attempts=0, reviewed_by=? WHERE id=?",
                [$accountKey, inboundEmailJson($payload), (int) $_SESSION['user_id'], $id]);
            $notice = 'Message queued for ' . $account['name'] . '. It will appear in that Account’s Inbox under Needs Review.';
        } elseif ($action === 'delete') {
            // Keep the fingerprint/source receipt so a future mailbox scan cannot resurrect deleted mail.
            $conn->execute_query("UPDATE platform_inbound_mail SET payload=NULL, review_reason='', reviewed_by=? WHERE id=?", [(int) $_SESSION['user_id'], $id]);
            $notice = 'Rejected message permanently deleted.';
        } else {
            $conn->execute_query("UPDATE platform_inbound_mail SET status='rejected', reviewed_by=? WHERE id=?", [(int) $_SESSION['user_id'], $id]);
            $notice = 'Message rejected.';
        }
        logSecurityEvent($conn, 'platform_mail_' . $action, null, (int) $_SESSION['user_id']);
        $conn->commit();
        $_SESSION['mail_review_notice'] = $notice;
        $conn->query("DO RELEASE_LOCK('moed-platform-mail-delivery')"); $locked = false;
        $nextStatus = in_array($action, ['reject', 'delete'], true) ? 'rejected' : (($action === 'route' || $route['account_key'] !== null) ? 'pending' : 'review');
        header('Location: mail_review.php?' . http_build_query(['status' => $nextStatus, 'id' => $action === 'delete' ? null : $id]), true, 303); exit;
    } catch (Throwable $exception) {
        $conn->rollback();
        $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The review action could not be completed.';
    } finally {
        if ($locked) $conn->query("DO RELEASE_LOCK('moed-platform-mail-delivery')");
    }
}
$status = \Dnr\Http\RequestInput::enum($_GET, 'status', ['review','pending','rejected'], 'review');
$page = max(1, (int) ($_GET['page'] ?? 1));
$count = (int) $conn->execute_query('SELECT COUNT(*) FROM platform_inbound_mail WHERE status=? AND payload IS NOT NULL', [$status])->fetch_row()[0];
$mailReviewPageCount = max(1, (int) ceil($count / 20)); $page = min($page, $mailReviewPageCount);
$rows = platformMailReviewRows($conn, $status, ($page-1)*20);
$id = filter_var($_POST['id'] ?? $_GET['id'] ?? ($rows[0]['id'] ?? null), FILTER_VALIDATE_INT);
$selected = $id ? $conn->execute_query("SELECT * FROM platform_inbound_mail WHERE id=? AND status<>'delivered' AND payload IS NOT NULL", [$id])->fetch_assoc() : null;
$mailReviewStatusLabels = ['review' => 'Needs Review', 'pending' => 'Pending Account Delivery', 'rejected' => 'Rejected'];
$mailReviewCounts = array_fill_keys(array_keys($mailReviewStatusLabels), 0);
foreach ($conn->query("SELECT status, COUNT(*) AS total FROM platform_inbound_mail WHERE status<>'delivered' AND payload IS NOT NULL GROUP BY status")->fetch_all(MYSQLI_ASSOC) as $mailReviewTotal) {
    $mailReviewCounts[$mailReviewTotal['status']] = (int) $mailReviewTotal['total'];
}
function mailReviewH(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
/** Only the display title omits routing markers; the original subject remains in Message details. */
function mailReviewSubject(string $subject): string {
    return trim(preg_replace('/\[(?:MOED@|[A-Z][A-Z0-9_-]{1,19}#)[^\]\r\n]*(?:\]|$)/i', '', $subject) ?? $subject) ?: '(No subject)';
}
function mailReviewReasonLabel(string $reason): string {
    return match (true) {
        str_contains($reason, 'No valid') => 'Missing routing token',
        str_contains($reason, 'more than one') => 'Conflicting Accounts',
        str_contains($reason, 'invalid, incomplete') => 'Invalid or incomplete token',
        str_contains($reason, 'manual destination') => 'Destination unavailable',
        default => 'Delivery needs attention',
    };
}
?>
<!DOCTYPE html><html lang="en">
<?php renderPageHead(applicationPageTitle('Mail Review'), ['styles' => ['assets/css/style.min.css','assets/css/modern.min.css','assets/css/pages/mail_review.min.css']]); ?>
<body><?php include 'templates/header.php'; ?>
<main class="container mail-review-page">
<header class="mail-review-heading">
    <div><p class="mail-review-eyebrow">SuperAdmin · Shared Mailbox</p><h1>Mail Review</h1><p>Review unassigned mail and choose the right Account.</p></div>
    <a class="button-secondary mail-review-refresh" href="mail_review.php?<?= mailReviewH(http_build_query(['status'=>$status,'page'=>$page,'id'=>$id])) ?>"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-1l2 6M4 12l2 6a7 7 0 0 0 12-1"/></svg>Refresh</a>
</header>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= mailReviewH($error) ?></p><?php endif; ?>
<?php if ($notice !== '' && $error === ''): ?><p class="success" role="status"><?= mailReviewH($notice) ?></p><?php endif; ?>
<nav class="mail-review-filters" aria-label="Mail status">
<?php foreach ($mailReviewStatusLabels as $mailReviewStatus=>$mailReviewLabel): ?>
    <a class="mail-review-filter<?= $status===$mailReviewStatus?' is-active':'' ?>" href="mail_review.php?status=<?= $mailReviewStatus ?>"<?= $status===$mailReviewStatus?' aria-current="page"':'' ?>><?= $mailReviewLabel ?><span><?= $mailReviewCounts[$mailReviewStatus] ?></span></a>
<?php endforeach; ?>
</nav>
<div class="mail-review-workspace">
<section class="mail-review-queue" aria-label="Shared mail queue">
    <header class="mail-review-queue-heading"><h2><?= $mailReviewStatusLabels[$status] ?></h2><span>Newest first · UTC</span></header>
    <div class="mail-review-message-list">
    <?php if ($rows === []): ?>
        <div class="mail-review-empty"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="m4 4 16 0v16H4zM4 13h4l2 3h4l2-3h4"/></svg><h3><?= $status==='review'?'All Caught Up':'No Messages Here' ?></h3><p><?= $status==='review'?'New mail that needs your decision will appear here.':'Messages with this status will appear here.' ?></p></div>
    <?php else: foreach ($rows as $row): $message=$row; ?>
        <a class="mail-review-message<?= (int)$id===(int)$row['id']?' is-selected':'' ?>" href="mail_review.php?<?= mailReviewH(http_build_query(['status'=>$status,'page'=>$page,'id'=>$row['id']])) ?>#mail-review-detail"<?= (int)$id===(int)$row['id']?' aria-current="true"':'' ?>>
            <span class="mail-review-message-meta"><span><?= mailReviewH($message['sender_name'] ?: $message['sender_address']) ?></span><time datetime="<?= mailReviewH(str_replace(' ','T',$row['received_at']).'Z') ?>"><?= mailReviewH(substr($row['received_at'],5,11)) ?></time></span>
            <strong><?= mailReviewH(mailReviewSubject($message['subject'])) ?></strong>
            <span class="mail-review-message-reason"><?= $status==='review'?mailReviewH(mailReviewReasonLabel($row['review_reason'])):$mailReviewStatusLabels[$status] ?></span>
        </a>
    <?php endforeach; endif; ?></div>
    <footer class="mail-review-pagination">
        <span>Page <?= $page ?> of <?= $mailReviewPageCount ?> · <?= $count ?> <?= $count===1?'message':'messages' ?></span>
        <?php if ($mailReviewPageCount>1): ?><div><?php if ($page>1): ?><a class="button-secondary" href="mail_review.php?status=<?= $status ?>&amp;page=<?= $page-1 ?>">Previous</a><?php endif; ?><?php if ($page<$mailReviewPageCount): ?><a class="button-secondary" href="mail_review.php?status=<?= $status ?>&amp;page=<?= $page+1 ?>">Next</a><?php endif; ?></div><?php endif; ?>
    </footer>
</section>
<section class="mail-review-detail" id="mail-review-detail" aria-label="Message for review">
<?php if ($selected): $selectedPayload=json_decode($selected['payload'],true,16,JSON_THROW_ON_ERROR); $message=$selectedPayload['message']; ?>
    <header class="mail-review-message-heading">
        <div class="mail-review-detail-meta"><span class="mail-review-badge mail-review-badge-<?= mailReviewH($selected['status']) ?>"><?= $mailReviewStatusLabels[$selected['status']] ?></span><span>Message #<?= (int)$selected['id'] ?></span></div>
        <h2><?= mailReviewH(mailReviewSubject($message['subject'])) ?></h2>
        <p class="mail-review-sender"><?= mailReviewH($message['sender_address']) ?></p>
        <details class="mail-review-envelope"><summary>Message details</summary><dl>
            <div><dt>To</dt><dd><?= mailReviewH(implode(', ', $message['to_addresses'])) ?></dd></div>
            <?php if ($message['cc_addresses'] !== []): ?><div><dt>Cc</dt><dd><?= mailReviewH(implode(', ', $message['cc_addresses'])) ?></dd></div><?php endif; ?>
            <div><dt>Received</dt><dd><?= mailReviewH($selected['received_at']) ?> UTC</dd></div>
            <div><dt>Original subject</dt><dd><?= mailReviewH($message['subject'] ?: '(No subject)') ?></dd></div>
        </dl></details>
    </header>
    <?php if ($selected['status']==='review'): ?><div class="mail-review-reason"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v6m0 3v1"/></svg><div><strong><?= mailReviewH(mailReviewReasonLabel($selected['review_reason'])) ?></strong><p><?= mailReviewH($selected['review_reason']) ?> This message is visible only to SuperAdmins until routed.</p></div></div><?php endif; ?>
    <div class="mail-review-body"><pre><?= mailReviewH($message['body_text'] ?: '(This message has no text body.)') ?></pre></div>
    <?php if (in_array($selected['status'],['review','pending'],true)): ?>
    <section class="mail-review-resolution" aria-labelledby="mail-route-heading">
        <?php if (!platformMailDeliveryHasStarted($selected, $selectedPayload)): ?>
        <div class="mail-review-route-intro"><span class="mail-review-route-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 6h9a4 4 0 0 1 4 4v8m-4-4 4 4 4-4M4 3v6"/></svg></span><div><h3 id="mail-route-heading">Route to Account</h3><p id="mail-route-help">Send to the Account’s Inbox for review. No records are updated automatically.</p></div></div>
        <form method="post" class="mail-review-route-form" data-admin-unlock-required>
            <?= csrfInput() ?><input type="hidden" name="id" value="<?= (int)$selected['id'] ?>">
            <label for="mail-route-account">Destination Account</label>
            <div class="mail-review-route-controls"><div class="mail-review-route-field"><select id="mail-route-account" name="account_key" required aria-describedby="mail-route-help"><option value="">Select an Account</option>
            <?php foreach ($mailReviewAccounts as $mailReviewAccount): ?><option value="<?= mailReviewH($mailReviewAccount['account_key']) ?>"><?= mailReviewH($mailReviewAccount['name']) ?></option><?php endforeach; ?></select></div>
            <button type="submit" id="mail-route-submit" class="save-button" name="action" value="route">Route to Account <span aria-hidden="true">→</span></button>
            </div>
        </form>
        <?php else: ?><h3 id="mail-route-heading">Delivery in Progress</h3><p>Delivery has already been attempted. Check Routing Again retries the existing destination without creating a copy in another Account.</p><?php endif; ?>
        <div class="mail-review-other-actions">
            <form method="post" data-admin-unlock-required><?= csrfInput() ?><input type="hidden" name="id" value="<?= (int)$selected['id'] ?>"><button type="submit" class="button-secondary" name="action" value="retry">Check Routing Again</button></form>
            <form method="post" data-admin-unlock-required><?= csrfInput() ?><input type="hidden" name="id" value="<?= (int)$selected['id'] ?>"><button type="submit" class="button-secondary mail-review-reject" name="action" value="reject"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m6 6 12 12"/></svg>Reject Message</button><span>Keeps the message in Rejected.</span></form>
        </div>
    </section>
    <?php else: ?><div class="mail-review-rejected-note"><div><strong>Rejected</strong><p>No further delivery attempts will be made. You can permanently delete this message.</p></div>
        <form method="post" data-admin-unlock-required data-confirm="Permanently delete this rejected message? Its contents cannot be recovered from Mail Review. A fingerprint is retained to prevent reimporting the same email.">
            <?= csrfInput() ?><input type="hidden" name="id" value="<?= (int)$selected['id'] ?>"><input type="hidden" name="action" value="delete">
            <button type="submit" class="action-button action-icon-button delete-button" aria-label="Delete rejected message" title="Delete rejected message" data-tooltip="Delete rejected message"><?= actionIconSvg('delete') ?></button>
        </form></div><?php endif; ?>
<?php else: ?><div class="mail-review-empty mail-review-empty-reader"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/></svg><h2><?= $rows===[]?'Nothing to Review':'Select a Message' ?></h2><p><?= $rows===[]?'Messages will appear here when they need your attention.':'Choose a message from the queue to read it and select an Account.' ?></p></div><?php endif; ?>
</section>
</div>
<p class="mail-review-privacy">Only SuperAdmins can access this queue. Account users see a message only after it has been routed to their Account.</p>
</main><?php include 'templates/footer.php'; ?></body></html>
