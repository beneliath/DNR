<?php

declare(strict_types=1);

/** @param array<int, int|null> $engagement_ids */
function lockFollowUpTaskEngagements(mysqli $conn, array $engagement_ids): void
{
    $engagement_ids = array_values(array_unique(array_filter(
        array_map(
            static fn($engagement_id): int => (int) $engagement_id,
            $engagement_ids
        ),
        static fn(int $engagement_id): bool => $engagement_id > 0
    )));
    sort($engagement_ids, SORT_NUMERIC);
    if ($engagement_ids === []) {
        return;
    }

    $stmt = $conn->prepare('SELECT id FROM engagements WHERE id = ? FOR UPDATE');
    if (!$stmt) {
        throw new RuntimeException('Unable to lock the task engagement.');
    }
    foreach ($engagement_ids as $engagement_id) {
        $stmt->bind_param('i', $engagement_id);
        $stmt->execute();
        if ($stmt->get_result()->num_rows !== 1) {
            $stmt->close();
            throw new InvalidArgumentException('The task engagement is no longer available.');
        }
    }
    $stmt->close();
}

/** @param array<int, int|null> $inquiryIds */
function lockFollowUpTaskInquiries(mysqli $conn, array $inquiryIds): void
{
    $inquiryIds = array_values(array_unique(array_filter(
        array_map(
            static fn($inquiryId): int => (int) $inquiryId,
            $inquiryIds
        ),
        static fn(int $inquiryId): bool => $inquiryId > 0
    )));
    sort($inquiryIds, SORT_NUMERIC);
    if ($inquiryIds === []) {
        return;
    }

    $stmt = $conn->prepare('SELECT id FROM booking_inquiries WHERE id = ? FOR UPDATE');
    if (!$stmt) {
        throw new RuntimeException('Unable to lock the task inquiry.');
    }
    foreach ($inquiryIds as $inquiryId) {
        $stmt->bind_param('i', $inquiryId);
        $stmt->execute();
        if ($stmt->get_result()->num_rows !== 1) {
            $stmt->close();
            throw new InvalidArgumentException('The task inquiry is no longer available.');
        }
    }
    $stmt->close();
}

function parseFollowUpTaskSubject($value)
{
    $value = trim((string) $value);
    if ($value === 'general' || $value === 'general:0') {
        return [
            'subject_type' => 'general',
            'subject_id' => null,
            'engagement_id' => null,
            'organization_id' => null,
            'contact_id' => null,
            'inquiry_id' => null,
        ];
    }

    if (!preg_match('/\A(engagement|organization|contact|inquiry):([1-9][0-9]*)\z/', $value, $matches)) {
        throw new InvalidArgumentException('Select a valid related record.');
    }

    $subject_type = $matches[1];
    $subject_id = (int) $matches[2];
    return [
        'subject_type' => $subject_type,
        'subject_id' => $subject_id,
        'engagement_id' => $subject_type === 'engagement' ? $subject_id : null,
        'organization_id' => $subject_type === 'organization' ? $subject_id : null,
        'contact_id' => $subject_type === 'contact' ? $subject_id : null,
        'inquiry_id' => $subject_type === 'inquiry' ? $subject_id : null,
    ];
}

function followUpTaskSubjectValue(array $task)
{
    $subject_type = (string) ($task['subject_type'] ?? 'general');
    if ($subject_type === 'general') {
        return 'general';
    }

    $id_field = $subject_type . '_id';
    $subject_id = (int) ($task[$id_field] ?? 0);
    return $subject_id > 0 ? $subject_type . ':' . $subject_id : 'general';
}

function followUpTaskDuplicateFormValues(array $task): array
{
    return [
        'title' => (string) ($task['title'] ?? ''),
        'details' => (string) ($task['details'] ?? ''),
        'status' => 'open',
        'priority' => (string) ($task['priority'] ?? 'normal'),
        'due_date' => (string) ($task['due_date'] ?? ''),
        'waiting_on' => '',
        'assigned_to' => $task['assigned_to'] ?? '',
    ];
}

function requireDifferentEngagementForTaskDuplicate(array $source_task, array $duplicate_task): void
{
    if (($duplicate_task['subject_type'] ?? '') !== 'engagement') {
        throw new InvalidArgumentException('Select the destination event for the duplicated task.');
    }

    $source_engagement_id = (int) ($source_task['engagement_id'] ?? 0);
    $destination_engagement_id = (int) ($duplicate_task['engagement_id'] ?? 0);
    if ($source_engagement_id > 0 && $source_engagement_id === $destination_engagement_id) {
        throw new InvalidArgumentException('Select a different destination event for the duplicated task.');
    }
}

function followUpTaskSubjectRecord(mysqli $conn, $subject_type, $subject_id = null)
{
    $subject_type = (string) $subject_type;
    $subject_id = $subject_id === null ? null : (int) $subject_id;
    if ($subject_type === 'general') {
        return [
            'type' => 'general',
            'id' => null,
            'label' => applicationGeneralWorkLabel(),
            'url' => 'tasks.php',
            'active' => true,
        ];
    }
    if ($subject_id < 1) {
        return null;
    }

    if ($subject_type === 'engagement') {
        $stmt = $conn->prepare(
            "SELECT e.id,
                    COALESCE(NULLIF(TRIM(e.event_title), ''), o.organization_name) AS label,
                    (e.is_deleted = 0 AND o.is_deleted = 0
                     AND e.lifecycle_status <> 'canceled') AS is_active
             FROM engagements e
             INNER JOIN organizations o ON o.id = e.organization_id
             WHERE e.id = ?"
        );
        $url = 'view_engagement.php?id=' . $subject_id;
    } elseif ($subject_type === 'organization') {
        $stmt = $conn->prepare(
            'SELECT id, organization_name AS label, (is_deleted = 0) AS is_active
             FROM organizations WHERE id = ?'
        );
        $url = 'view_organization.php?id=' . $subject_id;
    } elseif ($subject_type === 'contact') {
        $stmt = $conn->prepare(
            "SELECT c.id,
                    CONCAT_WS(' · ',
                        CONCAT(c.contact_last_name, ', ', c.contact_first_name),
                        NULLIF(o.organization_name, '')
                    ) AS label,
                    (c.is_deleted = 0 AND (o.id IS NULL OR o.is_deleted = 0)) AS is_active
             FROM contacts c
             LEFT JOIN organizations o ON o.id = c.organization_id
             WHERE c.id = ?"
        );
        $url = 'view_contact.php?id=' . $subject_id;
    } elseif ($subject_type === 'inquiry') {
        $stmt = $conn->prepare(
            "SELECT id, title AS label,
                    (stage NOT IN ('booked', 'declined')) AS is_active
             FROM booking_inquiries WHERE id = ?"
        );
        $url = 'view_inquiry.php?id=' . $subject_id;
    } else {
        return null;
    }

    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $subject_id);
    if (!$stmt->execute()) {
        $stmt->close();
        return null;
    }
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }

    return [
        'type' => $subject_type,
        'id' => $subject_id,
        'label' => (string) $row['label'],
        'url' => $url,
        'active' => !empty($row['is_active']),
    ];
}

function followUpTaskSubjectOptions(mysqli $conn)
{
    $options = [
        'general' => [],
        'engagement' => [],
        'organization' => [],
        'contact' => [],
        'inquiry' => [],
    ];

    $options['general'][] = [
        'value' => 'general',
        'label' => applicationGeneralWorkLabel(),
    ];

    $engagements = $conn->query(
        "SELECT e.id,
                COALESCE(NULLIF(TRIM(e.event_title), ''), o.organization_name) AS label,
                e.event_start_date
         FROM engagements e
         INNER JOIN organizations o ON o.id = e.organization_id
         WHERE e.is_deleted = 0 AND o.is_deleted = 0
           AND e.lifecycle_status <> 'canceled'
         ORDER BY e.event_start_date DESC, label, e.id"
    );
    if ($engagements) {
        while ($row = $engagements->fetch_assoc()) {
            $options['engagement'][] = [
                'value' => 'engagement:' . (int) $row['id'],
                'label' => $row['label'] . ' · ' . $row['event_start_date'],
            ];
        }
    }

    $organizations = $conn->query(
        'SELECT id, organization_name FROM organizations
         WHERE is_deleted = 0 ORDER BY organization_name, id'
    );
    if ($organizations) {
        while ($row = $organizations->fetch_assoc()) {
            $options['organization'][] = [
                'value' => 'organization:' . (int) $row['id'],
                'label' => $row['organization_name'],
            ];
        }
    }

    $contacts = $conn->query(
        "SELECT c.id, c.contact_first_name, c.contact_last_name, o.organization_name
         FROM contacts c
         LEFT JOIN organizations o ON o.id = c.organization_id
         WHERE c.is_deleted = 0 AND (o.id IS NULL OR o.is_deleted = 0)
         ORDER BY c.contact_last_name, c.contact_first_name, o.organization_name, c.id"
    );
    if ($contacts) {
        while ($row = $contacts->fetch_assoc()) {
            $options['contact'][] = [
                'value' => 'contact:' . (int) $row['id'],
                'label' => implode(' · ', array_filter([
                    trim($row['contact_last_name'] . ', ' . $row['contact_first_name']),
                    trim((string) ($row['organization_name'] ?? '')),
                ])),
            ];
        }
    }

    $inquiries = $conn->query(
        "SELECT id, title, stage FROM booking_inquiries
         WHERE stage NOT IN ('booked', 'declined')
         ORDER BY updated_at DESC, id DESC"
    );
    if ($inquiries) {
        while ($row = $inquiries->fetch_assoc()) {
            $options['inquiry'][] = [
                'value' => 'inquiry:' . (int) $row['id'],
                'label' => $row['title'] . ' · ' . ucwords(str_replace('_', ' ', $row['stage'])),
            ];
        }
    }

    return $options;
}

function searchFollowUpTaskSubjects(mysqli $conn, $search, $limit = 20, $subject_type = null)
{
    $search = trim(substr((string) $search, 0, 100));
    $limit = max(1, min(50, (int) $limit));
    $subject_type = in_array($subject_type, ['engagement', 'organization', 'contact', 'inquiry'], true)
        ? $subject_type
        : null;
    $options = $subject_type === null
        ? [[
            'value' => 'general',
            'label' => applicationGeneralWorkLabel(),
            'type' => 'general',
        ]]
        : [];
    if (strlen($search) < 2) {
        return $options;
    }

    $fulltext = fulltextSearchQuery($search);
    if ($fulltext === '') {
        return $options;
    }
    $per_type_limit = max(3, (int) ceil($limit / 4));

    if ($subject_type === null || $subject_type === 'engagement') {
        $engagement_stmt = $conn->prepare(
        "SELECT e.id,
                CONCAT(COALESCE(NULLIF(TRIM(e.event_title), ''), o.organization_name),
                       ' · ', DATE_FORMAT(e.event_start_date, '%Y-%m-%d')) AS label
         FROM engagements e
         INNER JOIN organizations o ON o.id = e.organization_id
         WHERE e.is_deleted = 0 AND o.is_deleted = 0
           AND e.lifecycle_status <> 'canceled'
           AND (
             MATCH(
                e.event_title, e.event_description, e.engagement_notes,
                e.caller_name, e.cancellation_reason
             )
                 AGAINST (? IN BOOLEAN MODE)
             OR MATCH(
                o.organization_name, o.notes, o.affiliation, o.distinctives,
                o.email, o.phone, o.physical_city, o.physical_state,
                o.mailing_city, o.mailing_state
             ) AGAINST (? IN BOOLEAN MODE)
           )
         ORDER BY e.event_start_date DESC, e.id DESC LIMIT ?"
        );
        $engagement_stmt->bind_param('ssi', $fulltext, $fulltext, $per_type_limit);
        $engagement_stmt->execute();
        foreach ($engagement_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $options[] = ['value' => 'engagement:' . (int) $row['id'], 'label' => $row['label'], 'type' => 'engagement'];
        }
        $engagement_stmt->close();
    }

    if ($subject_type === null || $subject_type === 'organization') {
        $organization_stmt = $conn->prepare(
        "SELECT id, organization_name AS label FROM organizations
         WHERE is_deleted = 0 AND MATCH(
            organization_name, notes, affiliation, distinctives, email, phone,
            physical_city, physical_state, mailing_city, mailing_state
         ) AGAINST (? IN BOOLEAN MODE)
         ORDER BY organization_name, id LIMIT ?"
        );
        $organization_stmt->bind_param('si', $fulltext, $per_type_limit);
        $organization_stmt->execute();
        foreach ($organization_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $options[] = ['value' => 'organization:' . (int) $row['id'], 'label' => $row['label'], 'type' => 'organization'];
        }
        $organization_stmt->close();
    }

    if ($subject_type === null || $subject_type === 'contact') {
        $contact_stmt = $conn->prepare(
        "SELECT c.id,
                CONCAT_WS(' · ',
                    CONCAT(c.contact_last_name, ', ', c.contact_first_name),
                    NULLIF(o.organization_name, '')
                ) AS label
         FROM contacts c
         LEFT JOIN organizations o ON o.id = c.organization_id
         WHERE c.is_deleted = 0 AND (o.id IS NULL OR o.is_deleted = 0)
           AND (
             MATCH(
                c.contact_first_name, c.contact_last_name, c.contact_email,
                c.contact_phone, c.contact_role_other, c.contact_notes
             ) AGAINST (? IN BOOLEAN MODE)
             OR (o.id IS NOT NULL AND MATCH(
                o.organization_name, o.notes, o.affiliation, o.distinctives,
                o.email, o.phone, o.physical_city, o.physical_state,
                o.mailing_city, o.mailing_state
             ) AGAINST (? IN BOOLEAN MODE))
           )
         ORDER BY c.contact_last_name, c.contact_first_name, c.id LIMIT ?"
        );
        $contact_stmt->bind_param('ssi', $fulltext, $fulltext, $per_type_limit);
        $contact_stmt->execute();
        foreach ($contact_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $options[] = ['value' => 'contact:' . (int) $row['id'], 'label' => $row['label'], 'type' => 'contact'];
        }
        $contact_stmt->close();
    }

    if ($subject_type === null || $subject_type === 'inquiry') {
        $inquiry_stmt = $conn->prepare(
            "SELECT id, CONCAT(title, ' · ', REPLACE(stage, '_', ' ')) AS label
             FROM booking_inquiries
             WHERE stage NOT IN ('booked', 'declined')
               AND MATCH(title, request_summary, source_detail, event_city, event_state)
                   AGAINST (? IN BOOLEAN MODE)
             ORDER BY updated_at DESC, id DESC LIMIT ?"
        );
        $inquiry_stmt->bind_param('si', $fulltext, $per_type_limit);
        $inquiry_stmt->execute();
        foreach ($inquiry_stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $options[] = [
                'value' => 'inquiry:' . (int) $row['id'],
                'label' => $row['label'],
                'type' => 'inquiry',
            ];
        }
        $inquiry_stmt->close();
    }

    return array_slice($options, 0, $limit + ($subject_type === null ? 1 : 0));
}

function followUpTaskUsers(mysqli $conn)
{
    $users = [];
    $result = $conn->query(
        "SELECT id, username, role FROM users
         WHERE account_status = 'active' ORDER BY username, id"
    );
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $users[] = $row;
        }
    }
    return $users;
}
