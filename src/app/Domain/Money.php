<?php

declare(strict_types=1);

namespace Dnr\Domain;

/** Non-negative fixed-precision money. Database values remain decimal strings. */
final class Money
{
    public static function amount(mixed $value, string $label, string $maximum = '9999999999.99'): string
    {
        if (!is_scalar($value) || preg_match('/\A[0-9]+(?:\.[0-9]{1,2})?\z/D', trim((string) $value)) !== 1) {
            throw new \InvalidArgumentException("{$label} must be a non-negative amount with no more than two decimal places.");
        }
        [$whole, $fraction] = array_pad(explode('.', trim((string) $value), 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        $canonical = $whole . '.' . str_pad($fraction, 2, '0');
        $maximumWhole = explode('.', $maximum)[0];
        if (strlen($whole) > strlen($maximumWhole)
            || (strlen($whole) === strlen($maximumWhole) && strcmp($canonical, $maximum) > 0)) {
            throw new \InvalidArgumentException("{$label} exceeds the maximum supported amount.");
        }
        return $canonical;
    }

    public static function cents(string $amount): int
    {
        [$whole, $fraction] = explode('.', self::amount($amount, 'Amount'));
        return (int) $whole * 100 + (int) $fraction;
    }

    public static function fromCents(int $cents): string
    {
        if ($cents < 0) throw new \InvalidArgumentException('Amount cannot be negative.');
        return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /** @param list<string> $amounts */
    public static function total(array $amounts): string
    {
        $cents = 0;
        foreach ($amounts as $amount) {
            $next = self::cents($amount);
            if ($cents > PHP_INT_MAX - $next) throw new \InvalidArgumentException('Total exceeds the supported range.');
            $cents += $next;
        }
        return self::fromCents($cents);
    }
}
