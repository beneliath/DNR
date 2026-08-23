<?php

declare(strict_types=1);

namespace Dnr\Domain;

final class FinancialReportInput
{
    public const MAXIMUM_AMOUNT = '9999999999.99';

    /**
     * @param array<string, mixed> $input
     * @return array{
     *     giving_income_received: string,
     *     lodging_received: string,
     *     travel_received: string,
     *     notes: string,
     *     total_received: string
     * }
     */
    public static function normalize(array $input): array
    {
        $giving = self::amount($input['giving_income_received'] ?? null, 'Giving / income received');
        $lodging = self::amount($input['lodging_received'] ?? null, 'Lodging received');
        $travel = self::amount($input['travel_received'] ?? null, 'Travel received');
        $notes = InputText::value($input, 'notes');
        $notes_error = InputText::textStorageError($notes, 'Financial report notes');
        if ($notes_error !== null) {
            throw new \InvalidArgumentException($notes_error);
        }

        return [
            'giving_income_received' => $giving,
            'lodging_received' => $lodging,
            'travel_received' => $travel,
            'notes' => $notes,
            'total_received' => self::total([$giving, $lodging, $travel]),
        ];
    }

    /** @param list<string> $amounts */
    public static function total(array $amounts): string
    {
        $total_cents = 0;
        foreach ($amounts as $amount) {
            $total_cents += self::canonicalAmountToCents($amount);
        }

        return intdiv($total_cents, 100) . '.' . str_pad(
            (string) ($total_cents % 100),
            2,
            '0',
            STR_PAD_LEFT
        );
    }

    private static function amount(mixed $value, string $label): string
    {
        if (!is_scalar($value)) {
            throw new \InvalidArgumentException("{$label} is required.");
        }
        $amount = trim((string) $value);
        if ($amount === '') {
            throw new \InvalidArgumentException("{$label} is required; enter 0 if none was received.");
        }
        if (preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/', $amount) !== 1) {
            throw new \InvalidArgumentException(
                "{$label} must be a non-negative amount with no more than two decimal places."
            );
        }

        [$whole, $fraction] = array_pad(explode('.', $amount, 2), 2, '');
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = str_pad($fraction, 2, '0');
        $canonical = $whole . '.' . $fraction;
        if (strlen($whole) > 10
            || (strlen($whole) === 10 && strcmp($canonical, self::MAXIMUM_AMOUNT) > 0)
        ) {
            throw new \InvalidArgumentException("{$label} exceeds the maximum supported amount.");
        }

        return $canonical;
    }

    private static function canonicalAmountToCents(string $amount): int
    {
        [$whole, $fraction] = explode('.', $amount, 2);
        return ((int) $whole * 100) + (int) $fraction;
    }
}
