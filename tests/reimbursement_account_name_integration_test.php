<?php
declare(strict_types=1);

// Every database change is rolled back, including in an explicitly selected
// local Account preview. No request, submission, delivery, or stored file is created.
$disposable = getenv('DNR_INTEGRATION_TEST') === '1' && getenv('DNR_INTEGRATION_TARGET') === 'disposable';
if (!$disposable && getenv('DNR_ACCOUNT_PREVIEW_FIXTURE') !== '1') {
    echo "Reimbursement Account name tests skipped (disposable or local Account preview required).\n";
    exit;
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/reimbursement_submission_helpers.php';

function expectReimbursementAccountName(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$conn->begin_transaction();
try {
    $legacyName = 'Unused Reimbursement Organization';
    $conn->execute_query('UPDATE reimbursement_setup SET organization_name=? WHERE id=1', [$legacyName]);
    $accountName = 'Account Name & Partners';
    if (accountsEnabled()) {
        $conn->execute_query('UPDATE account_profile SET name=? WHERE id=1', [$accountName]);
    } else {
        $accountName = applicationBrandName();
    }
    $setup = reimbursementSetup($conn);
    expectReimbursementAccountName($setup['account_name'] === $accountName && !isset($setup['organization_name']),
        'Reports must use the Account name and ignore the old reimbursement organization.');
    expectReimbursementAccountName(reimbursementSetup($conn, true) === $setup,
        'Submission validation must lock and read the same Account name as the preview.');
    $context = [
        'request' => ['request_hash' => 'ACCOUNT-NAME-TEST', 'start_date' => '2026-10-01',
            'end_date' => '2026-10-02', 'status' => 'draft', 'bookkeeper_note' => ''],
        'owner' => ['display_name' => 'Test Owner'],
        'setup' => array_replace($setup, ['bookkeeper_first_name' => 'Test', 'bookkeeper_last_name' => 'Bookkeeper',
            'bookkeeper_email' => 'bookkeeper@example.test', 'bookkeeper_phone' => '', 'cc_email' => '']),
        'items' => [['expense_id' => 1, 'expense_date' => '2026-10-01', 'merchant' => 'Test Merchant',
            'description' => 'Test Expense', 'amount_cents' => 1250, 'coa_number' => 'TEST', 'coa_description' => 'Test']],
        'receipts' => [],
    ];
    $message = reimbursementSubmissionMessage($context);
    expectReimbursementAccountName(str_contains($message['html_body'], htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8'))
        && !str_contains($message['html_body'], $legacyName), 'Email preview must display the escaped Account name.');
    $pdf = renderReimbursementPdf($context['request'], $context['owner'], $context['items'], [], $context['setup']);
    $process = proc_open(['pdftotext', '-', '-'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Unable to inspect the report.');
    fwrite($pipes[0], $pdf); fclose($pipes[0]);
    $reportText = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $diagnostics = stream_get_contents($pipes[2]); fclose($pipes[2]);
    expectReimbursementAccountName(proc_close($process) === 0, 'Unable to read report text: ' . $diagnostics);
    expectReimbursementAccountName(str_contains($reportText, $accountName) && !str_contains($reportText, $legacyName),
        'The PDF report must print the Account name.');
    $fingerprint = reimbursementReviewFingerprint(['setup' => $setup]);
    $conn->execute_query('UPDATE reimbursement_setup SET organization_name=? WHERE id=1', ['Ignored legacy edit']);
    expectReimbursementAccountName(reimbursementReviewFingerprint(['setup' => reimbursementSetup($conn)]) === $fingerprint,
        'The unused legacy name must not affect submission review.');
    if (accountsEnabled()) {
        $conn->execute_query('UPDATE account_profile SET name=? WHERE id=1', ['Renamed Account']);
        $updated = reimbursementSetup($conn, true);
        expectReimbursementAccountName($updated['account_name'] === 'Renamed Account'
            && reimbursementReviewFingerprint(['setup' => $updated]) !== $fingerprint,
            'An Account rename must invalidate the reviewed submission without waiting for the profile cache.');
        expectReimbursementAccountName($context['setup']['account_name'] === $accountName,
            'An existing submission snapshot must preserve the reviewed Account name.');
    }
    echo "Reimbursement Account name, PDF, email, and submission review checks passed.\n";
} finally {
    $conn->rollback();
}
