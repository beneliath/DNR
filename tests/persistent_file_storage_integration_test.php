<?php

declare(strict_types=1);

if (getenv('DNR_INTEGRATION_TARGET') !== 'disposable' || getenv('DNR_DESTRUCTIVE_BACKUP_TEST') !== 'isolated-restore') {
    echo "Persistent file integration skipped (isolated restore database required).\n";
    exit;
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/bootstrap.php';
require_once $source . '/persistent_file_migration_helpers.php';
require_once $source . '/database_backup_helpers.php';
require_once $source . '/short_link_helpers.php';
require_once $source . '/presentation_slidedeck_helpers.php';
require_once __DIR__ . '/presentation_slidedeck_fixture.php';

function expectPersistentStorage(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$suffix = bin2hex(random_bytes(5));
$image = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
$hash = hash('sha256', $image, true);
$conn->execute_query("INSERT INTO users (username, password, role, profile_picture, profile_picture_thumbnail,
    profile_picture_mime, profile_picture_thumbnail_mime, profile_picture_sha256)
    VALUES (?, ?, 'admin', ?, ?, 'image/png', 'image/png', ?)",
    ['storage-' . $suffix, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $image, $image, $hash]);
$user = (int) $conn->insert_id;
$conn->execute_query('INSERT INTO organizations (organization_name) VALUES (?)', ['Storage ' . $suffix]);
$organization = (int) $conn->insert_id;
$conn->execute_query("INSERT INTO contacts (organization_id, contact_first_name, contact_last_name, contact_role, contact_email,
    contact_photo, contact_photo_thumbnail, contact_photo_mime, contact_photo_thumbnail_mime, contact_photo_sha256)
    VALUES (?, 'File', 'Test', 'admin', 'file@example.test', ?, ?, 'image/png', 'image/png', ?)", [$organization, $image, $image, $hash]);
$contact = (int) $conn->insert_id;
$conn->execute_query("INSERT INTO speakers (name, email, phone, photo, photo_thumbnail, photo_mime, photo_thumbnail_mime, photo_sha256)
    VALUES (?, ?, '+19494002892', ?, ?, 'image/png', 'image/png', ?)", ['Storage ' . $suffix, $suffix . '@example.test', $image, $image, $hash]);
$speaker = (int) $conn->insert_id;
$conn->execute_query("INSERT INTO engagements (organization_id, event_title, event_start_date, event_end_date, event_type, confirmation_status)
    VALUES (?, 'Storage test', '2026-10-01', '2026-10-01', 'conference', 'under_review')", [$organization]);
$engagement = (int) $conn->insert_id;
$conn->execute_query("INSERT INTO presentations (engagement_id, speaker_id, topic_title) VALUES (?, ?, 'Storage notes')", [$engagement, $speaker]);
$presentation = (int) $conn->insert_id;
$pdf = "%PDF-1.4\n" . random_bytes(150000) . "\nstartxref\n0\n%%EOF\n";
$conn->execute_query('INSERT INTO presentation_notes (presentation_id, speaker_id, pdf, filename, size, sha256)
    VALUES (?, ?, ?, ?, ?, ?)', [$presentation, $speaker, $pdf, 'legacy.pdf', strlen($pdf), hash('sha256', $pdf, true)]);
expectPersistentStorage(migratePersistentFiles($conn) >= 7, 'Legacy portraits, thumbnails and PDFs must be converted.');
expectPersistentStorage(migratePersistentFiles($conn) === 0, 'Conversion must be resumable/idempotent.');
foreach (['users' => [$user, 'profile_picture'], 'contacts' => [$contact, 'contact_photo'], 'speakers' => [$speaker, 'photo']] as $table => [$id, $field]) {
    $row = $conn->execute_query("SELECT {$field}, {$field}_thumbnail, {$field}_key, {$field}_thumbnail_key FROM {$table} WHERE id = ?", [$id])->fetch_assoc();
    foreach ([$field, $field . '_thumbnail'] as $column) {
        expectPersistentStorage($row[$column] === null && file_get_contents(persistentFilePath($row[$column . '_key'])) === $image, 'Migration must clear BLOBs only after preserving exact bytes.');
    }
}
$conn->begin_transaction();
applyPresentationNotesChange($conn, $presentation, $engagement, ['action' => 'replace', 'asset' => [
    'data' => $pdf, 'filename' => 'replacement.pdf', 'size' => strlen($pdf), 'sha256' => hash('sha256', $pdf, true)]], $user);
$conn->commit();
$notes = $conn->execute_query('SELECT pdf, storage_key FROM presentation_notes WHERE presentation_id = ?', [$presentation])->fetch_assoc();
expectPersistentStorage($notes['pdf'] === null && file_get_contents(persistentFilePath($notes['storage_key'])) === $pdf, 'New PDF uploads must use persistent storage.');
$deckPath = tempnam(sys_get_temp_dir(), 'backup-deck-');
try {
    writeTestSlidedeck($deckPath, 'Backup and restore');
    $deckAsset = presentationSlidedeckFromPath($deckPath, 'backup-deck.pptx', false);
    $conn->begin_transaction();
    applyPresentationSlidedeckChange($conn, $presentation, $engagement, ['action' => 'replace', 'asset' => $deckAsset], $user);
    $conn->commit();
} finally { unlink($deckPath); }
$deckKey = $conn->execute_query('SELECT storage_key FROM presentation_slidedecks WHERE presentation_id = ?', [$presentation])->fetch_row()[0];
$conn->begin_transaction();
$rolledBackKey = storePersistentFile($conn, 'uncommitted', 'rollback.txt', 'text/plain');
$conn->rollback();
expectPersistentStorage($conn->execute_query('SELECT storage_key FROM stored_files WHERE storage_key = ?', [$rolledBackKey])->num_rows === 0, 'Failed transactions must not leave database references.');

$reader = databaseBackupConnection();
$backup = $encrypted = $decrypted = null;
$originalRoot = persistentFileRoot();
$newRoot = sys_get_temp_dir() . '/dnr-restored-files-' . $suffix;
mkdir($newRoot, 0700);
try {
    $metadata = $conn->query('SELECT storage_key, filename, content_type, size, checksum FROM stored_files ORDER BY storage_key')->fetch_all(MYSQLI_ASSOC);
    $schema = databaseBackupSchemaDescriptor($conn);
    $backup = createDatabaseBackup($reader, 'storage-test');
    expectPersistentStorage($backup['file_count'] === count($metadata), 'The archive must include every registered file.');
    $encrypted = encryptDatabaseBackup($backup['path'], 'persistent storage integration password');
    $decrypted = decryptDatabaseBackup($encrypted['path'], 'persistent storage integration password');
    // A fresh empty volume simulates loss of the original server's filesystem.
    putenv('DNR_FILE_STORAGE_PATH=' . $newRoot);
    $conn->execute_query("UPDATE organizations SET organization_name = ? WHERE id = ?", ['Changed after backup ' . $suffix, $organization]);
    $restored = restoreDatabaseBackup($conn, $decrypted['path'], $schema, ['id' => 0, 'username' => 'storage-test']);
    expectPersistentStorage($restored['file_count'] === count($metadata), 'Restore must include file counts.');
    foreach ($metadata as $file) {
        $handle = openPersistentFile($file, true);
        fclose($handle);
    }
    expectPersistentStorage($conn->execute_query('SELECT organization_name FROM organizations WHERE id = ?', [$organization])->fetch_row()[0] === 'Storage ' . $suffix, 'Database and files must restore together.');
    expectPersistentStorage($conn->execute_query('SELECT storage_key FROM presentation_slidedecks WHERE presentation_id = ?', [$presentation])->fetch_row()[0] === $deckKey
        && file_get_contents(persistentFilePath($deckKey)) === $deckAsset['data'], 'PowerPoint file and presentation association restore together onto an empty volume.');
    // A missing file must make export fail; never offer an incomplete archive.
    $filePath = persistentFilePath($notes['storage_key']);
    rename($filePath, $filePath . '.saved');
    try {
        try { $incomplete = createDatabaseBackup($reader, 'missing-file'); @unlink($incomplete['path']); $rejected = false; }
        catch (RuntimeException $exception) { $rejected = true; }
        expectPersistentStorage($rejected, 'Export must fail when a file is missing.');
    } finally { rename($filePath . '.saved', $filePath); }
    // Conflicting existing content must fail before any database replacement.
    file_put_contents($filePath, 'corrupt');
    $conn->execute_query("UPDATE organizations SET organization_name = ? WHERE id = ?", ['Preserve on failure ' . $suffix, $organization]);
    try { restoreDatabaseBackup($conn, $decrypted['path'], $schema, ['id' => 0]); $rejected = false; }
    catch (RuntimeException $exception) { $rejected = true; }
    expectPersistentStorage($rejected && $conn->execute_query('SELECT organization_name FROM organizations WHERE id = ?', [$organization])->fetch_row()[0] === 'Preserve on failure ' . $suffix, 'Storage failure must preserve the current database.');
    echo "Persistent storage migration and encrypted backup/restore integration passed.\n";
} finally {
    putenv('DNR_FILE_STORAGE_PATH=' . $originalRoot);
    foreach (glob($newRoot . '/*') as $path) unlink($path);
    rmdir($newRoot);
    foreach ([$backup, $encrypted, $decrypted] as $file) if ($file !== null) @unlink($file['path']);
    $reader->close();
}
