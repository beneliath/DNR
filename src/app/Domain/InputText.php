<?php

declare(strict_types=1);

namespace Dnr\Domain;

final class InputText
{
    /** @param array<string, mixed> $input */
    public static function value(array $input, string $key): string
    {
        return is_scalar($input[$key] ?? null) ? trim((string) $input[$key]) : '';
    }

    public static function lengthError(string $value, int $maximum, string $label): ?string
    {
        return mb_strlen($value, 'UTF-8') > $maximum
            ? sprintf('%s must be %d characters or fewer.', $label, $maximum)
            : null;
    }

    public static function textStorageError(string $value, string $label): ?string
    {
        return strlen($value) > 65535
            ? sprintf('%s is too long; shorten it and try again.', $label)
            : null;
    }
}
