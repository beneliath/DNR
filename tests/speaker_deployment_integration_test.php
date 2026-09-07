<?php
declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Speaker deployment integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/speaker_seed_helpers.php';
$conn = applicationDatabaseConnection();
function expectSpeakerSeed(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Speaker deployment test failed: ' . $message);
}
$conn->begin_transaction();
try {
    $seed = exportInitialSpeakerSeed($conn);
    $seed['speaker']['bio'] = "Imported biography\nSecond line";
    foreach (SPEAKER_URL_FIELDS as $field => $label) $seed['speaker'][$field] = 'https://example.com/' . $field;
    $image = imagecreatetruecolor(32, 32);
    imagefill($image, 0, 0, imagecolorallocate($image, 10, 80, 180));
    ob_start(); imagepng($image); $bytes = ob_get_clean();
    $seed['photo'] = ['data' => base64_encode($bytes), 'thumbnail_data' => base64_encode($bytes),
        'mime_type' => 'image/png', 'thumbnail_mime_type' => 'image/png', 'sha256' => hash('sha256', $bytes)];
    $id = (int) $seed['source_id'];
    $conn->query("INSERT INTO organizations (organization_name) VALUES ('Speaker seed test')");
    $org = (int) $conn->insert_id;
    $conn->query("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type, confirmation_status)
        VALUES ($org, 'Speaker seed test', '2026-10-01', '2026-10-02', 'conference', 'under_review')");
    $event = (int) $conn->insert_id;
    $conn->query("INSERT INTO presentations (engagement_id, speaker_id, topic_title, is_archived)
        VALUES ($event, $id, 'Active', 0), ($event, $id, 'Archived', 1)");
    $receipt = applyInitialSpeakerSeed($conn, $seed);
    expectSpeakerSeed($receipt['presentations'] >= 2 && $receipt['speaker_id'] === $id, 'All active and archived presentations retain their designated speaker.');
    $saved = exportInitialSpeakerSeed($conn);
    expectSpeakerSeed($saved['speaker'] === $seed['speaker'] && $saved['photo'] === $seed['photo'], 'Every field and both photo byte streams are copied exactly.');
    expectSpeakerSeed(applyInitialSpeakerSeed($conn, $seed) === $receipt, 'Repeating an import preserves data and associations.');
    $broken = $seed;
    $broken['photo']['sha256'] = str_repeat('0', 64);
    try {
        applyInitialSpeakerSeed($conn, $broken);
        throw new RuntimeException('Corrupt image accepted.');
    } catch (InvalidArgumentException $expected) {}
    $stillSaved = exportInitialSpeakerSeed($conn);
    expectSpeakerSeed($stillSaved['speaker'] === $seed['speaker'] && $stillSaved['photo'] === $seed['photo'], 'Invalid seed data never changes the stored profile.');
    $conn->query("INSERT INTO speakers (name, email, phone) VALUES ('Other speaker', 'other@example.com', '+19494002892')");
    try {
        applyInitialSpeakerSeed($conn, $seed);
        throw new RuntimeException('Multiple speakers were accepted by an initial import.');
    } catch (RuntimeException $expected) {
        expectSpeakerSeed(str_contains($expected->getMessage(), 'single migrated Olivier'), 'Subsequent directories cannot accidentally be reseeded.');
    }
    echo "Speaker deployment integration tests passed.\n";
} finally {
    $conn->rollback();
}
