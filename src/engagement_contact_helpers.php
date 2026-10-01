<?php

declare(strict_types=1);

/** @return array<string, string> */
function engagementContactRoles(): array
{
    return [
        'primary_host' => 'Primary Host',
        'on_site_contact' => 'On-Site Contact',
        'billing' => 'Billing',
        'travel' => 'Travel',
        'materials' => 'Materials',
    ];
}

function engagementContactRoleLabel(mixed $role): string
{
    $role = trim((string) $role);
    return engagementContactRoles()[$role] ?? 'Event contact';
}

/** @param array<string, mixed> $contact */
function organizationContactRoleLabel(array $contact): string
{
    if (array_key_exists('organization_role_title', $contact)) {
        return trim((string) $contact['organization_role_title']);
    }
    $role = trim((string) ($contact['contact_role'] ?? ''));
    if ($role === 'other') {
        return trim((string) ($contact['contact_role_other'] ?? ''));
    }
    return $role !== '' ? ucfirst($role) : '';
}

/**
 * @return list<array{contact_id: int, contact_role: string}>
 */
function normalizeEngagementContactAssignments(mixed $submitted): array
{
    if ($submitted === null || $submitted === '') {
        return [];
    }
    if (!is_array($submitted) || count($submitted) > 200) {
        throw new InvalidArgumentException('Select valid event contacts and roles.');
    }

    $valid_roles = engagementContactRoles();
    $assignments = [];
    foreach ($submitted as $contact_id_value => $submitted_roles) {
        $contact_id_text = is_int($contact_id_value)
            ? (string) $contact_id_value
            : trim((string) $contact_id_value);
        if ($contact_id_text === '' || !ctype_digit($contact_id_text)) {
            throw new InvalidArgumentException('Select valid event contacts and roles.');
        }
        $contact_id = (int) $contact_id_text;
        if ($contact_id < 1 || !is_array($submitted_roles) || count($submitted_roles) > count($valid_roles)) {
            throw new InvalidArgumentException('Select valid event contacts and roles.');
        }
        foreach ($submitted_roles as $submitted_role) {
            if (!is_scalar($submitted_role)) {
                throw new InvalidArgumentException('Select valid event contacts and roles.');
            }
            $contact_role = trim((string) $submitted_role);
            if (!array_key_exists($contact_role, $valid_roles)) {
                throw new InvalidArgumentException('Select a supported event contact role.');
            }
            $assignments[$contact_id . ':' . $contact_role] = [
                'contact_id' => $contact_id,
                'contact_role' => $contact_role,
            ];
        }
    }
    if (count($assignments) > 500) {
        throw new InvalidArgumentException('Too many event contact roles were selected.');
    }

    $role_order = array_flip(array_keys($valid_roles));
    $assignments = array_values($assignments);
    usort(
        $assignments,
        static function (array $left, array $right) use ($role_order): int {
            $contact_comparison = $left['contact_id'] <=> $right['contact_id'];
            return $contact_comparison !== 0
                ? $contact_comparison
                : ($role_order[$left['contact_role']] <=> $role_order[$right['contact_role']]);
        }
    );
    return $assignments;
}

/**
 * @param list<array{contact_id: int, contact_role: string}> $assignments
 * @return array<int, list<string>>
 */
function engagementContactAssignmentMap(array $assignments): array
{
    $assignment_map = [];
    foreach ($assignments as $assignment) {
        $assignment_map[$assignment['contact_id']][] = $assignment['contact_role'];
    }
    return $assignment_map;
}

/** @return list<array<string, mixed>> */
function fetchOrganizationContactOptions(mysqli $conn, int $organization_id, int $engagement_id = 0): array
{
    if ($organization_id < 1) {
        return [];
    }
    $stmt = $conn->prepare(
        'SELECT c.id, co.organization_id, c.contact_first_name, c.contact_last_name,
                c.contact_role, c.contact_role_other, c.contact_email, c.contact_phone,
                co.role_title AS organization_role_title
         FROM contact_organizations co
         INNER JOIN contacts c ON c.id = co.contact_id
         WHERE co.organization_id = ? AND c.is_deleted = 0
         ORDER BY c.contact_last_name, c.contact_first_name, c.id'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the organization contacts.');
    }
    $stmt->bind_param('i', $organization_id);
    $stmt->execute();
    $contacts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    if ($engagement_id > 0) {
        $ids = array_fill_keys(array_column($contacts, 'id'), true);
        foreach (fetchEngagementContacts($conn, $engagement_id) as $contact) {
            if (!isset($ids[$contact['id']])) $contacts[] = $contact;
        }
    }
    return $contacts;
}

/** @return list<array<string, mixed>> */
function searchEventContactOptions(mysqli $conn, int $organization_id, string $query): array
{
    $query = trim($query);
    if (mb_strlen($query, 'UTF-8') < 2) {
        return [];
    }
    $pattern = '%' . strtr(mb_substr($query, 0, 100, 'UTF-8'), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
    $stmt = $conn->prepare(
        "SELECT c.id, c.contact_first_name, c.contact_last_name, c.contact_email,
                c.contact_phone, co.role_title AS organization_role_title,
                (co.contact_id IS NOT NULL) AS is_affiliated,
                COALESCE(o.organization_name, '') AS organization_name
         FROM contacts c
         LEFT JOIN organizations o ON o.id = c.organization_id
         LEFT JOIN contact_organizations co ON co.contact_id = c.id AND co.organization_id = ?
         WHERE c.is_deleted = 0
           AND (CONCAT_WS(' ', c.contact_first_name, c.contact_last_name) LIKE ? ESCAPE '!'
                OR c.contact_email LIKE ? ESCAPE '!')
         ORDER BY c.contact_last_name, c.contact_first_name, c.id
         LIMIT 30"
    );
    $stmt->bind_param('iss', $organization_id, $pattern, $pattern);
    $stmt->execute();
    $contacts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $contacts;
}

/**
 * @param list<array{contact_id: int, contact_role: string}> $assignments
 */
function validateEngagementContactAssignments(
    mysqli $conn,
    int $organization_id,
    array $assignments,
    int $engagement_id = 0
): void {
    if ($assignments === []) {
        return;
    }
    $available_contact_ids = [];
    foreach (fetchOrganizationContactOptions($conn, $organization_id) as $contact) {
        $available_contact_ids[(int) $contact['id']] = true;
    }
    $existing = [];
    if ($engagement_id > 0) foreach (fetchEngagementContactAssignments($conn, $engagement_id) as $row) {
        $existing[$row['contact_id'] . ':' . $row['contact_role']] = true;
    }
    foreach ($assignments as $assignment) {
        if (isset($existing[$assignment['contact_id'] . ':' . $assignment['contact_role']])) continue;
        if (!isset($available_contact_ids[$assignment['contact_id']])) {
            throw new InvalidArgumentException(
                'Select only active contacts from the engagement organization.'
            );
        }
    }
}

/** @return list<array<string, mixed>> */
function fetchEngagementContacts(mysqli $conn, int $engagement_id): array
{
    $stmt = $conn->prepare(
        "SELECT c.id, ec.organization_id_snapshot AS organization_id,
                IF(co.contact_id IS NULL OR c.is_deleted=1,ec.contact_first_name_snapshot,c.contact_first_name) AS contact_first_name,
                IF(co.contact_id IS NULL OR c.is_deleted=1,ec.contact_last_name_snapshot,c.contact_last_name) AS contact_last_name,
                c.contact_role, c.contact_role_other,
                IF(co.contact_id IS NULL OR c.is_deleted=1,ec.contact_email_snapshot,c.contact_email) AS contact_email,
                IF(co.contact_id IS NULL OR c.is_deleted=1,ec.contact_phone_snapshot,c.contact_phone) AS contact_phone,
                COALESCE(co.role_title,ec.role_title_snapshot) AS organization_role_title,
                (co.contact_id IS NULL OR c.is_deleted=1) AS historical_affiliation,
                ec.contact_role AS engagement_contact_role
         FROM engagement_contacts ec
         INNER JOIN engagements e ON e.id = ec.engagement_id
         INNER JOIN contacts c ON c.id = ec.contact_id
         LEFT JOIN contact_organizations co ON co.contact_id = c.id
             AND co.organization_id = e.organization_id
         WHERE ec.engagement_id = ?
         ORDER BY FIELD(
                    ec.contact_role,
                    'primary_host', 'on_site_contact', 'billing', 'travel', 'materials'
                  ),
                  c.contact_last_name, c.contact_first_name, c.id"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the engagement contacts.');
    }
    $stmt->bind_param('i', $engagement_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $contacts_by_id = [];
    $roles_by_contact_id = [];
    while ($row = $result->fetch_assoc()) {
        $contact_id = (int) $row['id'];
        $engagement_contact_role = (string) $row['engagement_contact_role'];
        if (!isset($contacts_by_id[$contact_id])) {
            unset($row['engagement_contact_role']);
            $contacts_by_id[$contact_id] = $row;
        }
        $roles_by_contact_id[$contact_id][] = $engagement_contact_role;
    }
    $stmt->close();
    $engagement_contacts = [];
    foreach ($contacts_by_id as $contact_id => $contact) {
        $contact['engagement_contact_roles'] = $roles_by_contact_id[$contact_id];
        $engagement_contacts[] = $contact;
    }
    return $engagement_contacts;
}

/** Historical snapshots remain visible, but never supply live email recipients. */
function fetchActiveEngagementEmailContacts(mysqli $conn, int $engagementId): array
{
    return array_values(array_filter(fetchEngagementContacts($conn,$engagementId),
        static fn(array $contact): bool => empty($contact['historical_affiliation'])));
}

/** @return list<array{contact_id: int, contact_role: string}> */
function fetchEngagementContactAssignments(mysqli $conn, int $engagement_id): array
{
    $stmt = $conn->prepare(
        "SELECT contact_id, contact_role
         FROM engagement_contacts
         WHERE engagement_id = ?
         ORDER BY contact_id,
                  FIELD(contact_role, 'primary_host', 'on_site_contact', 'billing', 'travel', 'materials')"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the event contact assignments.');
    }
    $stmt->bind_param('i', $engagement_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $assignments = [];
    while ($row = $result->fetch_assoc()) {
        $assignments[] = [
            'contact_id' => (int) $row['contact_id'],
            'contact_role' => (string) $row['contact_role'],
        ];
    }
    $stmt->close();
    return $assignments;
}

/**
 * @param list<array{contact_id: int, contact_role: string}> $assignments
 */
function syncEngagementContacts(
    mysqli $conn,
    int $engagement_id,
    array $assignments,
    int $created_by,
    bool $touch_engagement = true
): bool {
    $current_assignments = fetchEngagementContactAssignments($conn, $engagement_id);
    if ($current_assignments === $assignments) {
        return false;
    }

    $current = [];
    foreach ($current_assignments as $row) $current[$row['contact_id'] . ':' . $row['contact_role']] = $row;
    $desired = [];
    foreach ($assignments as $row) $desired[$row['contact_id'] . ':' . $row['contact_role']] = $row;
    foreach (array_diff_key($current, $desired) as $row) {
        $conn->execute_query('DELETE FROM engagement_contacts WHERE engagement_id=? AND contact_id=? AND contact_role=?',
            [$engagement_id,$row['contact_id'],$row['contact_role']]);
    }
    foreach (array_diff_key($desired, $current) as $row) {
        $conn->execute_query('INSERT INTO engagement_contacts (engagement_id,contact_id,contact_role,created_by) VALUES (?,?,?,?)',
            [$engagement_id,$row['contact_id'],$row['contact_role'],$created_by]);
    }

    if ($touch_engagement) {
        $touch_stmt = $conn->prepare(
            'UPDATE engagements SET updated_at = CURRENT_TIMESTAMP(6) WHERE id = ?'
        );
        if (!$touch_stmt) {
            throw new RuntimeException('Unable to update the engagement timestamp.');
        }
        $touch_stmt->bind_param('i', $engagement_id);
        if (!$touch_stmt->execute() || $touch_stmt->affected_rows > 1) {
            $touch_stmt->close();
            throw new RuntimeException('Unable to update the engagement timestamp.');
        }
        $touch_stmt->close();
    }
    return true;
}

/** @return list<array<string,mixed>> */
function fetchEngagementContactHistory(mysqli $conn, int $engagementId): array
{
    return $conn->execute_query('SELECT * FROM engagement_contact_history WHERE engagement_id=? ORDER BY ended_at DESC,id DESC LIMIT 100', [$engagementId])->fetch_all(MYSQLI_ASSOC);
}
