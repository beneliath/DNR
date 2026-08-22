<?php

/**
 * @param array<string, int|float|string> $values
 */
function encodePaginationCursor(array $values): string {
    $json = json_encode($values, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode the pagination cursor.');
    }
    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

/**
 * @param list<string> $required_keys
 * @return array<string, int|float|string>|null
 */
function decodePaginationCursor(mixed $cursor, array $required_keys): ?array {
    if (!is_string($cursor) || $cursor === '' || strlen($cursor) > 2048) {
        return null;
    }
    $padding = (4 - strlen($cursor) % 4) % 4;
    $decoded = base64_decode(strtr($cursor, '-_', '+/') . str_repeat('=', $padding), true);
    if (!is_string($decoded)) {
        return null;
    }
    $values = json_decode($decoded, true);
    if (!is_array($values) || array_keys($values) !== $required_keys) {
        return null;
    }
    foreach ($values as $value) {
        if (!is_scalar($value) || is_bool($value)) {
            return null;
        }
    }
    return $values;
}
