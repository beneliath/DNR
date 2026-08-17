<?php

function chronLogDisplayTimezone() {
    static $timezone = null;
    if ($timezone instanceof DateTimeZone) {
        return $timezone;
    }

    $timezone_name = getenv('DNR_TIMEZONE') ?: 'America/Chicago';
    try {
        $timezone = new DateTimeZone($timezone_name);
    } catch (Throwable $exception) {
        $timezone = new DateTimeZone('UTC');
    }

    return $timezone;
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

function fetchChronLogEntriesForArchiveState(mysqli $conn, $engagement_id, $archive_state = 0) {
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
    $query .= ' ORDER BY ce.created_at DESC, ce.id DESC';

    $stmt = $conn->prepare($query);
    if (!$stmt) {
        error_log('Unable to prepare the Chron log query: ' . $conn->error);
        throw new RuntimeException('Unable to prepare the Chron log query.');
    }
    $stmt->bind_param('i', $engagement_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load the Chron log.');
    }
    $entries = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $entries;
}

function fetchChronLogEntries(mysqli $conn, $engagement_id, $include_archived = false) {
    return fetchChronLogEntriesForArchiveState(
        $conn,
        $engagement_id,
        $include_archived ? null : 0
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
        error_log('Unable to prepare the archived Chron count query: ' . $conn->error);
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
