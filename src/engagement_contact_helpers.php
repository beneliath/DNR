<?php

declare(strict_types=1);

/** @return array<string, string> */
function engagementContactRoles(): array
{
    return [
        'primary_host' => 'Primary host',
        'on_site_contact' => 'On-site contact',
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
function fetchOrganizationContactOptions(mysqli $conn, int $organization_id): array
{
    if ($organization_id < 1) {
        return [];
    }
    $stmt = $conn->prepare(
        'SELECT id, organization_id, contact_first_name, contact_last_name,
                contact_role, contact_role_other, contact_email, contact_phone
         FROM contacts
         WHERE organization_id = ? AND is_deleted = 0
         ORDER BY contact_last_name, contact_first_name, id'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the organization contacts.');
    }
    $stmt->bind_param('i', $organization_id);
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
    array $assignments
): void {
    if ($assignments === []) {
        return;
    }
    $available_contact_ids = [];
    foreach (fetchOrganizationContactOptions($conn, $organization_id) as $contact) {
        $available_contact_ids[(int) $contact['id']] = true;
    }
    foreach ($assignments as $assignment) {
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
        "SELECT c.id, c.organization_id, c.contact_first_name, c.contact_last_name,
                c.contact_role, c.contact_role_other, c.contact_email, c.contact_phone,
                ec.contact_role AS engagement_contact_role
         FROM engagement_contacts ec
         INNER JOIN engagements e ON e.id = ec.engagement_id
         INNER JOIN contacts c ON c.id = ec.contact_id
             AND c.organization_id = e.organization_id
         WHERE ec.engagement_id = ? AND c.is_deleted = 0
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

    $delete_stmt = $conn->prepare('DELETE FROM engagement_contacts WHERE engagement_id = ?');
    if (!$delete_stmt) {
        throw new RuntimeException('Unable to prepare the event contact changes.');
    }
    $delete_stmt->bind_param('i', $engagement_id);
    if (!$delete_stmt->execute()) {
        $delete_stmt->close();
        throw new RuntimeException('Unable to clear the prior event contact assignments.');
    }
    $delete_stmt->close();

    if ($assignments !== []) {
        $insert_stmt = $conn->prepare(
            'INSERT INTO engagement_contacts
                (engagement_id, contact_id, contact_role, created_by)
             VALUES (?, ?, ?, ?)'
        );
        if (!$insert_stmt) {
            throw new RuntimeException('Unable to prepare the event contact assignments.');
        }
        $contact_id = 0;
        $contact_role = '';
        $insert_stmt->bind_param(
            'iisi',
            $engagement_id,
            $contact_id,
            $contact_role,
            $created_by
        );
        foreach ($assignments as $assignment) {
            $contact_id = $assignment['contact_id'];
            $contact_role = $assignment['contact_role'];
            if (!$insert_stmt->execute()) {
                $insert_stmt->close();
                throw new RuntimeException('Unable to save the event contact assignments.');
            }
        }
        $insert_stmt->close();
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
