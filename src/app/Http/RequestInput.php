<?php

declare(strict_types=1);

namespace Dnr\Http;

/**
 * Scalar-only access to request arrays. PHP permits repeated query/form keys
 * to become arrays; routes should treat those as invalid input, not cast them.
 */
final class RequestInput
{
    /** @param array<string, mixed> $input */
    public static function string(
        array $input,
        string $key,
        string $default = '',
        int $maximumBytes = 0
    ): string {
        $value = $input[$key] ?? null;
        if (!is_scalar($value)) {
            return $default;
        }
        $value = trim((string) $value);
        return $maximumBytes > 0 ? substr($value, 0, $maximumBytes) : $value;
    }

    /** @param array<string, mixed> $input */
    public static function positiveInt(array $input, string $key): ?int
    {
        $value = $input[$key] ?? null;
        if (!is_int($value) && !is_string($value)) {
            return null;
        }
        $filtered = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        return is_int($filtered) ? $filtered : null;
    }

    /**
     * @param array<string, mixed> $input
     * @param list<string> $allowed
     */
    public static function enum(
        array $input,
        string $key,
        array $allowed,
        string $default = ''
    ): string {
        $value = self::string($input, $key, $default);
        return in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * @param array<string, mixed> $input
     * @return list<int>
     */
    public static function positiveIntList(array $input, string $key): array
    {
        $values = $input[$key] ?? [];
        if (!is_array($values)) {
            return [];
        }
        $ids = [];
        foreach ($values as $value) {
            if ((is_int($value) || is_string($value))
                && filter_var($value, FILTER_VALIDATE_INT, [
                    'options' => ['min_range' => 1],
                ]) !== false
            ) {
                $ids[] = (int) $value;
            }
        }
        return array_values(array_unique($ids));
    }
}
