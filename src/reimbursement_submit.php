<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/reimbursement_submission_helpers.php';
startSecureSession(); requireLogin();
if (!hasRole(['admin', 'editor'])) { http_response_code(403); exit('This account has read-only access.'); }
$conn = applicationDatabaseConnection();
$userId = (int) $_SESSION['user_id'];
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$id) { http_response_code(400); exit('Request ID required.'); }
// Check ownership before showing any request or recipient information.
$owned = $conn->execute_query('SELECT status, is_archived FROM reimbursement_requests WHERE id = ? AND user_id = ?', [$id, $userId])->fetch_assoc();
if (!$owned) { http_response_code(404); exit('Request not found.'); }
if ($owned['status'] !== 'draft' || (int) $owned['is_archived']) { http_response_code(409); exit('Only an active draft can be submitted.'); }
$error = ''; $context = null; $recipients = null; $message = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    try {
        if (($_POST['confirm_submission'] ?? '') !== '1') throw new InvalidArgumentException('Confirm the recipients and package before submitting.');
        $fingerprint = is_string($_POST['review_fingerprint'] ?? null) ? $_POST['review_fingerprint'] : '';
        queueReimbursementSubmission($conn, $id, $userId, $fingerprint);
        $_SESSION['reimbursement_request_message'] = 'Request submitted. The email and ZIP package are queued for delivery; its expenses and receipts are now locked.';
        header('Location: reimbursement_request.php?id=' . $id, true, 303); exit();
    } catch (InvalidArgumentException $e) { $error = $e->getMessage(); }
    catch (Throwable $e) {
        applicationLog('error', 'Unable to submit reimbursement email', ['request_id' => $id, 'error' => $e->getMessage()]);
        $error = 'Unable to prepare the submission. Nothing was submitted. Please try again.';
    }
}
try {
    $context = reimbursementSubmissionContext($conn, $id, $userId);
    $message = reimbursementSubmissionMessage($context);
    $recipients = reimbursementSubmissionRecipients($context);
    reimbursementReceiptsReady($context['receipts']);
} catch (InvalidArgumentException $e) { if ($error === '') $error = $e->getMessage(); }
$preview = $_GET['preview'] ?? '';
if ($preview !== '') {
    if (!$context || !$message) { http_response_code(409); exit('The request is not ready for review.'); }
    releaseApplicationSessionLock();
    header('Cache-Control: private, no-store');
    if ($preview === 'email') {
        header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'self'; base-uri 'none'; form-action 'none'");
        header('Content-Type: text/html; charset=UTF-8'); echo $message['html_body']; exit();
    }
    if ($preview === 'package') {
        $path = null;
        try {
            $path = reimbursementSubmissionPackage($context, false);
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $message['filename'] . '"');
            header('Content-Length: ' . filesize($path)); readfile($path);
        } catch (Throwable $e) { http_response_code(409); echo 'Unable to prepare the package. Check the receipt files and the 18 MB ZIP attachment limit.'; }
        finally { if ($path !== null) unlink($path); }
        exit();
    }
    http_response_code(400); exit('Invalid preview.');
}
$emailBytes = null; $zipBytes = null;
if ($context && $recipients && $error === '') {
    $sizePath = null;
    try {
        $sizePath = reimbursementBuildPackage($context);
        $zipBytes = filesize($sizePath);
        $emailBytes = reimbursementEmailSize($context, $sizePath);
    } catch (Throwable $e) {
        applicationLog('error', 'Unable to measure reimbursement email', ['request_id' => $id, 'error' => $e->getMessage()]);
        $error = 'Unable to calculate the email size. Submission is disabled. Check the receipts and reload this review.';
    } finally { if ($sizePath !== null) unlink($sizePath); }
}
$sizeAllowed = $emailBytes !== null && $emailBytes <= SMTP_MAX_MESSAGE_BYTES && $zipBytes <= REIMBURSEMENT_MAX_PACKAGE_BYTES;
$emailPercent = $zipBytes !== null ? $zipBytes / REIMBURSEMENT_MAX_PACKAGE_BYTES * 100 : null;
function reimbursementSubmitH(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html><html lang="en">
<?php renderPageHead(applicationPageTitle('Submit Reimbursement Request'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/reimbursements.css'], 'scripts' => ['assets/js/reimbursements.js']]); ?>
<body><?php include 'templates/header.php'; ?><main class="container reimbursement-page">
<nav class="breadcrumb" aria-label="Breadcrumb"><a href="reimbursements.php">Reimbursements</a><span aria-hidden="true">/</span><a href="reimbursement_requests.php">Requests</a><span aria-hidden="true">/</span><span>Review Submission</span></nav>
<div class="page-heading"><div><h1>Review Reimbursement Email</h1><p class="page-intro">Check the recipients, message, and ZIP package before submitting.</p></div></div>
<?php if ($error !== ''): ?><p class="error" role="alert"><?= reimbursementSubmitH($error) ?></p><?php endif; ?>
<?php if ($context && $message): ?>
<section class="reimbursement-card"><h2>Email Details</h2>
<dl class="reimbursement-email-details"><dt>To</dt><dd><?= reimbursementSubmitH(implode(', ', $recipients['to'] ?? [])) ?></dd><dt>Cc</dt><dd><?= reimbursementSubmitH(implode(', ', $recipients['cc'] ?? [])) ?: 'None' ?></dd><dt>Bcc</dt><dd><?= reimbursementSubmitH(implode(', ', $recipients['bcc'] ?? [])) ?: 'None' ?></dd><dt>Subject</dt><dd><?= reimbursementSubmitH($message['subject']) ?></dd><dt>Attachments</dt><dd><?= reimbursementSubmitH($message['filename']) ?> (ZIP containing the expense report PDF, CSV, and all original receipts)</dd></dl>
<p class="field-help">Bcc recipients are hidden from other recipients. Duplicate email addresses receive one copy.</p>
<div class="reimbursement-actions"><a class="button-secondary" href="reimbursement_submit.php?id=<?= $id ?>&amp;preview=package">Download Package for Review</a><a class="button-secondary" href="profile.php">User Profile</a><?php if (hasRole(['admin'])): ?><a class="button-secondary" href="reimbursement_setup.php">Reimbursement Setup</a><?php endif; ?></div>
</section>
<section class="reimbursement-card"><h2>Email Preview</h2><div class="reimbursement-email-preview" data-reimbursement-email-preview><template><?= preg_replace('~^.*?<body([^>]*)>(.*)</body>.*$~s', '<div$1>$2</div>', $message['html_body']) ?></template><noscript><a href="reimbursement_submit.php?id=<?= $id ?>&amp;preview=email" target="_blank" rel="noopener">Open Email Preview</a></noscript></div></section>
<section class="reimbursement-card"><h2>Submission Readiness</h2>
<?php $receiptExpenseIds=array_unique(array_column($context['receipts'],'expense_id')); $missing=count(array_filter($context['items'],static fn($item)=>!in_array($item['expense_id'],$receiptExpenseIds))); ?>
<p><?= count($context['items']) ?> Expenses · <?= reimbursementMoney(array_sum(array_column($context['items'],'amount_cents'))) ?> · <?= count($context['receipts']) ?> Receipts · <?= number_format(array_sum(array_column($context['receipts'],'size'))/1048576,2) ?> MB of Original Files</p>
<?php if ($missing): ?><p class="warning"><?= $missing ?> expense(s) have no receipt. You may submit, but the bookkeeper may need supporting documentation.</p><?php endif; ?>
<p class="field-help">Only the ZIP package is attached. It contains the expense report PDF, CSV, and all original receipts. The complete email must fit within 25 MB, including the message, headers, and email encoding; the ZIP is limited to 18 MB to leave room for encoding and message content. Download the package to review its contents before submitting.</p>
<div class="reimbursement-size <?= $sizeAllowed ? 'reimbursement-size-ok' : 'reimbursement-size-over' ?>">
<h3 id="email-size-label">Email Attachment Size</h3>
<?php if ($emailBytes !== null): ?>
<p id="email-size-value"><strong><?= number_format($emailPercent, 1) ?>%</strong> of the 18 MB ZIP limit · <?= number_format($zipBytes / 1000000, 2) ?> MB / 18 MB</p>
<?php $gaugePercent = min(100, max(0, $emailPercent)); $gaugeAngle = M_PI * (1 - $gaugePercent / 100); ?>
<div class="reimbursement-size-gauge" role="meter" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= round($gaugePercent, 1) ?>" aria-labelledby="email-size-label" aria-describedby="email-size-value email-size-status" aria-valuetext="<?= reimbursementSubmitH(number_format($emailPercent, 1) . '% of the 18 MB ZIP limit') ?>">
<svg viewBox="0 0 320 216" aria-hidden="true" focusable="false">
<defs><linearGradient id="email-size-fill" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#86b9eb" stop-opacity=".55"/><stop offset="100%" stop-color="#86b9eb" stop-opacity=".03"/></linearGradient></defs>
<path d="M 28 158 A 132 132 0 0 1 292 158 Z" fill="url(#email-size-fill)"/>
<path class="reimbursement-gauge-grid" d="M 28 158 H 292 M 160 26 V 158 M 66.66 64.66 L 85 83 M 253.34 64.66 L 235 83"/>
<path class="reimbursement-gauge-outline" d="M 28 158 A 132 132 0 0 1 292 158"/>
<path class="reimbursement-gauge-progress" d="M 28 158 A 132 132 0 0 1 292 158" pathLength="100" stroke-dasharray="<?= $gaugePercent ?> 100"/>
<line class="reimbursement-gauge-needle <?= $gaugePercent <= 50 ? 'reimbursement-gauge-low' : ($gaugePercent <= 75 ? 'reimbursement-gauge-medium' : 'reimbursement-gauge-high') ?>" x1="160" y1="158" x2="<?= round(160 + 110 * cos($gaugeAngle), 2) ?>" y2="<?= round(158 - 110 * sin($gaugeAngle), 2) ?>"/>
<circle cx="160" cy="158" r="5" fill="#74ade6"/>
<text class="reimbursement-gauge-label" x="28" y="183" text-anchor="middle">0%</text>
<text class="reimbursement-gauge-label" x="292" y="183" text-anchor="middle">100%</text>
<text class="reimbursement-gauge-value" x="160" y="207" text-anchor="middle"><?= number_format($gaugePercent, 1) ?>%</text>
</svg>
</div>
<p>Total encoded email: <?= number_format($emailBytes / 1000000, 2) ?> MB / 25 MB, including headers, message, and encoding.</p>
<p id="email-size-status"><?= $sizeAllowed ? 'Within both size limits.' : 'Over the limit. Reduce receipt sizes or split this draft before submitting.' ?></p>
<?php else: ?><p id="email-size-status">Email size unavailable. Submission is disabled until the size can be verified.</p><?php endif; ?>
</div>
<h2>Package Contents</h2><p><?= reimbursementSubmitH(reimbursementPackageBase($context['request']).'.pdf') ?><br><?= reimbursementSubmitH(reimbursementPackageBase($context['request']).'.csv') ?> (one row per expense; receipt filenames are separated by semicolons)</p>
<?php foreach ($context['items'] as $item): ?><details open><summary><?= reimbursementSubmitH($item['merchant'].' · '.reimbursementMoney((int)$item['amount_cents'])) ?></summary><ul><?php $found=false; foreach ($context['receipts'] as $receipt): if ((int)$receipt['expense_id']!==(int)$item['expense_id']) continue; $found=true; ?><li class="reimbursement-filename">receipts/<?= reimbursementSubmitH(reimbursementReceiptPackageFilename($receipt)) ?><small>Original: <?= reimbursementSubmitH($receipt['filename']) ?> · <?= number_format($receipt['size']/1024) ?> KB · <?= reimbursementSubmitH(ucfirst($receipt['scan_state'])) ?></small></li><?php endforeach; if (!$found): ?><li>No Receipt Attached</li><?php endif; ?></ul></details><?php endforeach; ?></section>
<?php if ($recipients): ?><section class="reimbursement-card"><form method="post"><?= csrfInput() ?><input type="hidden" name="review_fingerprint" value="<?= reimbursementSubmitH(reimbursementReviewFingerprint($context)) ?>"><label class="reimbursement-confirm"><input type="checkbox" name="confirm_submission" value="1" required> I have reviewed the recipients, email, and package</label><p>Submitting queues the email for delivery and locks this request’s expenses and receipts. Downloads alone do not submit the request.</p><div class="reimbursement-actions"><button type="submit" class="save-button" data-reimbursement-progress data-submitting-label="Submitting Request…"<?= (!$sizeAllowed || $error !== '') ? ' disabled aria-describedby="email-size-status"' : '' ?>>Submit Reimbursement Request</button><a class="button-secondary" href="reimbursement_request.php?id=<?= $id ?>&amp;mode=edit">Back to Draft</a></div><p class="invitation-submit-status" data-reimbursement-progress-status role="status" hidden><span class="invitation-submit-spinner" aria-hidden="true"></span><span>Preparing your package and submitting the reimbursement request… Please wait.</span></p></form></section><?php endif; ?>
<?php endif; ?>
</main><?php include 'templates/footer.php'; ?></body></html>
