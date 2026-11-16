<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Speaker sorting tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}
$sourceDirectory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $sourceDirectory . '/bootstrap.php';
require_once $sourceDirectory . '/speaker_helpers.php';

function expectSpeakerSort(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Speaker sorting failed: ' . $message);
    }
}

$conn->begin_transaction();
try {
    // These names deliberately distinguish surname ordering from full-name or
    // suffix ordering. The compound surname is an explicit user preference.
    $fixtures = [
        ['name' => 'John Smith Jr.'],
        ['name' => 'Brian Adams III'],
        ['name' => 'Gabriel García Márquez', 'sort_name' => 'García Márquez, Gabriel'],
        ['name' => "Anne O'Neill"],
        ['name' => 'Walter Brown, Sr.'],
        ['name' => 'Luis Fernández Jr., Ph.D.'],
        ['name' => 'Jane Smith'],
        ['name' => 'Walter Brown, Sr.'],
        ['name' => 'Amy Smith', 'sort_name' => 'Smith, Amy'],
        ['name' => 'Zoe Smith'],
    ];
    $ids = [];
    foreach ($fixtures as $fixture) {
        $ids[] = saveSpeaker($conn, $fixture + ['email' => 'speaker@example.invalid', 'phone' => '+19494002892']);
    }
    $where = 'WHERE id IN (' . implode(',', $ids) . ')';
    $expected = [$ids[1], $ids[4], $ids[7], $ids[5], $ids[2], $ids[3], $ids[8], $ids[6], $ids[0], $ids[9]];
    foreach (['ASC', 'DESC'] as $direction) {
        $paged = [];
        for ($offset = 0; $offset < count($ids); $offset += 3) {
            $rows = $conn->query('SELECT id FROM speakers ' . $where . ' ORDER BY ' . speakerSortNameSql()
                . " $direction, name $direction, id $direction LIMIT 3 OFFSET $offset")->fetch_all(MYSQLI_ASSOC);
            $paged = array_merge($paged, array_map('intval', array_column($rows, 'id')));
        }
        expectSpeakerSort($paged === ($direction === 'ASC' ? $expected : array_reverse($expected)),
            'Suffixes, compound surnames, and equal names retain correct order across pages: ' . $direction);
    }

    $id = $ids[2];
    $speaker = fetchSpeaker($conn, $id);
    expectSpeakerSort($speaker['name'] === 'Gabriel García Márquez'
        && $speaker['sort_name'] === 'García Márquez, Gabriel', 'The override is saved without changing the display name.');
    $input = ['name' => $speaker['name'], 'email' => 'speaker@example.invalid', 'phone' => '+19494002892'];
    saveSpeaker($conn, $input, $id, (int) $speaker['version']);
    $speaker = fetchSpeaker($conn, $id);
    expectSpeakerSort($speaker['sort_name'] === 'García Márquez, Gabriel', 'Older callers preserve the override.');
    saveSpeaker($conn, $input + ['sort_name' => ''], $id, (int) $speaker['version']);
    expectSpeakerSort(fetchSpeaker($conn, $id)['sort_name'] === null, 'Clearing the field restores automatic sorting.');
    expectSpeakerSort(fetchSpeaker($conn, $ids[0])['sort_name'] === null, 'An omitted override remains optional.');
    echo "Speaker sorting integration tests passed.\n";
} finally {
    $conn->rollback();
}
