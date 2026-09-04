<?php

declare(strict_types=1);

require_once __DIR__ . '/application_runtime.php';

function chronLogDisplayTimezone() {
    return applicationTimezone();
}

function chronLogTimestampDetails($timestamp) {
    try {
        $utc_timestamp = new DateTimeImmutable((string) $timestamp, new DateTimeZone('UTC'));
        return [
            'display' => $utc_timestamp
                ->setTimezone(chronLogDisplayTimezone())
                ->format('F j, Y \a\t g:i A T'),
            'iso' => $utc_timestamp->format(DateTimeInterface::ATOM),
        ];
    } catch (Throwable $exception) {
        return [
            'display' => (string) $timestamp,
            'iso' => '',
        ];
    }
}

function sortChronLogEntriesReverseChronological(array $entries) {
    usort($entries, function ($left, $right) {
        $left_timestamp = strtotime((string) ($left['created_at'] ?? '')) ?: 0;
        $right_timestamp = strtotime((string) ($right['created_at'] ?? '')) ?: 0;
        return [$right_timestamp, (int) ($right['id'] ?? 0)]
            <=> [$left_timestamp, (int) ($left['id'] ?? 0)];
    });

    return $entries;
}

/**
 * Render Chron text for read-only UI surfaces, wrapping absolute HTTP(S) URLs
 * in safe external links without changing the stored entry text.
 */
function renderChronLogEntryHtml($entry_text) {
    $entry_text = (string) $entry_text;
    $url_matches = [];
    $match_count = preg_match_all(
        "~\\bhttps?://[^\\s<>\"']+~i",
        $entry_text,
        $url_matches,
        PREG_OFFSET_CAPTURE
    );
    if ($match_count === false || $match_count === 0) {
        return nl2br(htmlspecialchars(
            $entry_text,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        ));
    }

    $html = '';
    $text_offset = 0;
    $paired_closers = [')' => '(', ']' => '[', '}' => '{'];
    foreach ($url_matches[0] as $url_match) {
        $matched_url = (string) $url_match[0];
        $url_offset = (int) $url_match[1];
        $url = $matched_url;
        $trailing_text = '';

        while ($url !== '') {
            $last_character = substr($url, -1);
            $is_sentence_punctuation = str_contains('.,;:!?', $last_character);
            $is_unmatched_closer = isset($paired_closers[$last_character])
                && substr_count($url, $last_character)
                    > substr_count($url, $paired_closers[$last_character]);
            if (!$is_sentence_punctuation && !$is_unmatched_closer) {
                break;
            }
            $url = substr($url, 0, -1);
            $trailing_text = $last_character . $trailing_text;
        }

        $html .= htmlspecialchars(
            substr($entry_text, $text_offset, $url_offset - $text_offset),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $escaped_url = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $html .= '<a href="' . $escaped_url
            . '" target="_blank" rel="noopener noreferrer">'
            . $escaped_url
            . '</a>';
        $html .= htmlspecialchars(
            $trailing_text,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $text_offset = $url_offset + strlen($matched_url);
    }

    $html .= htmlspecialchars(
        substr($entry_text, $text_offset),
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );

    return nl2br($html);
}

/**
 * Chron logs use one table per parent record. Keeping the table and foreign-key
 * column behind this fixed allowlist prevents a contact's entries from ever
 * being queried through its organization (or vice versa).
 */
function chronLogEntityConfiguration($entity_type) {
    $configurations = [
        'engagement' => [
            'table' => 'engagement_chron_entries',
            'parent_column' => 'engagement_id',
        ],
        'contact' => [
            'table' => 'contact_chron_entries',
            'parent_column' => 'contact_id',
        ],
        'organization' => [
            'table' => 'organization_chron_entries',
            'parent_column' => 'organization_id',
        ],
        'inquiry' => [
            'table' => 'booking_inquiry_chron_entries',
            'parent_column' => 'booking_inquiry_id',
        ],
    ];

    $entity_type = (string) $entity_type;
    if (!isset($configurations[$entity_type])) {
        throw new InvalidArgumentException('Invalid Chron log owner.');
    }

    return $configurations[$entity_type];
}

/**
 * Resolve an offset against only the covering parent/date index. The expensive
 * Chron row query can then seek from this key without discarding large entry
 * text or joined user rows for every preceding page.
 *
 * @return array{created_at: string, id: int}|null
 */
function chronLogPageBoundary(
    mysqli $conn,
    string $table,
    string $parent_column,
    int $entity_id,
    ?int $archive_state,
    int $offset
): ?array {
    if ($offset < 1) {
        return null;
    }
    $query = "SELECT created_at, id FROM {$table} WHERE {$parent_column} = ?";
    if ($archive_state !== null) {
        $query .= $archive_state === 1 ? ' AND is_archived = 1' : ' AND is_archived = 0';
    }
    $query .= " ORDER BY created_at DESC, id DESC LIMIT 1 OFFSET {$offset}";
    $stmt = $conn->prepare($query);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Chron page boundary.');
    }
    $stmt->bind_param('i', $entity_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load the Chron page boundary.');
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return is_array($row)
        ? ['created_at' => (string) $row['created_at'], 'id' => (int) $row['id']]
        : null;
}

function fetchEntityChronLogEntriesForArchiveState(
    mysqli $conn,
    $entity_type,
    $entity_id,
    $archive_state = 0,
    $limit = null,
    $offset = 0
) {
    $configuration = chronLogEntityConfiguration($entity_type);
    $table = $configuration['table'];
    $parent_column = $configuration['parent_column'];
    $limit = $limit === null ? null : max(1, min(1000, (int) $limit));
    $offset = max(0, (int) $offset);
    $boundary = $limit === null ? null : chronLogPageBoundary(
        $conn,
        $table,
        $parent_column,
        (int) $entity_id,
        $archive_state === null ? null : ($archive_state === 1 ? 1 : 0),
        $offset
    );
    if ($limit !== null && $offset > 0 && $boundary === null) {
        return [];
    }

    $query = "SELECT ce.*,
                    COALESCE(creator.username, ce.created_by_username_snapshot)
                        AS created_by_username,
                    updater.username AS updated_by_username,
                    archiver.username AS archived_by_username
              FROM {$table} ce
              LEFT JOIN users creator ON creator.id = ce.created_by
              LEFT JOIN users updater ON updater.id = ce.updated_by
              LEFT JOIN users archiver ON archiver.id = ce.archived_by
              WHERE ce.{$parent_column} = ?";
    if ($archive_state !== null) {
        $query .= $archive_state === 1
            ? ' AND ce.is_archived = 1'
            : ' AND ce.is_archived = 0';
    }
    if ($boundary !== null) {
        $query .= ' AND (ce.created_at < ? OR (ce.created_at = ? AND ce.id <= ?))';
    }
    $query .= ' ORDER BY ce.created_at DESC, ce.id DESC';
    if ($limit !== null) {
        $query .= " LIMIT {$limit}";
    }

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        applicationLog('error', 'Unable to prepare the entity Chron log query', [
            'entity_type' => (string) $entity_type,
            'error' => $conn->error,
        ]);
        throw new RuntimeException('Unable to prepare the Chron log query.');
    }
    if ($boundary === null) {
        $stmt->bind_param('i', $entity_id);
    } else {
        $boundary_created_at = $boundary['created_at'];
        $boundary_id = $boundary['id'];
        $stmt->bind_param(
            'issi',
            $entity_id,
            $boundary_created_at,
            $boundary_created_at,
            $boundary_id
        );
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load the Chron log.');
    }
    $entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $entries;
}

function fetchEntityChronLogEntries(
    mysqli $conn,
    $entity_type,
    $entity_id,
    $include_archived = false,
    $limit = 50,
    $offset = 0
) {
    return fetchEntityChronLogEntriesForArchiveState(
        $conn,
        $entity_type,
        $entity_id,
        $include_archived ? null : 0,
        $limit,
        $offset
    );
}

function fetchArchivedEntityChronLogEntries(mysqli $conn, $entity_type, $entity_id) {
    return fetchEntityChronLogEntriesForArchiveState($conn, $entity_type, $entity_id, 1);
}

function countEntityChronLogEntries(mysqli $conn, $entity_type, $entity_id, $archive_state = 0) {
    $configuration = chronLogEntityConfiguration($entity_type);
    $table = $configuration['table'];
    $parent_column = $configuration['parent_column'];
    $archive_state = $archive_state === 1 ? 1 : 0;
    $stmt = $conn->prepare(
        "SELECT COUNT(*) AS entry_count
         FROM {$table}
         WHERE {$parent_column} = ? AND is_archived = {$archive_state}"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Chron count query.');
    }
    $stmt->bind_param('i', $entity_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to count Chron entries.');
    }
    $count = (int) ($stmt->get_result()->fetch_assoc()['entry_count'] ?? 0);
    $stmt->close();

    return $count;
}

function normalizeChronLogEntryText($entry_text) {
    if (!is_scalar($entry_text)) {
        throw new InvalidArgumentException('Enter a valid Chron entry.');
    }
    $entry_text = trim((string) $entry_text);
    if ($entry_text === '') {
        throw new InvalidArgumentException('Chron entries cannot be empty.');
    }
    $length = function_exists('mb_strlen') ? mb_strlen($entry_text, 'UTF-8') : strlen($entry_text);
    if ($length > 100000) {
        throw new InvalidArgumentException('Chron entries must be 100,000 characters or fewer.');
    }

    return $entry_text;
}

function normalizeSubmittedChronLogEntries($submitted_entries) {
    if ($submitted_entries === null) {
        return [];
    }
    if (!is_array($submitted_entries)) {
        throw new InvalidArgumentException('Invalid Chron entry submission.');
    }

    $entries = [];
    foreach ($submitted_entries as $submitted_entry_id => $submitted_entry_text) {
        if (!ctype_digit((string) $submitted_entry_id) || (int) $submitted_entry_id < 1) {
            throw new InvalidArgumentException('Invalid Chron entry submission.');
        }
        $entries[(int) $submitted_entry_id] = normalizeChronLogEntryText($submitted_entry_text);
    }

    return $entries;
}

function normalizeSubmittedChronLogVersions($submitted_versions) {
    if ($submitted_versions === null) {
        return [];
    }
    if (!is_array($submitted_versions) || count($submitted_versions) > 200) {
        throw new InvalidArgumentException('Invalid Chron entry version submission.');
    }

    $versions = [];
    foreach ($submitted_versions as $submitted_entry_id => $version) {
        if (!ctype_digit((string) $submitted_entry_id) || !is_scalar($version)) {
            throw new InvalidArgumentException('Invalid Chron entry version submission.');
        }
        $version = trim((string) $version);
        if ($version === '' || strlen($version) > 32) {
            throw new InvalidArgumentException('Invalid Chron entry version submission.');
        }
        $versions[(int) $submitted_entry_id] = $version;
    }

    return $versions;
}

function updateEntityChronLogEntries(
    mysqli $conn,
    $entity_type,
    $entity_id,
    array $submitted_entries,
    array $submitted_versions,
    $current_user_id
) {
    if (!$submitted_entries) {
        return;
    }

    $configuration = chronLogEntityConfiguration($entity_type);
    $table = $configuration['table'];
    $parent_column = $configuration['parent_column'];
    $current_stmt = $conn->prepare(
        "SELECT id, entry_text, updated_at
         FROM {$table}
         WHERE {$parent_column} = ? AND is_archived = 0
         FOR UPDATE"
    );
    if (!$current_stmt) {
        throw new RuntimeException('Unable to prepare the Chron entry update.');
    }
    $current_stmt->bind_param('i', $entity_id);
    if (!$current_stmt->execute()) {
        $current_stmt->close();
        throw new RuntimeException('Unable to load the Chron entries for updating.');
    }
    $current_entries = [];
    $current_result = $current_stmt->get_result();
    while ($current_entry = $current_result->fetch_assoc()) {
        $current_entries[(int) $current_entry['id']] = [
            'entry_text' => (string) $current_entry['entry_text'],
            'updated_at' => (string) $current_entry['updated_at'],
        ];
    }
    $current_stmt->close();

    $update_stmt = $conn->prepare(
        "UPDATE {$table}
         SET entry_text = ?, updated_by = ?, updated_at = UTC_TIMESTAMP(6)
         WHERE id = ? AND {$parent_column} = ? AND is_archived = 0 AND updated_at = ?"
    );
    if (!$update_stmt) {
        throw new RuntimeException('Unable to prepare the Chron entry update.');
    }
    $updated_text = '';
    $entry_id = 0;
    $expected_version = '';
    $update_stmt->bind_param(
        'siiis',
        $updated_text,
        $current_user_id,
        $entry_id,
        $entity_id,
        $expected_version
    );
    foreach ($submitted_entries as $submitted_entry_id => $submitted_entry_text) {
        if (!array_key_exists($submitted_entry_id, $current_entries)) {
            $update_stmt->close();
            throw new InvalidArgumentException('A submitted Chron entry is no longer available.');
        }
        if ($submitted_entry_text === $current_entries[$submitted_entry_id]['entry_text']) {
            continue;
        }
        $expected_version = (string) ($submitted_versions[$submitted_entry_id] ?? '');
        if ($expected_version === ''
            || !hash_equals($current_entries[$submitted_entry_id]['updated_at'], $expected_version)
        ) {
            $update_stmt->close();
            throw new InvalidArgumentException(
                'A Chron entry changed after you opened this page. Reload before saving so newer history is not overwritten.'
            );
        }
        $entry_id = $submitted_entry_id;
        $updated_text = $submitted_entry_text;
        if (!$update_stmt->execute() || $update_stmt->affected_rows !== 1) {
            $update_stmt->close();
            throw new RuntimeException('Unable to update the Chron entry.');
        }
    }
    $update_stmt->close();
}

function insertEntityChronLogEntry(
    mysqli $conn,
    $entity_type,
    $entity_id,
    $entry_text,
    $current_user_id,
    $current_username
) {
    $configuration = chronLogEntityConfiguration($entity_type);
    $table = $configuration['table'];
    $parent_column = $configuration['parent_column'];
    $entry_text = normalizeChronLogEntryText($entry_text);
    $current_username = trim((string) $current_username);
    $stmt = $conn->prepare(
        "INSERT INTO {$table}
            ({$parent_column}, entry_text, created_by, created_by_username_snapshot, updated_by)
         VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the new Chron entry.');
    }
    $stmt->bind_param(
        'isisi',
        $entity_id,
        $entry_text,
        $current_user_id,
        $current_username,
        $current_user_id
    );
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to save the new Chron entry.');
    }
    $stmt->close();
}

function archiveEntityChronLogEntry(
    mysqli $conn,
    $entity_type,
    $entity_id,
    $entry_id,
    $current_user_id
) {
    $configuration = chronLogEntityConfiguration($entity_type);
    $table = $configuration['table'];
    $parent_column = $configuration['parent_column'];
    $stmt = $conn->prepare(
        "UPDATE {$table}
         SET is_archived = 1, archived_by = ?, archived_at = UTC_TIMESTAMP(),
             updated_by = ?, updated_at = UTC_TIMESTAMP()
         WHERE id = ? AND {$parent_column} = ? AND is_archived = 0"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Chron archive.');
    }
    $stmt->bind_param('iiii', $current_user_id, $current_user_id, $entry_id, $entity_id);
    $succeeded = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    if (!$succeeded) {
        throw new RuntimeException('Unable to archive the Chron entry.');
    }
}

function deleteEntityChronLogEntry(mysqli $conn, $entity_type, $entity_id, $entry_id) {
    $configuration = chronLogEntityConfiguration($entity_type);
    $table = $configuration['table'];
    $parent_column = $configuration['parent_column'];
    $stmt = $conn->prepare(
        "DELETE FROM {$table} WHERE id = ? AND {$parent_column} = ?"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Chron deletion.');
    }
    $stmt->bind_param('ii', $entry_id, $entity_id);
    $succeeded = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();
    if (!$succeeded) {
        throw new RuntimeException('Unable to permanently delete the Chron entry.');
    }
}

function restoreEntityChronLogEntries(
    mysqli $conn,
    $entity_type,
    $entity_id,
    array $entry_ids,
    $current_user_id
) {
    $configuration = chronLogEntityConfiguration($entity_type);
    $table = $configuration['table'];
    $parent_column = $configuration['parent_column'];
    $entry_ids = array_values(array_unique(array_filter(
        array_map('intval', $entry_ids),
        fn($entry_id) => $entry_id > 0
    )));
    if (!$entry_ids) {
        throw new InvalidArgumentException('Select at least one archived Chron entry to restore.');
    }

    $placeholders = implode(', ', array_fill(0, count($entry_ids), '?'));
    $stmt = $conn->prepare(
        "UPDATE {$table}
         SET is_archived = 0,
             archived_by = NULL,
             archived_at = NULL,
             updated_by = ?,
             updated_at = UTC_TIMESTAMP()
         WHERE {$parent_column} = ?
           AND is_archived = 1
           AND id IN ({$placeholders})"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Chron restore.');
    }

    $bind_values = array_merge([(int) $current_user_id, (int) $entity_id], $entry_ids);
    $bind_types = str_repeat('i', count($bind_values));
    $bind_params = [$bind_types];
    foreach ($bind_values as &$bind_value) {
        $bind_params[] = &$bind_value;
    }
    unset($bind_value);
    $stmt->bind_param(...$bind_params);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to restore the selected Chron entries.');
    }
    $restored_count = $stmt->affected_rows;
    $stmt->close();
    if ($restored_count < 1) {
        throw new InvalidArgumentException('The selected entries are no longer archived.');
    }

    return $restored_count;
}

function fetchChronLogEntriesForArchiveState(
    mysqli $conn,
    $engagement_id,
    $archive_state = 0,
    $limit = null,
    $offset = 0
) {
    $limit = $limit === null ? null : max(1, min(1000, (int) $limit));
    $offset = max(0, (int) $offset);
    $boundary = $limit === null ? null : chronLogPageBoundary(
        $conn,
        'engagement_chron_entries',
        'engagement_id',
        (int) $engagement_id,
        $archive_state === null ? null : ($archive_state === 1 ? 1 : 0),
        $offset
    );
    if ($limit !== null && $offset > 0 && $boundary === null) {
        return [];
    }

    $query = 'SELECT ce.*,
                    COALESCE(creator.username, ce.created_by_username_snapshot)
                        AS created_by_username,
                    updater.username AS updated_by_username,
                    archiver.username AS archived_by_username
              FROM engagement_chron_entries ce
              LEFT JOIN users creator ON creator.id = ce.created_by
              LEFT JOIN users updater ON updater.id = ce.updated_by
              LEFT JOIN users archiver ON archiver.id = ce.archived_by
              WHERE ce.engagement_id = ?';
    if ($archive_state !== null) {
        $query .= $archive_state === 1
            ? ' AND ce.is_archived = 1'
            : ' AND ce.is_archived = 0';
    }
    if ($boundary !== null) {
        $query .= ' AND (ce.created_at < ? OR (ce.created_at = ? AND ce.id <= ?))';
    }
    $query .= ' ORDER BY ce.created_at DESC, ce.id DESC';
    if ($limit !== null) {
        $query .= " LIMIT {$limit}";
    }

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        applicationLog('error', 'Unable to prepare the Chron log query', ['error' => $conn->error]);
        throw new RuntimeException('Unable to prepare the Chron log query.');
    }
    if ($boundary === null) {
        $stmt->bind_param('i', $engagement_id);
    } else {
        $boundary_created_at = $boundary['created_at'];
        $boundary_id = $boundary['id'];
        $stmt->bind_param(
            'issi',
            $engagement_id,
            $boundary_created_at,
            $boundary_created_at,
            $boundary_id
        );
    }
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load the Chron log.');
    }
    $entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $entries;
}

function fetchChronLogEntries(
    mysqli $conn,
    $engagement_id,
    $include_archived = false,
    $limit = 50,
    $offset = 0
) {
    return fetchChronLogEntriesForArchiveState(
        $conn,
        $engagement_id,
        $include_archived ? null : 0,
        $limit,
        $offset
    );
}

function fetchArchivedChronLogEntries(mysqli $conn, $engagement_id) {
    return fetchChronLogEntriesForArchiveState($conn, $engagement_id, 1);
}

function countArchivedChronLogEntries(mysqli $conn, $engagement_id) {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS archived_count
         FROM engagement_chron_entries
         WHERE engagement_id = ? AND is_archived = 1'
    );
    if (!$stmt) {
        applicationLog('error', 'Unable to prepare the archived Chron count query', ['error' => $conn->error]);
        throw new RuntimeException('Unable to prepare the archived Chron count query.');
    }
    $stmt->bind_param('i', $engagement_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to count archived Chron entries.');
    }
    $result = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($result['archived_count'] ?? 0);
}

function countActiveChronLogEntries(mysqli $conn, $engagement_id) {
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS active_count
         FROM engagement_chron_entries
         WHERE engagement_id = ? AND is_archived = 0'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the Chron count query.');
    }
    $stmt->bind_param('i', $engagement_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to count Chron entries.');
    }
    $count = (int) ($stmt->get_result()->fetch_assoc()['active_count'] ?? 0);
    $stmt->close();
    return $count;
}

function chronLogEntryExportTitle(array $entry) {
    $created = chronLogTimestampDetails($entry['created_at'] ?? '');
    $author = trim((string) ($entry['created_by_username'] ?? ''));
    if ($author === '' && !empty($entry['legacy_engagement_note'])) {
        $author = 'Migrated legacy note';
    } elseif ($author === '') {
        $author = 'System';
    }

    return $created['display'] . ' - ' . $author;
}
