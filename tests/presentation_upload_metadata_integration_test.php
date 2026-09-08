<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Presentation upload metadata integration skipped (disposable database required).\n";
    exit;
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/presentation_helpers.php';

function expectStoredUpload(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$conn->begin_transaction();
try {
    $suffix = bin2hex(random_bytes(5));
    $users = [];
    foreach (['first', 'second'] as $label) {
        $username = 'upload-' . $label . '-' . $suffix;
        $hash = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
        $stmt = $conn->prepare("INSERT INTO users (username, password, role) VALUES (?, ?, 'editor')");
        $stmt->bind_param('ss', $username, $hash);
        $stmt->execute();
        $users[] = ['id' => (int) $conn->insert_id, 'username' => $username];
    }
    $speaker = saveSpeaker($conn, ['name' => 'Upload metadata fixture', 'email' => 'upload-' . $suffix . '@example.test', 'phone' => '+19494002892']);
    $conn->query("INSERT INTO organizations (organization_name) VALUES ('Upload metadata $suffix')");
    $org = (int) $conn->insert_id;
    $conn->query("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type, confirmation_status)
        VALUES ($org, 'Upload metadata', '2026-10-01', '2026-10-01', 'conference', 'under_review')");
    $event = (int) $conn->insert_id;
    $pdf = "%PDF-1.4\nUpload metadata fixture\nstartxref\n0\n%%EOF\n";
    $asset = ['data' => $pdf, 'filename' => 'first.pdf', 'size' => strlen($pdf), 'sha256' => hash('sha256', $pdf, true)];
    $rows = normalizeEngagementPresentations([['topic_title' => 'Upload fixture', 'speaker_id' => $speaker]], '2026-10-01', '2026-10-01', $speaker);
    $rows[0]['asset_changes'] = ['speaker_notes' => ['action' => 'replace', 'asset' => $asset]];
    syncEngagementPresentations($conn, $event, $rows, $users[0]['id']);
    $pid = (int) $conn->query("SELECT id FROM presentations WHERE engagement_id = $event")->fetch_assoc()['id'];
    $read = static fn(): array => $conn->query("SELECT * FROM presentation_notes WHERE presentation_id = $pid AND speaker_id = $speaker")->fetch_assoc();
    $initial = $read();
    expectStoredUpload(
        (int) $initial['uploaded_by'] === $users[0]['id']
            && $initial['uploaded_by_username_snapshot'] === $users[0]['username']
            && !empty($initial['updated_at']) && $initial['pdf'] === $pdf,
        'Creating a presentation with a PDF persists its uploader and upload time with the file.'
    );
    $rows[0]['id'] = $pid;
    unset($rows[0]['asset_changes']);
    $rows[0]['topic_title'] = 'Edited presentation title';
    syncEngagementPresentations($conn, $event, $rows, $users[1]['id']);
    expectStoredUpload($read() === $initial, 'An ordinary edit by a different user preserves the original upload metadata.');

    $conn->query("UPDATE presentation_notes SET updated_at = '2026-01-01 00:00:00' WHERE presentation_id = $pid");
    $asset['filename'] = 'replacement.pdf';
    $rows[0]['asset_changes'] = ['speaker_notes' => ['action' => 'replace', 'asset' => $asset]];
    syncEngagementPresentations($conn, $event, $rows, $users[1]['id']);
    $replacement = $read();
    expectStoredUpload(
        (int) $replacement['uploaded_by'] === $users[1]['id']
            && $replacement['uploaded_by_username_snapshot'] === $users[1]['username']
            && $replacement['updated_at'] > '2026-01-01 00:00:00.000000'
            && $replacement['filename'] === 'replacement.pdf',
        'Replacing a PDF records the new uploader and the replacement upload time.'
    );
    $conn->query('SAVEPOINT failed_upload');
    syncEngagementPresentations($conn, $event, $rows, $users[0]['id']);
    $conn->query('ROLLBACK TO SAVEPOINT failed_upload');
    expectStoredUpload($read() === $replacement, 'Rolling back a failed save rolls back the PDF attribution too.');

    $conn->query('DELETE FROM users WHERE id = ' . $users[1]['id']);
    expectStoredUpload(
        $read()['uploaded_by'] === null
            && $read()['uploaded_by_username_snapshot'] === $users[1]['username']
            && $read()['pdf'] === $pdf,
        'Deleting an account retains the PDF and the uploader username snapshot.'
    );
    applyPresentationAssetChanges($conn, $event, $pid, ['speaker_notes' => ['action' => 'remove']], $users[0]['id']);
    expectStoredUpload(
        $read()['pdf'] === null && $read()['uploaded_by'] === null && $read()['uploaded_by_username_snapshot'] === null,
        'Removing the PDF clears the uploader metadata for that file.'
    );
    echo "Presentation upload metadata integration tests passed.\n";
} finally {
    $conn->rollback();
}
