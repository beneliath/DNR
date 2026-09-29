<?php

declare(strict_types=1);
require_once __DIR__ . '/reimbursement_pdf.php';
require_once __DIR__ . '/email_helpers.php';
require_once __DIR__ . '/email_ascii_footer.php';

/** The review fingerprint covers every value used for recipients, email, and ZIP. */
function reimbursementSubmissionContext(mysqli $conn, int $id, int $userId, bool $lock = false): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $request = $conn->execute_query('SELECT * FROM reimbursement_requests WHERE id = ? AND user_id = ?' . $suffix, [$id, $userId])->fetch_assoc();
    if (!$request) throw new InvalidArgumentException('Request not found.');
    if ($request['status'] !== 'draft' || (int) $request['is_archived']) throw new InvalidArgumentException('Only an active draft can be submitted.');
    $owner = $conn->execute_query('SELECT id, username, first_name, last_name, email, reimbursement_reviewer_email FROM users WHERE id = ?' . $suffix, [$userId])->fetch_assoc();
    $owner['display_name'] = trim($owner['first_name'] . ' ' . $owner['last_name']) ?: $owner['username'];
    $setup = $conn->query('SELECT * FROM reimbursement_setup WHERE id = 1' . $suffix)->fetch_assoc();
    if (!$setup) throw new RuntimeException('Reimbursement Setup is unavailable.');
    $items = $conn->execute_query('SELECT e.id AS expense_id, e.expense_date, e.merchant, e.description, e.amount_cents,
        c.coa_number, c.description AS coa_description
        FROM reimbursement_request_items ri JOIN reimbursement_expenses e ON e.id = ri.expense_id
        JOIN reimbursement_cost_centers c ON c.id = e.cost_center_id
        WHERE ri.request_id = ? ORDER BY e.expense_date, e.id' . $suffix, [$id])->fetch_all(MYSQLI_ASSOC);
    if (!$items) throw new InvalidArgumentException('Add expenses before submitting this request.');
    $receipts = $conn->execute_query('SELECT rr.id, rr.expense_id, rr.storage_key, rr.filename, rr.content_type, rr.scan_state, sf.size, sf.checksum
        FROM reimbursement_receipts rr JOIN stored_files sf ON sf.storage_key = rr.storage_key
        JOIN reimbursement_request_items ri ON ri.expense_id = rr.expense_id
        WHERE ri.request_id = ? ORDER BY rr.expense_id, rr.id' . $suffix, [$id])->fetch_all(MYSQLI_ASSOC);
    return compact('request', 'owner', 'setup', 'items', 'receipts');
}

function reimbursementReviewFingerprint(array $context): string
{
    return hash('sha256', serialize($context));
}

function reimbursementSubmissionRecipients(array $context): array
{
    $setup = $context['setup']; $owner = $context['owner'];
    if (trim($setup['bookkeeper_email']) === '') throw new InvalidArgumentException('Enter the bookkeeper email in Reimbursement Setup before submitting.');
    if (trim((string) $owner['email']) === '') throw new InvalidArgumentException('Add your email address in User Profile before submitting so you receive a copy.');
    $to = [normalizeAccountEmail($setup['bookkeeper_email'])];
    $cc = []; $bcc = []; $seen = array_fill_keys($to, true);
    foreach ([$owner['reimbursement_reviewer_email'], $setup['cc_email'], $owner['email']] as $address) {
        if (trim((string) $address) === '') continue;
        $address = normalizeAccountEmail($address);
        if (!isset($seen[$address])) { $cc[] = $address; $seen[$address] = true; }
    }
    if (trim($setup['reviewer_email']) !== '') {
        $address = normalizeAccountEmail($setup['reviewer_email']);
        if (!isset($seen[$address])) $bcc[] = $address;
    }
    return compact('to', 'cc', 'bcc');
}

function reimbursementPackageBase(array $request): string
{
    return 'reimbursement-' . $request['request_hash'] . '-' . $request['start_date'] . '-to-' . $request['end_date'];
}

function createReimbursementZip(string $pdf, array $receipts, string $base): string
{
    $path = tempnam(sys_get_temp_dir(), 'dnr-reimbursement-');
    if (!$path) throw new RuntimeException('Unable to stage reimbursement package.');
    try {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) throw new RuntimeException('Unable to create reimbursement package.');
        try {
            if (!$zip->addFromString($base . '.pdf', $pdf)) throw new RuntimeException('Unable to add report.');
            foreach ($receipts as $receipt) {
                $file = openPersistentFile($receipt, true); fclose($file);
                $entry = 'receipts/' . reimbursementReceiptPackageFilename($receipt);
                if (!$zip->addFile(persistentFilePath($receipt['storage_key']), $entry)) throw new RuntimeException('Unable to add receipt.');
            }
        } catch (Throwable $e) { $zip->close(); throw $e; }
        if (!$zip->close()) throw new RuntimeException('Unable to finish reimbursement package.');
        return $path;
    } catch (Throwable $e) { @unlink($path); throw $e; }
}

function reimbursementSubmissionPackage(array $context): string
{
    reimbursementReceiptsReady($context['receipts']);
    $receipts = [];
    foreach ($context['receipts'] as $receipt) $receipts[(int) $receipt['expense_id']][] = $receipt;
    $request = $context['request']; $request['status'] = 'submitted';
    $pdf = renderReimbursementPdf($request, $context['owner'], $context['items'], $receipts, $context['setup']);
    $path = createReimbursementZip($pdf, $context['receipts'], reimbursementPackageBase($request));
    if (filesize($path) > 15 * 1024 * 1024) {
        unlink($path);
        throw new InvalidArgumentException('The ZIP package exceeds the 15 MB email attachment limit. Reduce receipt sizes or split this draft before submitting.');
    }
    return $path;
}

function reimbursementSubmissionMessage(array $context, bool $forDelivery = false): array
{
    $h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $owner = $context['owner']; $request = $context['request']; $setup = $context['setup'];
    $note = reimbursementSubmissionNote($request['bookkeeper_note'] ?? '');
    $name = $owner['display_name'];
    $total = reimbursementMoney(array_sum(array_column($context['items'], 'amount_cents')));
    $subject = 'Reimbursement request ' . $request['request_hash'] . ' — ' . $name;
    $greeting = trim($setup['bookkeeper_first_name'] . ' ' . $setup['bookkeeper_last_name']) ?: 'Bookkeeper';
    $filename = reimbursementPackageBase($request) . '.zip';
    $range = $request['start_date'] . ' to ' . $request['end_date'];
    $body = "Hello {$greeting},\n\nPlease process the reimbursement request from {$name}.\n\nRequest: {$request['request_hash']}\nExpense Dates: {$range}\nExpenses: " . count($context['items']) . "\nReceipts: " . count($context['receipts']) . "\nTotal Amount Due: {$total}\n\n";
    if ($note !== '') $body .= "Note from {$name} to the bookkeeper:\n{$note}\n\n";
    $receiptsByExpense = [];
    foreach ($context['receipts'] as $receipt) $receiptsByExpense[(int) $receipt['expense_id']][] = reimbursementReceiptPackageFilename($receipt);
    $rows = '';
    foreach ($context['items'] as $item) {
        $amount = reimbursementMoney((int) $item['amount_cents']);
        $receiptNames = $receiptsByExpense[(int) $item['expense_id']] ?? [];
        $receiptText = $receiptNames ? implode('; ', $receiptNames) : 'No Receipt Attached';
        $receiptHtml = $receiptNames ? implode('<br>', array_map($h, $receiptNames)) : 'No Receipt Attached';
        $body .= $item['expense_date'] . ' | ' . $item['merchant'] . ' | ' . $item['coa_number'] . ' | ' . $amount . "\nReceipt Files: " . $receiptText . "\n";
        $rows .= '<tr><td style="padding:10px;border-bottom:1px solid #dfe4ec">' . $h($item['expense_date']) . '</td><td style="padding:10px;border-bottom:1px solid #dfe4ec">' . $h($item['merchant']) . '<br><span style="display:block;font-size:12px;overflow-wrap:anywhere;word-break:break-word"><strong>Receipt Files:</strong><br>' . $receiptHtml . '</span></td><td style="padding:10px;border-bottom:1px solid #dfe4ec">' . $h($item['coa_number'] . ' · ' . $item['coa_description']) . '</td><td style="padding:10px;border-bottom:1px solid #dfe4ec;white-space:nowrap">' . $h($amount) . '</td></tr>';
    }
    $body .= "\nAttached: {$filename}\nReceipt filenames above match the files in the ZIP receipts/ folder. The ZIP contains the expense report PDF and all " . count($context['receipts']) . " receipt files.\n\nThank you,\n{$name}\n";
    $body .= "\n" . emailAsciiFooterText() . "\n";
    $logo = $h($forDelivery ? emailBrandLogoUrl() : applicationPublicUrl(applicationBrandEmailLogo()));
    $inlineImages = [];
    $noteHtml = $note === '' ? '' : '<p style="white-space:pre-wrap;overflow-wrap:anywhere"><strong>Note to the bookkeeper from ' . $h($name) . ':</strong><br>' . $h($note) . '</p>';
    $html = '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . $h($subject) . '</title></head><body style="margin:0;background:#f6f7fb;color:#172033;font:15px/1.6 Arial,sans-serif"><table role="presentation" width="100%" style="background:#f6f7fb"><tr><td align="center" style="padding:24px 12px"><table role="presentation" width="720" style="width:100%;max-width:720px"><tr><td style="padding:20px 24px;background:#fff;border:1px solid #dfe4ec;border-radius:14px"><img src="' . $logo . '" alt="' . $h(applicationBrandLabel()) . '" width="227" height="39" border="0" style="display:block;width:227px;max-width:100%;height:auto;border:0;outline:none;text-decoration:none;"><h1 style="font-size:24px;margin:16px 0 0">Reimbursement Request</h1><p style="color:#667085;margin:4px 0">' . $h($setup['organization_name']) . '</p></td></tr><tr><td style="height:16px"></td></tr><tr><td style="padding:24px;background:#fff;border:1px solid #dfe4ec;border-radius:14px"><p>Hello ' . $h($greeting) . ',</p><p>Please process the reimbursement request from <strong>' . $h($name) . '</strong>.</p><p><strong>Request:</strong> ' . $h($request['request_hash']) . '<br><strong>Expense Dates:</strong> ' . $h($range) . '<br><strong>Expenses:</strong> ' . count($context['items']) . ' · <strong>Receipts:</strong> ' . count($context['receipts']) . '</p><div style="background:#eff4ff;border-radius:10px;padding:16px;color:#2457d6"><strong>Total Amount Due: ' . $h($total) . '</strong></div><div data-reimbursement-note-preview>' . $noteHtml . '</div><table width="100%" style="border-collapse:collapse;font-size:14px;margin:18px 0"><thead><tr><th align="left">Date</th><th align="left">Merchant / Payee</th><th align="left">Account</th><th align="left">Amount</th></tr></thead><tbody>' . $rows . '</tbody></table><p><strong>Attachment:</strong> ' . $h($filename) . '<br>Receipt filenames above match the files in the ZIP receipts/ folder. The ZIP contains the expense report PDF and all ' . count($context['receipts']) . ' receipt files.</p><p>Thank you,<br>' . $h($name) . '</p></td></tr><tr><td style="padding:16px;text-align:center;color:#667085;font-size:12px">Sent through ' . $h(applicationBrandName()) . '</td></tr><tr><td align="center" style="padding:18px 8px 2px">' . renderEmailAsciiFooter() . '</td></tr></table></td></tr></table></body></html>';
    return ['subject' => $subject, 'body' => $body, 'html_body' => $html, 'filename' => $filename, 'inline_images' => $inlineImages];
}

function queueReimbursementSubmission(mysqli $conn, int $id, int $userId, string $fingerprint): void
{
    $path = null;
    // Rendering and hashing do not hold database row locks. Revalidate every
    // approved value under locks immediately before publishing the artifact.
    $context = reimbursementSubmissionContext($conn, $id, $userId);
    if (!hash_equals(reimbursementReviewFingerprint($context), $fingerprint)) throw new InvalidArgumentException('The request, receipts, or recipients changed. Review the updated email before submitting.');
    $recipients = reimbursementSubmissionRecipients($context);
    $message = reimbursementSubmissionMessage($context, true);
    try {
        lockPersistentFiles();
        $path = reimbursementSubmissionPackage($context);
        $size = filesize($path); $checksum = hash_file('sha256', $path);
        $key = storePersistentFileFromPath($conn, $path, $message['filename'], 'application/zip', $size, $checksum);
        $conn->begin_transaction();
        try {
            $current = reimbursementSubmissionContext($conn, $id, $userId, true);
            if (!hash_equals(reimbursementReviewFingerprint($current), $fingerprint)) throw new InvalidArgumentException('The request changed while preparing the package. Review it again. Nothing was submitted.');
            submitReimbursementRequest($conn, $id, $userId, false);
            $conn->execute_query('UPDATE reimbursement_requests SET owner_name_snapshot=? WHERE id=?',[$context['owner']['display_name'],$id]);
            $deliveries = [];
            foreach ($recipients as $type => $addresses) foreach ($addresses as $address) {
                $approved = [...$message, 'recipient' => $address, 'reply_to' => normalizeAccountEmail($context['owner']['email']),
                    'visible_recipients' => ['to' => $recipients['to'], 'cc' => $recipients['cc']]];
                $payload = \Dnr\Security\ApplicationKey::seal(json_encode($approved, JSON_THROW_ON_ERROR));
                $conn->execute_query('INSERT INTO reimbursement_email_deliveries (request_id, request_hash, recipient_hash, recipient_type, attachment_key, payload_ciphertext) VALUES (?, ?, ?, ?, ?, ?)',
                    [$id, $context['request']['request_hash'], hash('sha256', $address, true), $type, $key, $payload]);
                $deliveries[(string)$conn->insert_id] = $approved;
            }
            $snapshot = ['template_version'=>4, 'context'=>$context, 'recipients'=>$recipients, 'deliveries'=>$deliveries];
            $conn->execute_query('INSERT INTO reimbursement_submissions (id,attachment_key,snapshot_ciphertext) VALUES (?,?,?)',
                [$id,$key,\Dnr\Security\ApplicationKey::seal(json_encode($snapshot,JSON_THROW_ON_ERROR))]);
            reimbursementEvent($conn,'request',$id,'submitted','Approved package SHA-256: '.$checksum,$userId);
            $conn->commit();
        } catch (Throwable $e) { $conn->rollback(); throw $e; }
    } finally { if ($path !== null) @unlink($path); }
}
