<?php
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../src/reimbursement_submission_helpers.php';

function expectMileage(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}
function rejectMileage(callable $action): void
{
    try { $action(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Invalid mileage input was accepted.');
}
$trip = ['origin_location' => 'Rockwall, TX', 'destination_location' => 'Sugar Land, TX',
    'odometer_start' => '12000.10', 'odometer_end' => '12250.60', 'description' => 'Business meeting'];
$details = reimbursementMileageDetails($trip, '0.76');
expectMileage($details['amount_cents'] === 19038 && $details['mileage_rate'] === '0.760', '250.50 miles at $0.76 must equal $190.38.');
$incomplete = reimbursementMileageDetails(array_replace($trip, ['odometer_end' => '']), '0.76') + ['expense_type' => 'mileage'];
expectMileage($incomplete['odometer_end'] === null && $incomplete['amount_cents'] === 0, 'Blank ending readings save without a calculated reimbursement.');
expectMileage(reimbursementMileageIncomplete($incomplete), 'Missing ending reading makes mileage incomplete.');
rejectMileage(fn() => requireCompleteReimbursementExpense($incomplete));
requireCompleteReimbursementExpense($details + ['expense_type' => 'mileage']);
requireCompleteReimbursementExpense(['expense_type' => 'expense']);
expectMileage(str_contains(reimbursementExpenseDescription($incomplete), 'Incomplete mileage'), 'Incomplete mileage has no misleading calculated distance.');
expectMileage(reimbursementMileageDetails(array_replace($trip, ['odometer_start' => '0', 'odometer_end' => '1']), '0.725')['amount_cents'] === 73, 'Half cents round up once at the final total.');
expectMileage(reimbursementMileageDetails(array_replace($trip, ['odometer_start' => '9999999.98', 'odometer_end' => '9999999.99']), '0.760')['amount_cents'] === 1, 'High odometers preserve hundredths without floating point errors.');
foreach (['', '0', '-0.76', '1e2', '0.1234', '100', [], null] as $invalid) rejectMileage(fn() => reimbursementMileageRate($invalid));
foreach (['odometer_start' => ['-1', '1e3', '1.234', [], '10000000'], 'odometer_end' => ['12000.10', '0', 'NaN', [], '1e5'],
    'origin_location' => ['', [], str_repeat('x', 161), "Place\nOther"], 'destination_location' => ['', null],
    'description' => ['', str_repeat('x', 501)]] as $field => $values) {
    foreach ($values as $value) rejectMileage(fn() => reimbursementMileageDetails(array_replace($trip, [$field => $value]), '0.760'));
}
rejectMileage(fn() => reimbursementMileageDetails(array_replace($trip, ['odometer_start' => '0', 'odometer_end' => '9999999.99']), '99.999'));
$item = $details + ['expense_id' => 1, 'expense_type' => 'mileage', 'expense_date' => '2026-10-15', 'coa_number' => '5442'];
$description = reimbursementExpenseDescription($item);
expectMileage(str_contains($description, '250.50 miles at $0.760 per mile') && str_contains($description, '12000.10 to 12250.60'), 'Report details include odometers and stored rate.');
$csv = reimbursementExpenseCsv([$item], []);
expectMileage(str_contains($csv, 'Sugar Land, TX') && str_contains($csv, '250.50 miles at $0.760 per mile') && str_contains($csv, '$190.38'), 'CSV preserves trip details and calculated amount.');
expectMileage(reimbursementExpenseDescription(['description' => 'Regular expense']) === 'Regular expense', 'Regular expenses retain their description.');
echo "Mileage calculation, validation, and CSV tests passed.\n";
