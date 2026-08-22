<?php

declare(strict_types=1);

namespace Dnr\Security;

final class PasswordPolicy
{
    public const MINIMUM_CHARACTERS = 12;
    public const BCRYPT_MAXIMUM_BYTES = 72;

    public static function validationError(mixed $password, string $label = 'Password'): ?string
    {
        if (!is_string($password)) {
            return $label . ' is invalid.';
        }
        if (!mb_check_encoding($password, 'UTF-8')) {
            return $label . ' must use valid UTF-8 characters.';
        }
        if (mb_strlen($password, 'UTF-8') < self::MINIMUM_CHARACTERS) {
            return $label . ' must contain at least ' . self::MINIMUM_CHARACTERS . ' characters.';
        }
        if (strlen($password) > self::BCRYPT_MAXIMUM_BYTES) {
            return $label . ' must use ' . self::BCRYPT_MAXIMUM_BYTES
                . ' UTF-8 bytes or fewer so every character is checked during sign-in.';
        }
        return null;
    }

    public static function hash(string $password): string
    {
        $error = self::validationError($password);
        if ($error !== null) {
            throw new \InvalidArgumentException($error);
        }

        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verify(mixed $password, mixed $hash): bool
    {
        return is_string($password)
            && strlen($password) <= self::BCRYPT_MAXIMUM_BYTES
            && is_string($hash)
            && $hash !== ''
            && password_verify($password, $hash);
    }
}
