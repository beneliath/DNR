<?php
// This deliberately restores an entire synthetic database. Never enable on real data.
if (getenv('DNR_DESTRUCTIVE_BACKUP_TEST') !== 'isolated-restore' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
    || getenv('DNR_LARGE_BACKUP_TEST') !== '1') {
    echo "Large backup capacity test skipped (requires isolated restore fixture).\n"; exit(0);
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/config.php';
require_once $source . '/functions.php';
require_once $source . '/database_backup_helpers.php';
ini_set('memory_limit', '512M');
$conn->query("INSERT INTO organizations (organization_name) VALUES ('Large backup synthetic organization')");
$organization = (int) $conn->insert_id;
$conn->query("INSERT INTO engagements (organization_id,event_title,event_start_date,event_end_date,event_type,confirmation_status)
    VALUES ($organization,'Large backup','2026-09-05','2026-09-05','conference','under_review')");
$engagement = (int) $conn->insert_id;
$backup = $encrypted = $decrypted = null;
try {
    for ($i = 0; $i < 2; $i++) {
        $conn->query("INSERT INTO presentations (engagement_id,topic_title,speaker_id,
            speaker_notes_qr_image,speaker_website_qr_image,speaker_donation_qr_image)
            VALUES ($engagement,'Capacity fixture',(SELECT MIN(id) FROM speakers),REPEAT('q',5242880),REPEAT('r',5242880),REPEAT('s',5242880))");
        $presentation = (int) $conn->insert_id;
        $conn->query("INSERT INTO presentation_notes (presentation_id,speaker_id,pdf,size,sha256)
            VALUES ($presentation,(SELECT MIN(id) FROM speakers),REPEAT(CHAR(65+$i),104857600),104857600,
                UNHEX(SHA2(REPEAT(CHAR(65+$i),104857600),256)))");
    }
    $expected = $conn->query("SELECT p.id, HEX(n.sha256) AS hash FROM presentations p JOIN presentation_notes n ON n.presentation_id=p.id AND n.speaker_id=p.speaker_id WHERE engagement_id=$engagement ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    $backup = createDatabaseBackup($conn, 'capacity-test');
    if ($backup['size'] <= 268435456 || $backup['size'] >= databaseBackupMaximumBytes()) throw new RuntimeException('Capacity fixture did not exercise the old limit');
    $encrypted = encryptDatabaseBackup($backup['path'], 'Large synthetic backup password');
    unlink($backup['path']); $backup = null;
    $decrypted = decryptDatabaseBackup($encrypted['path'], 'Large synthetic backup password');
    unlink($encrypted['path']); $encrypted = null;
    $schema = databaseBackupSchemaDescriptor($conn);
    $inspection = inspectDatabaseBackup($decrypted['path'], $schema);
    $conn->query("UPDATE presentation_notes n JOIN presentations p ON p.id=n.presentation_id SET n.pdf=NULL WHERE engagement_id=$engagement");
    restoreDatabaseBackup($conn, $decrypted['path'], $schema, ['id'=>0,'username'=>'capacity-test']);
    $restored = $conn->query("SELECT p.id, SHA2(n.pdf,256) AS hash, OCTET_LENGTH(n.pdf) AS size FROM presentations p JOIN presentation_notes n ON n.presentation_id=p.id AND n.speaker_id=p.speaker_id WHERE engagement_id=$engagement ORDER BY id")->fetch_all(MYSQLI_ASSOC);
    foreach ($restored as $i=>$row) {
        if (strtoupper($row['hash']) !== $expected[$i]['hash'] || (int)$row['size'] !== 104857600) throw new RuntimeException('Large BLOB restore mismatch');
    }
    echo json_encode(['backup_bytes'=>$inspection['size'],'php_peak_bytes'=>memory_get_peak_usage(true),'restored_assets'=>count($restored),'peak_rss_kib'=>getrusage()['ru_maxrss']]) . "\n";
} finally {
    foreach ([$backup,$encrypted,$decrypted] as $file) if (is_array($file) && is_file($file['path'])) unlink($file['path']);
    $conn->query("DELETE FROM engagements WHERE id=$engagement");
    $conn->query("DELETE FROM organizations WHERE id=$organization");
}
