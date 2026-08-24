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

echo "Pagination helper tests passed.\n";
