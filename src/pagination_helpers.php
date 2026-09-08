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

/** @return list<int|null> Page numbers with null for a skipped range. */
function paginationPageNumbers(int $current_page, int $total_pages): array {
    $total_pages = max(1, $total_pages);
    $current_page = max(1, min($current_page, $total_pages));
    if ($total_pages <= 7) {
        return range(1, $total_pages);
    }

    $start = $current_page <= 3 ? 1 : $current_page - 1;
    $end = $current_page >= $total_pages - 2 ? $total_pages : $current_page + 1;
    if ($current_page <= 3) $end = 4;
    if ($current_page >= $total_pages - 2) $start = $total_pages - 3;
    $visible = array_values(array_unique(array_merge([1], range($start, $end), [$total_pages])));
    $pages = [];
    $previous = 0;
    foreach ($visible as $page) {
        if ($previous > 0 && $page - $previous === 2) {
            $pages[] = $previous + 1;
        } elseif ($previous > 0 && $page - $previous > 2) {
            $pages[] = null;
        }
        $pages[] = $page;
        $previous = $page;
    }
    return $pages;
}

/** @param list<int> $allowed */
function paginationPageSize(mixed $value, int $default = 20, array $allowed = [20, 50, 100]): int {
    $size = is_scalar($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
    return is_int($size) && in_array($size, $allowed, true) ? $size : $default;
}

/**
 * Remember each list's size across visits without holding an application session lock.
 * The key identifies a page and, where needed, a particular list on that page.
 * @param list<int> $allowed
 */
function paginationPageSizePreference(string $key, mixed $value, int $default = 20, array $allowed = [20, 50, 100]): int {
    $cookie_name = 'dnr_rows_per_page_' . $key;
    $remembered_size = paginationPageSize($_COOKIE[$cookie_name] ?? null, $default, $allowed);
    $requested_size = paginationPageSize($value, 0, $allowed);
    if ($requested_size === 0) {
        return $remembered_size;
    }

    if (($_COOKIE[$cookie_name] ?? null) !== (string) $requested_size && !headers_sent()) {
        setcookie($cookie_name, (string) $requested_size, [
            'expires' => time() + 365 * 86400,
            'path' => '/',
            'secure' => requestUsesHttps() || applicationRequiresHttps(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        $_COOKIE[$cookie_name] = (string) $requested_size;
    }
    return $requested_size;
}

/** @return array{total: int, page: int, pages: int, offset: int} */
function paginationState(int $total, int $size, mixed $requested_page): array {
    $total = max(0, $total);
    $size = max(1, $size);
    $pages = max(1, (int) ceil($total / $size));
    $requested = is_scalar($requested_page) ? filter_var($requested_page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
    $page = min($pages, is_int($requested) ? $requested : 1);
    return ['total' => $total, 'page' => $page, 'pages' => $pages, 'offset' => ($page - 1) * $size];
}

/**
 * Count the same filtered rows as the list and translate legacy cursor links.
 * SQL fragments come from the route; request values remain bound parameters.
 * @param list<mixed> $values
 * @param list<mixed> $legacy_values
 * @return array{total: int, page: int, pages: int, offset: int}
 */
function queryPagination(mysqli $conn, string $from, string $types, array $values, int $size, mixed $requested_page, string $legacy_filter = '', string $legacy_types = '', array $legacy_values = []): array {
    $count = static function (string $sql, string $bind_types, array $bind_values) use ($conn): int {
        $stmt = $conn->prepare('SELECT COUNT(*) ' . $sql);
        if ($bind_types !== '') $stmt->bind_param($bind_types, ...$bind_values);
        $stmt->execute();
        $total = (int) $stmt->get_result()->fetch_row()[0];
        $stmt->close();
        return $total;
    };
    $total = $count($from, $types, $values);
    if ($requested_page === null && $legacy_filter !== '') {
        $remaining = $count($from . $legacy_filter, $types . $legacy_types, array_merge($values, $legacy_values));
        $requested_page = intdiv(max(0, $total - $remaining), max(1, $size)) + 1;
    }
    return paginationState($total, $size, $requested_page);
}

function paginationUrl(string $base_url, int $page, int $size, string $page_key = 'page', string $size_key = 'per_page'): string {
    $parts = parse_url($base_url);
    $query = [];
    parse_str($parts['query'] ?? '', $query);
    unset($query['cursor'], $query['history']);
    $query[$page_key] = max(1, $page);
    $query[$size_key] = $size;
    return ($parts['path'] ?? '') . '?' . http_build_query($query)
        . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
}

/** @param list<int> $allowed_sizes */
function renderPagination(int $total, int $page, int $size, string $base_url, string $label, string $aria_label, string $page_key = 'page', string $size_key = 'per_page', array $allowed_sizes = [20, 50, 100]): void {
    if ($total <= 20) {
        return;
    }
    $allowed_sizes = array_values(array_filter($allowed_sizes, static fn(int $option): bool => $total >= match ($option) {
        50 => 21,
        100 => 51,
        default => $option + 1,
    }));
    $state = paginationState($total, $size, $page);
    $page_numbers = paginationPageNumbers($state['page'], $state['pages']);
    $url = static fn(int $target_page, int $target_size): string => htmlspecialchars(
        paginationUrl($base_url, $target_page, $target_size, $page_key, $size_key), ENT_QUOTES, 'UTF-8'
    );
    require __DIR__ . '/templates/pagination.php';
}
