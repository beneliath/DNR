<?php

declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/reimbursement_submission_helpers.php';

function expectReimbursementCsv(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$items = [
    ['expense_id' => 11, 'expense_date' => '2026-09-29', 'merchant' => '=SUM(1,1)',
        'coa_number' => '5291', 'description' => "Travel, \"sample\"\nSecond line", 'amount_cents' => 248857],
    ['expense_id' => 12, 'expense_date' => '2026-09-28', 'merchant' => 'Book shop',
        'coa_number' => '5451', 'description' => 'Reference book', 'amount_cents' => 450],
];
$receipts = [
    ['expense_id' => 11, 'id' => 2, 'filename' => 'first.png', 'content_type' => 'image/png'],
    ['expense_id' => 11, 'id' => 3, 'filename' => 'second.jpg', 'content_type' => 'image/jpeg'],
];
$csv = reimbursementExpenseCsv($items, $receipts);
expectReimbursementCsv(str_contains($csv, "\r\n"), 'CSV rows use standard line endings.');
$stream = fopen('php://temp', 'w+');
fwrite($stream, $csv);
rewind($stream);
$header = fgetcsv($stream, 0, ',', '"', '');
$first = fgetcsv($stream, 0, ',', '"', '');
$second = fgetcsv($stream, 0, ',', '"', '');
$end = fgetcsv($stream, 0, ',', '"', '');
fclose($stream);
expectReimbursementCsv($header === ['Date of Expense', 'Merchant/Payee', 'COA Number', 'Description', 'Amount (USD)', 'Receipt Filename(s)'],
    'CSV includes the requested import columns.');
expectReimbursementCsv($first === ['9/29/2026', "'=SUM(1,1)", '5291', "Travel, \"sample\"\nSecond line", '$2488.57',
    'expense-11-receipt-2-first.png; expense-11-receipt-3-second.jpg'],
    'Multiple receipts stay in one expense row, with spreadsheet formula text escaped.');
expectReimbursementCsv($second === ['9/28/2026', 'Book shop', '5451', 'Reference book', '$4.50', ''] && $end === false,
    'An expense without receipts has one row and a blank filename cell.');

echo "Reimbursement CSV tests passed.\n";
