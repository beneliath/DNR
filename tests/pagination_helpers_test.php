<?php

require_once __DIR__ . '/../src/pagination_helpers.php';

function expectPaginationHelper($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Pagination helper test failed: {$message}\n");
        exit(1);
    }
}

$values = [
    'sort_value' => 'مؤتمر ירושלים',
    'id' => 184467,
];
$cursor = encodePaginationCursor($values);

expectPaginationHelper(
    preg_match('/^[A-Za-z0-9_-]+$/', $cursor) === 1,
    'cursors should be URL-safe without padding.'
);
expectPaginationHelper(
    decodePaginationCursor($cursor, ['sort_value', 'id']) === $values,
    'valid cursor values should round-trip without losing Unicode or numeric values.'
);
expectPaginationHelper(
    decodePaginationCursor($cursor, ['id', 'sort_value']) === null,
    'cursor field order should be bound to the requested list and sort.'
);
expectPaginationHelper(
    decodePaginationCursor(encodePaginationCursor(['sort_value' => 'x']), ['sort_value', 'id']) === null,
    'cursors missing a required key should be rejected.'
);
expectPaginationHelper(
    decodePaginationCursor('***not-base64***', ['sort_value', 'id']) === null
        && decodePaginationCursor(str_repeat('a', 2049), ['sort_value', 'id']) === null,
    'malformed and oversized cursors should be rejected safely.'
);

foreach ([
    [1, 0, [1]],
    [1, 1, [1]],
    [2, 4, [1, 2, 3, 4]],
    [1, 39, [1, 2, 3, 4, null, 39]],
    [6, 39, [1, null, 5, 6, 7, null, 39]],
    [4, 39, [1, 2, 3, 4, 5, null, 39]],
    [38, 39, [1, null, 36, 37, 38, 39]],
    [99, 8, [1, null, 5, 6, 7, 8]],
] as [$current, $total, $expected]) {
    expectPaginationHelper(paginationPageNumbers($current, $total) === $expected,
        "numbered pages should retain boundaries and avoid ellipses for a single skipped page ($current/$total).");
}
for ($total = 1; $total <= 100; $total++) {
    for ($current = 1; $current <= $total; $current++) {
        $pages = paginationPageNumbers($current, $total);
        $numbers = array_values(array_filter($pages, 'is_int'));
        expectPaginationHelper(count($pages) <= 7 && $numbers[0] === 1 && end($numbers) === $total
            && in_array($current, $numbers, true) && count($numbers) === count(array_unique($numbers)),
            'the page window must stay compact and include the current, first, and last pages without duplicates.');
    }
}

foreach ([null, [], 'invalid', -1, 0] as $invalid) {
    expectPaginationHelper(paginationPageSize($invalid) === 20, 'Invalid sizes use the default.');
    expectPaginationHelper(paginationState(81, 20, $invalid)['page'] === 1, 'Invalid pages open the first page.');
}
expectPaginationHelper(paginationPageSize('50') === 50 && paginationPageSize(25) === 20,
    'Only supported page sizes are accepted.');
expectPaginationHelper(paginationPageSize(100, 50, [20, 50]) === 50,
    'A route-specific page-size cap cannot be exceeded.');
expectPaginationHelper(paginationState(81, 20, 999) === ['total' => 81, 'page' => 5, 'pages' => 5, 'offset' => 80],
    'Out-of-range pages clamp to the final record.');
expectPaginationHelper(paginationState(0, 20, 5) === ['total' => 0, 'page' => 1, 'pages' => 1, 'offset' => 0],
    'Empty results retain a valid first page without an offset.');

$base = 'view_organization.php?id=7&q=A%26B&events_page=3&events_per_page=50&cursor=old&history=old#chron-log';
$url = paginationUrl($base, 2, 20, 'chron_page', 'chron_per_page');
parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
expectPaginationHelper($query === ['id' => '7', 'q' => 'A&B', 'events_page' => '3', 'events_per_page' => '50', 'chron_page' => '2', 'chron_per_page' => '20']
    && parse_url($url, PHP_URL_FRAGMENT) === 'chron-log',
    'Navigation retains filters, record identity, other list positions, and anchors while removing legacy cursors.');

foreach ([[81, 1, 20, 0, 1, 'Showing 1–20 of 81 organizations'],
    [81, 3, 20, 1, 1, 'Showing 41–60 of 81 organizations'],
    [81, 5, 20, 1, 0, 'Showing 81–81 of 81 organizations'],
    [81, 1, 100, 0, 0, 'Showing 1–81 of 81 organizations'],
    [20, 1, 20, 0, 0, 'Showing 1–20 of 20 organizations'],
    [0, 1, 20, 0, 0, 'Showing 0 of 0 organizations']] as [$total, $page, $size, $previous, $next, $status]) {
    ob_start();
    renderPagination($total, $page, $size, 'organizations.php?q=A%26B', 'organizations', 'Organization pages');
    $html = ob_get_clean();
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    $xpath = new DOMXPath($dom);
    expectPaginationHelper($xpath->query('//a[@rel="prev"]')->length === $previous
        && $xpath->query('//a[@rel="next"]')->length === $next,
        'Only reachable previous and next pages are links.');
    if ($total > $size) {
        expectPaginationHelper($xpath->query('//*[@aria-current="page"]')->item(0)->textContent === (string) $page,
            'The current page is identified when navigation is available.');
    } else {
        expectPaginationHelper($xpath->query('//*[@class="pagination-bar"]')->length === 0
            && $xpath->query('//a[contains(@class,"page-size-button")]')->length === 3,
            'Single-page and empty lists retain the size selector without a page-navigation bar.');
    }
    expectPaginationHelper(str_contains($html, $status), 'The visible record range agrees with the results.');
    foreach ($xpath->query('//a') as $link) {
        parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $parameters);
        expectPaginationHelper(($parameters['q'] ?? '') === 'A&B', 'Every pagination link preserves the search.');
        if (str_contains($link->getAttribute('class'), 'page-size-button')) {
            expectPaginationHelper($parameters['page'] === '1', 'Changing the page size starts at page one.');
        }
    }
    expectPaginationHelper($xpath->query('//*[@id]')->length === 0, 'Repeated pagination controls do not duplicate IDs.');
}

echo "Pagination helper tests passed.\n";
