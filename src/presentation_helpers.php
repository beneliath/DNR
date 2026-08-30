<?php

declare(strict_types=1);

require_once __DIR__ . '/presentation_asset_helpers.php';

function presentationScalarValue(array $presentation, $key)
{
    $value = $presentation[$key] ?? '';
    if (!is_scalar($value)) {
        throw new InvalidArgumentException('Invalid presentation submission.');
    }

    return trim((string) $value);
}

function presentationDateIsValid($date)
{
    $parsed_date = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed_date instanceof DateTimeImmutable
        && $parsed_date->format('Y-m-d') === $date;
}

function requirePresentationDateWithinEngagement($topic_title, $presentation_date, $event_start_date, $event_end_date)
{
    if (!presentationDateIsValid((string) $presentation_date)) {
        throw new InvalidArgumentException("Use a valid date for presentation '{$topic_title}'.");
    }
    if (presentationDateIsValid((string) $event_start_date)
        && presentationDateIsValid((string) $event_end_date)
        && ($presentation_date < $event_start_date || $presentation_date > $event_end_date)
    ) {
        throw new InvalidArgumentException(
            "Presentation date for '{$topic_title}' must be between the engagement start and end dates."
        );
    }
}

function normalizePresentationTime($time)
{
    $time = trim((string) $time);
    if ($time === '') {
        return null;
    }

    if (preg_match('/^(0?[1-9]|1[0-2]):([0-5][0-9])\s*(AM|PM)$/i', $time, $matches)) {
        $parsed_time = DateTimeImmutable::createFromFormat(
            '!g:i A',
            (int) $matches[1] . ':' . $matches[2] . ' ' . strtoupper($matches[3])
        );
        if ($parsed_time instanceof DateTimeImmutable) {
            return $parsed_time->format('H:i:s');
        }
    }

    if (preg_match('/^([01][0-9]|2[0-3])([0-5][0-9])$/', $time, $matches)) {
        $time = $matches[1] . ':' . $matches[2];
    }

    if (preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])(?::([0-5][0-9]))?$/', $time)) {
        $parsed_time = DateTimeImmutable::createFromFormat(
            strlen($time) === 5 ? '!H:i' : '!H:i:s',
            $time
        );
        if ($parsed_time instanceof DateTimeImmutable) {
            return $parsed_time->format('H:i:s');
        }
    }

    throw new InvalidArgumentException('Use a valid presentation time, such as 9:30 AM.');
}

function presentationTimeParts($time)
{
    $time = trim((string) $time);
    if ($time === '') {
        return ['', 'AM'];
    }

    try {
        $normalized_time = normalizePresentationTime($time);
    } catch (InvalidArgumentException $exception) {
        return [$time, 'AM'];
    }

    $parsed_time = DateTimeImmutable::createFromFormat('!H:i:s', (string) $normalized_time);
    if (!$parsed_time instanceof DateTimeImmutable) {
        return [$time, 'AM'];
    }
    return [$parsed_time->format('h:i'), $parsed_time->format('A')];
}

function formatPresentationTime($time)
{
    $time = trim((string) $time);
    if ($time === '') {
        return '';
    }
    try {
        $normalized = normalizePresentationTime($time);
        $parsed = DateTimeImmutable::createFromFormat('!H:i:s', (string) $normalized);
        return $parsed instanceof DateTimeImmutable ? $parsed->format('h:i A') : $time;
    } catch (InvalidArgumentException $exception) {
        return $time;
    }
}

function normalizeEngagementPresentations(
    $submitted_presentations,
    $event_start_date,
    $event_end_date,
    $default_speaker,
    $allow_existing_ids = false,
    array $asset_form_keys = []
) {
    if ($submitted_presentations === null) {
        return [];
    }
    if (!is_array($submitted_presentations)) {
        throw new InvalidArgumentException('Invalid presentation submission.');
    }

    $normalized_presentations = [];
    foreach ($submitted_presentations as $presentation_form_key => $submitted_presentation) {
        if (!is_array($submitted_presentation)) {
            throw new InvalidArgumentException('Invalid presentation submission.');
        }

        $topic_title = presentationScalarValue($submitted_presentation, 'topic_title');
        $presentation_date = presentationScalarValue($submitted_presentation, 'presentation_date');
        $presentation_time = presentationScalarValue($submitted_presentation, 'presentation_time');
        $speaker_name = presentationScalarValue($submitted_presentation, 'speaker_name');
        $duration_minutes_raw = presentationScalarValue($submitted_presentation, 'duration_minutes');
        $expected_attendance_raw = presentationScalarValue($submitted_presentation, 'expected_attendance');
        $actual_attendance_raw = presentationScalarValue($submitted_presentation, 'actual_attendance');

        $has_nondefault_speaker = $speaker_name !== '' && $speaker_name !== (string) $default_speaker;
        $has_presentation_content = $topic_title !== ''
            || $presentation_date !== ''
            || $presentation_time !== ''
            || $expected_attendance_raw !== ''
            || $actual_attendance_raw !== ''
            || $has_nondefault_speaker
            || isset($asset_form_keys[(string) $presentation_form_key]);

        if (!$has_presentation_content) {
            continue;
        }

        if ($topic_title === '') {
            throw new InvalidArgumentException('Enter a topic/title for each presentation.');
        }

        if (mb_strlen($topic_title, 'UTF-8') > 255) {
            throw new InvalidArgumentException('Presentation topic/title must be 255 characters or fewer.');
        }

        if ($presentation_date === '') {
            throw new InvalidArgumentException("Enter a date for presentation '{$topic_title}'.");
        }
        requirePresentationDateWithinEngagement(
            $topic_title,
            $presentation_date,
            $event_start_date,
            $event_end_date
        );

        if ($presentation_time === '') {
            throw new InvalidArgumentException("Enter a time for presentation '{$topic_title}'.");
        }
        $normalized_presentation_time = normalizePresentationTime($presentation_time);

        $speaker_name = $speaker_name !== '' ? $speaker_name : (string) $default_speaker;
        if (mb_strlen($speaker_name, 'UTF-8') > 255) {
            throw new InvalidArgumentException('Presentation speaker name must be 255 characters or fewer.');
        }

        $duration_minutes = 60;
        if ($duration_minutes_raw !== '') {
            $duration_minutes = filter_var($duration_minutes_raw, FILTER_VALIDATE_INT);
            if ($duration_minutes === false || $duration_minutes < 1 || $duration_minutes > 1440) {
                throw new InvalidArgumentException(
                    'Presentation duration must be a whole number between 1 and 1440 minutes.'
                );
            }
        }

        $expected_attendance = null;
        if ($expected_attendance_raw !== '') {
            $expected_attendance = filter_var($expected_attendance_raw, FILTER_VALIDATE_INT);
            if ($expected_attendance === false || $expected_attendance < 1) {
                throw new InvalidArgumentException('Expected presentation attendance must be a whole number of at least 1.');
            }
        }

        $actual_attendance = null;
        if ($actual_attendance_raw !== '') {
            $actual_attendance = filter_var($actual_attendance_raw, FILTER_VALIDATE_INT);
            if ($actual_attendance === false || $actual_attendance < 0 || $actual_attendance > 2147483647) {
                throw new InvalidArgumentException(
                    'Actual presentation attendance must be zero or a positive whole number.'
                );
            }
        }

        $presentation_id = null;
        if ($allow_existing_ids && array_key_exists('id', $submitted_presentation)) {
            $submitted_id = presentationScalarValue($submitted_presentation, 'id');
            if ($submitted_id !== '') {
                $presentation_id = filter_var($submitted_id, FILTER_VALIDATE_INT);
                if ($presentation_id === false || $presentation_id < 1) {
                    throw new InvalidArgumentException('Invalid presentation submission.');
                }
            }
        }

        $normalized_presentations[] = [
            '_form_key' => (string) $presentation_form_key,
            'id' => $presentation_id,
            'topic_title' => $topic_title,
            'presentation_date' => $presentation_date,
            'presentation_time' => $normalized_presentation_time,
            'speaker_name' => $speaker_name,
            'duration_minutes' => $duration_minutes,
            'expected_attendance' => $expected_attendance,
            'actual_attendance' => $actual_attendance,
        ];
    }

    return $normalized_presentations;
}

function requirePresentationForConfirmedEngagement(
    $confirmation_status,
    array $presentations,
    $lifecycle_status = 'active'
)
{
    if ($confirmation_status === 'confirmed'
        && (string) $lifecycle_status === 'active'
        && count($presentations) === 0
    ) {
        throw new InvalidArgumentException(
            'Add at least one presentation before setting the engagement status to confirmed.'
        );
    }
}

function presentationRemovalRequiresReview(
    $confirmation_status,
    $active_presentation_count,
    $lifecycle_status = 'active'
)
{
    return (string) $confirmation_status === 'confirmed'
        && (string) $lifecycle_status === 'active'
        && (int) $active_presentation_count <= 1;
}

function countArchivedEngagementPresentations(mysqli $conn, $engagement_id)
{
    $stmt = $conn->prepare(
        'SELECT COUNT(*) AS presentation_count
         FROM presentations
         WHERE engagement_id = ? AND is_archived = 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to count archived presentations.');
    }
    $stmt->bind_param('i', $engagement_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to count archived presentations.');
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return (int) ($row['presentation_count'] ?? 0);
}

function fetchArchivedEngagementPresentations(mysqli $conn, $engagement_id)
{
    $stmt = $conn->prepare(
        'SELECT p.id, p.topic_title, p.presentation_date, p.presentation_time,
                p.speaker_name, p.duration_minutes, p.expected_attendance,
                p.actual_attendance, p.archived_at,
                archiver.username AS archived_by_username
         FROM presentations p
         LEFT JOIN users archiver ON archiver.id = p.archived_by
         WHERE p.engagement_id = ? AND p.is_archived = 1
         ORDER BY p.presentation_date, p.presentation_time, p.id'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to load archived presentations.');
    }
    $stmt->bind_param('i', $engagement_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to load archived presentations.');
    }
    $presentations = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    return $presentations;
}

function engagementPresentationMatches(array $current, array $submitted)
{
    $current_expected_attendance = $current['expected_attendance'] === null
        ? null
        : (int) $current['expected_attendance'];
    $current_actual_attendance = ($current['actual_attendance'] ?? null) === null
        ? null
        : (int) $current['actual_attendance'];

    return (string) $current['topic_title'] === (string) $submitted['topic_title']
        && ($current['presentation_date'] ?: null) === $submitted['presentation_date']
        && ($current['presentation_time'] ?: null) === $submitted['presentation_time']
        && (string) $current['speaker_name'] === (string) $submitted['speaker_name']
        && (int) ($current['duration_minutes'] ?? 60) === (int) ($submitted['duration_minutes'] ?? 60)
        && $current_expected_attendance === $submitted['expected_attendance']
        && $current_actual_attendance === ($submitted['actual_attendance'] ?? null);
}

function syncEngagementPresentations(mysqli $conn, $engagement_id, array $presentations)
{
    $presentations_changed = false;
    $current_stmt = $conn->prepare(
        'SELECT id, topic_title, presentation_date, presentation_time, speaker_name,
                duration_minutes, expected_attendance, actual_attendance
         FROM presentations
         WHERE engagement_id = ? AND is_archived = 0
         FOR UPDATE'
    );
    if (!$current_stmt) {
        throw new RuntimeException('Unable to prepare presentation changes.');
    }
    $current_stmt->bind_param('i', $engagement_id);
    if (!$current_stmt->execute()) {
        $current_stmt->close();
        throw new RuntimeException('Unable to load presentations for updating.');
    }

    $current_presentations = [];
    $current_result = $current_stmt->get_result();
    while ($current_presentation = $current_result->fetch_assoc()) {
        $current_presentations[(int) $current_presentation['id']] = $current_presentation;
    }
    $current_stmt->close();

    $submitted_ids = [];
    foreach ($presentations as $presentation) {
        if ($presentation['id'] === null) {
            continue;
        }
        $presentation_id = (int) $presentation['id'];
        if (isset($submitted_ids[$presentation_id])
            || !isset($current_presentations[$presentation_id])
        ) {
            throw new InvalidArgumentException('Invalid presentation submission.');
        }
        $submitted_ids[$presentation_id] = true;
    }

    $missing_ids = array_diff(array_keys($current_presentations), array_keys($submitted_ids));
    if ($missing_ids) {
        $missing_titles = array_map(function ($presentation_id) use ($current_presentations) {
            return "'" . (string) $current_presentations[$presentation_id]['topic_title'] . "'";
        }, $missing_ids);
        throw new InvalidArgumentException(
            'Every active presentation must be included when saving. Reload the page and update or archive: '
            . implode(', ', $missing_titles) . '.'
        );
    }

    $update_stmt = null;
    $insert_stmt = null;
    foreach ($presentations as $presentation) {
        if ($presentation['id'] !== null) {
            $presentation_id = (int) $presentation['id'];
            $core_changed = !engagementPresentationMatches(
                $current_presentations[$presentation_id],
                $presentation
            );
            $asset_changes = is_array($presentation['asset_changes'] ?? null)
                ? $presentation['asset_changes']
                : [];
            if ($core_changed) {
                if (!$update_stmt) {
                    $update_stmt = $conn->prepare(
                        'UPDATE presentations
                         SET topic_title = ?, presentation_date = ?, presentation_time = ?,
                             speaker_name = ?, duration_minutes = ?, expected_attendance = ?,
                             actual_attendance = ?
                         WHERE id = ? AND engagement_id = ?'
                    );
                    if (!$update_stmt) {
                        throw new RuntimeException('Unable to prepare presentation updates.');
                    }
                }
                $update_stmt->bind_param(
                    'ssssiiiii',
                    $presentation['topic_title'],
                    $presentation['presentation_date'],
                    $presentation['presentation_time'],
                    $presentation['speaker_name'],
                    $presentation['duration_minutes'],
                    $presentation['expected_attendance'],
                    $presentation['actual_attendance'],
                    $presentation_id,
                    $engagement_id
                );
                if (!$update_stmt->execute() || $update_stmt->affected_rows !== 1) {
                    throw new RuntimeException('Unable to update a presentation.');
                }
                $presentations_changed = true;
            }
            if (applyPresentationAssetChanges(
                $conn,
                (int) $engagement_id,
                $presentation_id,
                $asset_changes
            )) {
                $presentations_changed = true;
            }
            continue;
        }

        if (!$insert_stmt) {
            $insert_stmt = $conn->prepare(
                'INSERT INTO presentations
                    (engagement_id, topic_title, presentation_date, presentation_time,
                     speaker_name, duration_minutes, expected_attendance, actual_attendance)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            if (!$insert_stmt) {
                throw new RuntimeException('Unable to prepare new presentations.');
            }
        }
        $insert_stmt->bind_param(
            'issssiii',
            $engagement_id,
            $presentation['topic_title'],
            $presentation['presentation_date'],
            $presentation['presentation_time'],
            $presentation['speaker_name'],
            $presentation['duration_minutes'],
            $presentation['expected_attendance'],
            $presentation['actual_attendance']
        );
        if (!$insert_stmt->execute()) {
            throw new RuntimeException('Unable to add a presentation.');
        }
        $presentation_id = (int) $conn->insert_id;
        applyPresentationAssetChanges(
            $conn,
            (int) $engagement_id,
            $presentation_id,
            is_array($presentation['asset_changes'] ?? null) ? $presentation['asset_changes'] : []
        );
        $presentations_changed = true;
    }

    if ($update_stmt) {
        $update_stmt->close();
    }
    if ($insert_stmt) {
        $insert_stmt->close();
    }

    return $presentations_changed;
}
