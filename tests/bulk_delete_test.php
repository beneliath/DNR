<?php
require_once __DIR__ . '/../src/bulk_delete_helpers.php';
function expectBulkIds(bool $ok): void { if (!$ok) throw new RuntimeException('Bulk selection validation failed.'); }
expectBulkIds(bulkDeleteIds(['1', '2', '1']) === [1, 2]);
expectBulkIds(count(bulkDeleteIds(range(1, 100))) === 100);
foreach ([null, '', [], '1', ['a' => 1], [0], [-1], ['1x'], ['1.5'], [true], [[1]], [2147483648], range(1, 101)] as $input) {
    try { bulkDeleteIds($input); throw new RuntimeException('Invalid selection accepted.'); }
    catch (InvalidArgumentException $expected) {}
}
foreach (['organization', 'contact', 'speaker', 'task', 'engagement', 'user'] as $entity) {
    expectBulkIds(str_ends_with(bulkDeleteType($entity)['page'], '.php'));
}
try { bulkDeleteType('users; DELETE FROM users'); throw new RuntimeException('Invalid type accepted.'); }
catch (InvalidArgumentException $expected) {}
echo "Bulk deletion input tests passed.\n";
