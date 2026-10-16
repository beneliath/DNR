<?php

declare(strict_types=1);

/** Shared eligibility predicate for expense queries using the e alias. */
const REIMBURSEMENT_COMPLETE_EXPENSE_SQL = "(e.expense_type <> 'mileage' OR e.odometer_end IS NOT NULL)";

function reimbursementMileageIncomplete(array $expense): bool
{
    return ($expense['expense_type'] ?? 'expense') === 'mileage' && ($expense['odometer_end'] ?? null) === null;
}

function requireCompleteReimbursementExpense(array $expense): void
{
    if (reimbursementMileageIncomplete($expense)) {
        throw new InvalidArgumentException('Add an Ending Odometer Reading to the incomplete mileage entry before including it in a reimbursement request.');
    }
}

/** Parse decimal input as integer units so mileage amounts round once, to cents. */
function reimbursementMileageUnits(mixed $value, int $places, int $wholeDigits, string $label): int
{
    if (!is_string($value) || !preg_match('/\A[0-9]{1,' . $wholeDigits . '}(?:\.[0-9]{1,' . $places . '})?\z/D', trim($value))) {
        throw new InvalidArgumentException("Enter a valid {$label} with no more than {$places} decimal places.");
    }
    [$whole, $fraction] = array_pad(explode('.', trim($value), 2), 2, '');
    return (int) $whole * (10 ** $places) + (int) str_pad($fraction, $places, '0');
}

function reimbursementMileageRate(mixed $value): string
{
    $mills = reimbursementMileageUnits($value, 3, 2, 'reimbursement per mile');
    if ($mills < 1) throw new InvalidArgumentException('Reimbursement per mile must be greater than zero.');
    return sprintf('%d.%03d', intdiv($mills, 1000), $mills % 1000);
}

function reimbursementMileageDetails(array $input, string $rate): array
{
    $details = [];
    foreach (['origin_location' => 'origin location', 'destination_location' => 'destination location', 'description' => 'description or purpose'] as $field => $label) {
        $value = $input[$field] ?? null;
        $limit = $field === 'description' ? 500 : 160;
        if (!is_string($value) || trim($value) === '' || mb_strlen(trim($value)) > $limit
            || preg_match('/[\x00-\x1F\x7F]/', $field === 'description' ? str_replace(["\r", "\n"], '', $value) : $value)) {
            throw new InvalidArgumentException("Enter a {$label} of {$limit} characters or fewer.");
        }
        $details[$field] = trim($value);
    }
    $start = reimbursementMileageUnits($input['odometer_start'] ?? null, 2, 7, 'starting odometer reading');
    $endValue = $input['odometer_end'] ?? '';
    $end = is_string($endValue) && trim($endValue) === '' ? null : reimbursementMileageUnits($endValue, 2, 7, 'ending odometer reading');
    if ($end !== null && $end <= $start) throw new InvalidArgumentException('Ending odometer reading must be greater than the starting reading.');
    $rate = reimbursementMileageRate($rate);
    $mills = reimbursementMileageUnits($rate, 3, 2, 'reimbursement per mile');
    $amount = $end === null ? 0 : intdiv(($end - $start) * $mills + 500, 1000);
    if ($end !== null && ($amount < 1 || $amount > 2147483647)) throw new InvalidArgumentException('Calculated mileage reimbursement is outside the supported amount range.');
    return $details + [
        'odometer_start' => sprintf('%d.%02d', intdiv($start, 100), $start % 100),
        'odometer_end' => $end === null ? null : sprintf('%d.%02d', intdiv($end, 100), $end % 100),
        'mileage_rate' => $rate,
        'amount_cents' => $amount,
        'merchant' => mb_substr('Mileage: ' . $details['origin_location'] . ' to ' . $details['destination_location'], 0, 160),
    ];
}

/** Expense rows are immutable after submission; package snapshots also carry these fields. */
function reimbursementExpenseDescription(array $expense): string
{
    $description = (string) ($expense['description'] ?? '');
    if (($expense['expense_type'] ?? 'expense') !== 'mileage') return $description;
    if (reimbursementMileageIncomplete($expense)) return $description . "\nIncomplete mileage: Ending Odometer Reading required.";
    $miles = (float) $expense['odometer_end'] - (float) $expense['odometer_start'];
    return $description . "\nMileage: " . $expense['origin_location'] . ' to ' . $expense['destination_location']
        . '. Odometer: ' . $expense['odometer_start'] . ' to ' . $expense['odometer_end']
        . '. ' . number_format($miles, 2, '.', '') . ' miles at $' . $expense['mileage_rate'] . ' per mile.';
}

function reimbursementMileageReminder(): string
{
    return 'Mileage reimbursement includes fuel costs under IRS standard mileage rules. Do not also submit fuel for tax-free reimbursement for the same mileage. If fuel is reimbursed in addition, return the duplicate reimbursement; otherwise, it should be treated as taxable income. Contact your bookkeeper to arrange the correction or tax reporting.';
}
