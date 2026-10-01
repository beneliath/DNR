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
        $giving = self::amount($input['giving_income_received'] ?? null, 'Giving / Income Received');
        $lodging = self::amount($input['lodging_received'] ?? null, 'Lodging Received');
        $travel = self::amount($input['travel_received'] ?? null, 'Travel Received');
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
        return Money::total($amounts);
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
        return Money::amount($amount, $label, self::MAXIMUM_AMOUNT);
    }
}
