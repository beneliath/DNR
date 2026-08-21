<?php

if (getenv('DNR_INTEGRATION_TEST') !== '1'
    || getenv('DNR_INTEGRATION_TARGET') !== 'disposable'
) {
    echo "Contact photo integration tests skipped (requires an explicitly disposable database).\n";
    exit(0);
}

$source_directory = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source_directory . '/config.php';

function expectContactPhotoIntegration($condition, $message) {
    if (!$condition) {
        throw new RuntimeException("Contact photo integration test failed: {$message}");
    }
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$suffix = bin2hex(random_bytes(4));
$photo_data = base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
    true
);
$photo_mime = 'image/png';

$conn->begin_transaction();
try {
    $organization_name = 'Contact Photo Test ' . $suffix;
    $organization_stmt = $conn->prepare(
        'INSERT INTO organizations (organization_name, is_deleted) VALUES (?, 0)'
    );
    $organization_stmt->bind_param('s', $organization_name);
    $organization_stmt->execute();
    $organization_id = $conn->insert_id;
    $organization_stmt->close();

    $contact_stmt = $conn->prepare(
        "INSERT INTO contacts (
            organization_id, contact_first_name, contact_last_name, contact_role,
            contact_email, contact_photo, contact_photo_mime, contact_photo_updated_at
         ) VALUES (?, 'Photo', 'Test', 'admin', 'photo-test@example.com', ?, ?, UTC_TIMESTAMP())"
    );
    $contact_stmt->bind_param('iss', $organization_id, $photo_data, $photo_mime);
    $contact_stmt->execute();
    $contact_id = $conn->insert_id;
    $contact_stmt->close();

    $stored_stmt = $conn->prepare(
        'SELECT contact_photo, contact_photo_mime, contact_photo_updated_at
         FROM contacts WHERE id = ?'
    );
    $stored_stmt->bind_param('i', $contact_id);
    $stored_stmt->execute();
    $stored = $stored_stmt->get_result()->fetch_assoc();
    $stored_stmt->close();
    expectContactPhotoIntegration(
        is_string($stored['contact_photo'])
            && hash_equals(hash('sha256', $photo_data), hash('sha256', $stored['contact_photo']))
            && $stored['contact_photo_mime'] === 'image/png'
            && $stored['contact_photo_updated_at'] !== null,
        'MySQL should preserve the uploaded binary image, MIME type, and update time.'
    );

    $remove_stmt = $conn->prepare(
        'UPDATE contacts
         SET contact_photo = NULL, contact_photo_mime = NULL,
             contact_photo_updated_at = UTC_TIMESTAMP()
         WHERE id = ?'
    );
    $remove_stmt->bind_param('i', $contact_id);
    $remove_stmt->execute();
    $remove_stmt->close();

    $removed_stmt = $conn->prepare(
        'SELECT contact_photo, contact_photo_mime FROM contacts WHERE id = ?'
    );
    $removed_stmt->bind_param('i', $contact_id);
    $removed_stmt->execute();
    $removed = $removed_stmt->get_result()->fetch_assoc();
    $removed_stmt->close();
    expectContactPhotoIntegration(
        $removed['contact_photo'] === null && $removed['contact_photo_mime'] === null,
        'removing a contact photo should clear both stored values.'
    );

    $conn->rollback();
} catch (Throwable $exception) {
    $conn->rollback();
    throw $exception;
}

echo "Contact photo integration tests passed.\n";
