<?php

declare(strict_types=1);

/**
 * @param list<string> $addresses
 * @return array<string, array<string, list<array<string, mixed>>>>
 */
function inboundEmailBatchMatches(mysqli $conn, array $addresses): array
{
    $addresses = array_values(array_unique(array_filter($addresses)));
    if (count($addresses) > INBOUND_EMAIL_MAX_PARTICIPANTS) {
        throw new InvalidArgumentException('The inbound message has too many participants.');
    }
    $matches = ['users' => [], 'contacts' => [], 'organizations' => []];
    if ($addresses === []) {
        return $matches;
    }
    $placeholders = implode(',', array_fill(0, count($addresses), '?'));
    $queries = [
        'users' => "SELECT id, username, verified_email AS address FROM users
            WHERE verified_email IN ($placeholders) AND account_status = 'active'",
        'contacts' => "SELECT * FROM (
            SELECT contact.id, contact.normalized_email AS address,
                TRIM(CONCAT_WS(' ', contact.contact_first_name, contact.contact_last_name)) AS label,
                organization.id AS organization_id,
                COALESCE(organization.organization_name, '') AS organization_label,
                ROW_NUMBER() OVER (PARTITION BY contact.normalized_email ORDER BY contact.id) AS match_number
            FROM contacts contact
            LEFT JOIN organizations organization ON organization.id = contact.organization_id
            WHERE contact.is_deleted = 0 AND contact.normalized_email IN ($placeholders)
                AND (organization.id IS NULL OR organization.is_deleted = 0)
        ) matched WHERE match_number <= 25",
        'organizations' => "SELECT * FROM (
            SELECT id, normalized_email AS address, organization_name AS label,
                ROW_NUMBER() OVER (PARTITION BY normalized_email ORDER BY id) AS match_number
            FROM organizations WHERE is_deleted = 0 AND normalized_email IN ($placeholders)
        ) matched WHERE match_number <= 25",
    ];
    foreach ($queries as $kind => $query) {
        $stmt = $conn->prepare($query);
        $stmt->bind_param(str_repeat('s', count($addresses)), ...$addresses);
        $stmt->execute();
        foreach ($stmt->get_result()->fetch_all(MYSQLI_ASSOC) as $row) {
            $address = (string) $row['address'];
            unset($row['address'], $row['match_number']);
            $row['id'] = (int) $row['id'];
            if ($kind === 'contacts') {
                $row['organization_id'] = $row['organization_id'] === null ? null : (int) $row['organization_id'];
            }
            $matches[$kind][$address][] = $row;
        }
        $stmt->close();
    }
    return $matches;
}

/** @return list<array{id: int, username: string}> */
function inboundEmailUserMatches(mysqli $conn, string $address): array
{
    $stmt = $conn->prepare(
        "SELECT id, username FROM users
         WHERE verified_email = ? AND account_status = 'active'
         ORDER BY id LIMIT 3"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound user matching.');
    }
    $stmt->bind_param('s', $address);
    $stmt->execute();
    $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(
        static fn(array $row): array => [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
        ],
        $matches
    );
}

/**
 * @return list<array{id: int, label: string, organization_id: ?int, organization_label: string}>
 */
function inboundEmailContactMatches(mysqli $conn, string $address): array
{
    $stmt = $conn->prepare(
        "SELECT contact.id,
                TRIM(CONCAT_WS(' ', contact.contact_first_name, contact.contact_last_name)) AS label,
                organization.id AS organization_id,
                organization.organization_name AS organization_label
         FROM contacts contact
         LEFT JOIN organizations organization ON organization.id = contact.organization_id
         WHERE LOWER(TRIM(contact.contact_email)) = ?
           AND contact.is_deleted = 0
           AND (organization.id IS NULL OR organization.is_deleted = 0)
         ORDER BY contact.id LIMIT 25"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound Contact matching.');
    }
    $stmt->bind_param('s', $address);
    $stmt->execute();
    $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(
        static fn(array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
            'organization_id' => $row['organization_id'] !== null
                ? (int) $row['organization_id']
                : null,
            'organization_label' => (string) ($row['organization_label'] ?? ''),
        ],
        $matches
    );
}

/** @return list<array{id: int, label: string}> */
function inboundEmailOrganizationMatches(mysqli $conn, string $address): array
{
    $stmt = $conn->prepare(
        'SELECT id, organization_name AS label
         FROM organizations
         WHERE LOWER(TRIM(email)) = ? AND is_deleted = 0
         ORDER BY id LIMIT 25'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound Organization matching.');
    }
    $stmt->bind_param('s', $address);
    $stmt->execute();
    $matches = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map(
        static fn(array $row): array => [
            'id' => (int) $row['id'],
            'label' => (string) $row['label'],
        ],
        $matches
    );
}

/** @return array<string, mixed>|null */
function inboundEmailInquiryMatch(mysqli $conn, int $inquiryId): ?array
{
    if ($inquiryId < 1) {
        return null;
    }
    $stmt = $conn->prepare(
        "SELECT inquiry.id, inquiry.organization_id, inquiry.title,
                inquiry.preferred_start_date, inquiry.preferred_end_date,
                inquiry.stage, inquiry.converted_engagement_id,
                organization.organization_name AS organization_label
         FROM booking_inquiries inquiry
         LEFT JOIN organizations organization ON organization.id = inquiry.organization_id
         WHERE inquiry.id = ?
           AND inquiry.stage IN (
             'new', 'contacted', 'qualified', 'awaiting_details', 'proposal_sent', 'booked'
           ) LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound Inquiry matching.');
    }
    $stmt->bind_param('i', $inquiryId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        return null;
    }
    $dates = trim((string) ($row['preferred_start_date'] ?? ''));
    if (!empty($row['preferred_end_date']) && $row['preferred_end_date'] !== $dates) {
        $dates .= ' – ' . $row['preferred_end_date'];
    }
    return [
        'id' => (int) $row['id'],
        'organization_id' => (int) ($row['organization_id'] ?? 0),
        'label' => implode(' · ', array_filter([
            (string) $row['title'], (string) ($row['organization_label'] ?? ''), $dates,
        ])),
        'title' => (string) $row['title'],
        'organization_label' => (string) ($row['organization_label'] ?? ''),
        'date_label' => $dates,
        'stage' => (string) $row['stage'],
        'converted_engagement_id' => (int) ($row['converted_engagement_id'] ?? 0),
        'marker' => applicationInquiryInboundMarker((int) $row['id']),
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array{
 *   id: int, organization_id: int, label: string, title: string, organization_label: string,
 *   date_label: string, lifecycle_status: string, marker: string
 * }
 */
function inboundEmailEngagementRoute(array $row): array
{
    $id = (int) ($row['id'] ?? 0);
    $organization = (string) ($row['organization_label'] ?? '');
    $title = trim((string) ($row['event_title'] ?? ''));
    if ($title === '') {
        $title = $organization;
    }
    $startDate = (string) ($row['event_start_date'] ?? '');
    $endDate = (string) ($row['event_end_date'] ?? '');
    $dateLabel = $startDate;
    if ($endDate !== '' && $endDate !== $startDate) {
        $dateLabel .= ' – ' . $endDate;
    }
    $parts = array_values(array_filter([$title, $organization, $dateLabel]));
    return [
        'id' => $id,
        'organization_id' => (int) ($row['organization_id'] ?? 0),
        'label' => implode(' · ', $parts),
        'title' => $title,
        'organization_label' => $organization,
        'date_label' => $dateLabel,
        'lifecycle_status' => (string) ($row['lifecycle_status'] ?? 'active'),
        'marker' => applicationInboundMarker($id),
    ];
}

/**
 * @return array{
 *   id: int, organization_id: int, label: string, title: string, organization_label: string,
 *   date_label: string, lifecycle_status: string, marker: string
 * }|null
 */
function inboundEmailEngagementMatch(mysqli $conn, int $engagementId): ?array
{
    if ($engagementId < 1) {
        return null;
    }
    $stmt = $conn->prepare(
        'SELECT engagement.id, engagement.organization_id, engagement.event_title,
                engagement.event_start_date, engagement.event_end_date,
                engagement.lifecycle_status,
                organization.organization_name AS organization_label
         FROM engagements engagement
         INNER JOIN organizations organization
            ON organization.id = engagement.organization_id
         WHERE engagement.id = ?
           AND engagement.is_deleted = 0
           AND organization.is_deleted = 0
         LIMIT 1'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound Engagement matching.');
    }
    $stmt->bind_param('i', $engagementId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ? inboundEmailEngagementRoute($row) : null;
}

/**
 * @return list<array{
 *   id: int, organization_id: int, label: string, title: string, organization_label: string,
 *   date_label: string, lifecycle_status: string, marker: string
 * }>
 */
function searchInboundEmailEngagements(
    mysqli $conn,
    string $search,
    int $limit = 30
): array
{
    $search = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $search) ?? '';
    $search = trim(mb_substr($search, 0, 100, 'UTF-8'));
    $limit = max(1, min(50, $limit));

    $exactId = 0;
    $idMatch = [];
    $prefixPattern = inboundEmailMarkerPrefixPattern();
    if (preg_match('/\A([1-9][0-9]{0,9})\z/D', $search, $idMatch) === 1) {
        $candidate = (int) $idMatch[1];
        if ($candidate <= 2147483647) {
            $exactId = $candidate;
        }
    } elseif (preg_match(
        '/\A\[(' . $prefixPattern . ')#([1-9][0-9]{0,9})\.([A-Za-z0-9_-]{22})\]\z/iD',
        $search,
        $idMatch
    ) === 1) {
        $candidate = (int) $idMatch[2];
        if ($candidate <= 2147483647
            && applicationInboundMarkerIsValid((string) $idMatch[1], $candidate, (string) $idMatch[3])
        ) {
            $exactId = $candidate;
        }
    }
    if ($exactId === 0 && mb_strlen($search, 'UTF-8') < 2) {
        return [];
    }

    $escapedSearch = strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']);
    $like = '%' . $escapedSearch . '%';
    $stmt = $conn->prepare(
        'SELECT engagement.id, engagement.organization_id, engagement.event_title,
                engagement.event_start_date, engagement.event_end_date,
                engagement.lifecycle_status,
                organization.organization_name AS organization_label
         FROM engagements engagement
         INNER JOIN organizations organization
            ON organization.id = engagement.organization_id
         WHERE engagement.is_deleted = 0
           AND organization.is_deleted = 0
           AND (
                engagement.id = ?
                OR engagement.event_title LIKE ? ESCAPE \'!\'
                OR organization.organization_name LIKE ? ESCAPE \'!\'
           )
         ORDER BY
            (engagement.id = ?) DESC,
            (COALESCE(engagement.event_end_date, engagement.event_start_date) < CURRENT_DATE),
            CASE WHEN COALESCE(engagement.event_end_date, engagement.event_start_date) >= CURRENT_DATE
                THEN engagement.event_start_date END ASC,
            CASE WHEN COALESCE(engagement.event_end_date, engagement.event_start_date) < CURRENT_DATE
                THEN engagement.event_start_date END DESC,
            engagement.id DESC
         LIMIT ' . $limit
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare inbound Engagement search.');
    }
    $stmt->bind_param('issi', $exactId, $like, $like, $exactId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return array_map('inboundEmailEngagementRoute', $rows);
}
