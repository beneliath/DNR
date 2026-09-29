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
        } catch (Throwable $e) { http_response_code(409); echo 'Unable to prepare the package. Check the receipt files and the 15 MB attachment limit.'; }
        finally { if ($path !== null) unlink($path); }
        exit();
    }
    http_response_code(400); exit('Invalid preview.');
}
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
<dl class="reimbursement-email-details"><dt>To</dt><dd><?= reimbursementSubmitH(implode(', ', $recipients['to'] ?? [])) ?></dd><dt>Cc</dt><dd><?= reimbursementSubmitH(implode(', ', $recipients['cc'] ?? [])) ?: 'None' ?></dd><dt>Bcc</dt><dd><?= reimbursementSubmitH(implode(', ', $recipients['bcc'] ?? [])) ?: 'None' ?></dd><dt>Subject</dt><dd><?= reimbursementSubmitH($message['subject']) ?></dd><dt>Attachments</dt><dd><?= reimbursementSubmitH($message['filename']) ?>, <?= reimbursementSubmitH(reimbursementPackageBase($context['request']) . '.pdf') ?>, <?= reimbursementSubmitH(reimbursementPackageBase($context['request']) . '.csv') ?>, and <?= count($context['receipts']) ?> individual receipt files</dd></dl>
<p class="field-help">Bcc recipients are hidden from other recipients. Duplicate email addresses receive one copy.</p>
<div class="reimbursement-actions"><a class="button-secondary" href="reimbursement_submit.php?id=<?= $id ?>&amp;preview=package">Download Package for Review</a><a class="button-secondary" href="profile.php">User Profile</a><?php if (hasRole(['admin'])): ?><a class="button-secondary" href="reimbursement_setup.php">Reimbursement Setup</a><?php endif; ?></div>
</section>
<section class="reimbursement-card"><h2>Email Preview</h2><div class="reimbursement-email-preview" data-reimbursement-email-preview><template><?= preg_replace('~^.*?<body([^>]*)>(.*)</body>.*$~s', '<div$1>$2</div>', $message['html_body']) ?></template><noscript><a href="reimbursement_submit.php?id=<?= $id ?>&amp;preview=email" target="_blank" rel="noopener">Open Email Preview</a></noscript></div></section>
<section class="reimbursement-card"><h2>Submission Readiness</h2>
<?php $receiptExpenseIds=array_unique(array_column($context['receipts'],'expense_id')); $missing=count(array_filter($context['items'],static fn($item)=>!in_array($item['expense_id'],$receiptExpenseIds))); ?>
<p><?= count($context['items']) ?> Expenses · <?= reimbursementMoney(array_sum(array_column($context['items'],'amount_cents'))) ?> · <?= count($context['receipts']) ?> Receipts · <?= number_format(array_sum(array_column($context['receipts'],'size'))/1048576,2) ?> MB of Original Files</p>
<?php if ($missing): ?><p class="warning"><?= $missing ?> expense(s) have no receipt. You may submit, but the bookkeeper may need supporting documentation.</p><?php endif; ?>
<p class="field-help">The ZIP, separate report PDF, CSV, and individual receipts together can be at most 15 MB (approximately 20 MB after email encoding, plus message headers). Download the package to review its contents before submitting.</p>
<h2>Package Contents</h2><p><?= reimbursementSubmitH(reimbursementPackageBase($context['request']).'.pdf') ?><br><?= reimbursementSubmitH(reimbursementPackageBase($context['request']).'.csv') ?> (one row per expense; receipt filenames are separated by semicolons)</p>
<?php foreach ($context['items'] as $item): ?><details open><summary><?= reimbursementSubmitH($item['merchant'].' · '.reimbursementMoney((int)$item['amount_cents'])) ?></summary><ul><?php $found=false; foreach ($context['receipts'] as $receipt): if ((int)$receipt['expense_id']!==(int)$item['expense_id']) continue; $found=true; ?><li class="reimbursement-filename">receipts/<?= reimbursementSubmitH(reimbursementReceiptPackageFilename($receipt)) ?><small>Original: <?= reimbursementSubmitH($receipt['filename']) ?> · <?= number_format($receipt['size']/1024) ?> KB · <?= reimbursementSubmitH(ucfirst($receipt['scan_state'])) ?></small></li><?php endforeach; if (!$found): ?><li>No Receipt Attached</li><?php endif; ?></ul></details><?php endforeach; ?></section>
<?php if ($recipients && $error === ''): ?><section class="reimbursement-card"><form method="post"><?= csrfInput() ?><input type="hidden" name="review_fingerprint" value="<?= reimbursementSubmitH(reimbursementReviewFingerprint($context)) ?>"><label class="reimbursement-confirm"><input type="checkbox" name="confirm_submission" value="1" required> I have reviewed the recipients, email, and package</label><p>Submitting queues the email for delivery and locks this request’s expenses and receipts. Downloads alone do not submit the request.</p><div class="reimbursement-actions"><button type="submit" class="save-button">Submit Reimbursement Request</button><a class="button-secondary" href="reimbursement_request.php?id=<?= $id ?>&amp;mode=edit">Back to Draft</a></div></form></section><?php endif; ?>
<?php endif; ?>
</main><?php include 'templates/footer.php'; ?></body></html>
