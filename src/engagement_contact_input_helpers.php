<?php

declare(strict_types=1);

require_once __DIR__ . '/engagement_contact_helpers.php';
require_once __DIR__ . '/contact_organization_helpers.php';

/** @return list<int> */
function normalizeEngagementAddedContactIds(mixed $submitted): array
{
    if ($submitted === null || $submitted === '') {
        return [];
    }
    if (!is_array($submitted) || count($submitted) > 200) {
        throw new InvalidArgumentException('Select valid existing contacts to add.');
    }
    $ids = [];
    foreach ($submitted as $value) {
        if (!is_scalar($value) || !ctype_digit((string) $value)
            || (int) $value < 1 || (string) (int) $value !== (string) $value
        ) {
            throw new InvalidArgumentException('Select valid existing contacts to add.');
        }
        $ids[(int) $value] = (int) $value;
    }
    return array_values($ids);
}

/** @return list<array<string, mixed>> */
function normalizeEngagementNewContacts(mixed $submitted): array
{
    if ($submitted === null || $submitted === '') {
        return [];
    }
    if (!is_array($submitted) || count($submitted) > 20) {
        throw new InvalidArgumentException('Add no more than 20 new contacts at a time.');
    }
    $contacts = [];
    foreach (array_values($submitted) as $index => $row) {
        if (!is_array($row)) {
            throw new InvalidArgumentException('Enter valid details for each new contact.');
        }
        foreach (['first_name', 'last_name', 'email', 'phone', 'phone_country_code', 'role_title'] as $field) {
            if (isset($row[$field]) && !is_scalar($row[$field])) {
                throw new InvalidArgumentException('Enter valid details for each new contact.');
            }
        }
        $role_title = trim((string) ($row['role_title'] ?? ''));
        $normalized = \Dnr\Domain\ContactInput::normalizeEmbedded(array_merge($row, [
            'role' => 'other',
            'role_other' => $role_title !== '' ? $role_title : 'Event contact',
        ]));
        if ($normalized['errors'] !== []) {
            throw new InvalidArgumentException('New contact ' . ($index + 1) . ': ' . $normalized['errors'][0]);
        }
        $assignments = normalizeEngagementContactAssignments([1 => $row['roles'] ?? []]);
        if ($assignments === []) {
            throw new InvalidArgumentException('Select at least one event role for new contact ' . ($index + 1) . '.');
        }
        $contacts[] = array_merge($normalized['data'], [
            'roles' => array_column($assignments, 'contact_role'),
        ]);
    }
    return $contacts;
}

/** Preserve all well-formed draft fields even if a different row fails validation.
 * @return list<array<string, mixed>>
 */
function engagementNewContactFormRows(mixed $submitted): array
{
    if (!is_array($submitted)) {
        return [];
    }
    $rows = [];
    foreach (array_slice(array_values($submitted), 0, 20) as $row) {
        if (!is_array($row)) {
            continue;
        }
        $draft = [];
        foreach (['first_name', 'last_name', 'email', 'phone', 'phone_country_code', 'role_title'] as $field) {
            $draft[$field] = is_scalar($row[$field] ?? null) ? (string) $row[$field] : '';
        }
        $draft['roles'] = is_array($row['roles'] ?? null)
            ? array_values(array_filter($row['roles'], static fn(mixed $role): bool => is_string($role)
                && array_key_exists($role, engagementContactRoles())))
            : [];
        $rows[] = $draft;
    }
    return $rows;
}

/** @param list<int> $contact_ids
 * @return list<array<string, mixed>>
 */
function fetchEngagementAddedContactOptions(mysqli $conn, array $contact_ids): array
{
    if ($contact_ids === []) {
        return [];
    }
    $placeholders = implode(', ', array_fill(0, count($contact_ids), '?'));
    $stmt = $conn->prepare(
        "SELECT id, contact_first_name, contact_last_name, contact_email,
                '' AS contact_role, '' AS contact_role_other
         FROM contacts WHERE is_deleted = 0 AND id IN ($placeholders)
         ORDER BY contact_last_name, contact_first_name, id"
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the added event contacts.');
    }
    $stmt->bind_param(str_repeat('i', count($contact_ids)), ...$contact_ids);
    $stmt->execute();
    $contacts = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    return $contacts;
}

/** Called only inside the event save transaction; the caller owns commit/rollback.
 * @param list<array{contact_id: int, contact_role: string}> $assignments
 * @param list<int> $added_contact_ids
 * @param list<array<string, mixed>> $new_contacts
 * @return list<array{contact_id: int, contact_role: string}>
 */
function prepareEngagementContactAssignments(
    mysqli $conn,
    int $organization_id,
    array $assignments,
    array $added_contact_ids,
    array $new_contacts
): array {
    $assignment_map = engagementContactAssignmentMap($assignments);
    foreach ($added_contact_ids as $contact_id) {
        if (!empty($assignment_map[$contact_id])) {
            ensureContactOrganizationAffiliation($conn, $contact_id, $organization_id);
        }
    }
    validateEngagementContactAssignments($conn, $organization_id, $assignments);
    foreach ($new_contacts as $contact) {
        $stmt = $conn->prepare(
            'INSERT INTO contacts (organization_id, contact_first_name, contact_last_name,
                contact_role, contact_role_other, contact_email, contact_phone)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to prepare the new event contact.');
        }
        $stmt->bind_param('issssss', $organization_id, $contact['first_name'], $contact['last_name'],
            $contact['role'], $contact['role_other'], $contact['email'], $contact['phone']);
        if (!$stmt->execute()) {
            $stmt->close();
            throw new RuntimeException('Unable to save the new event contact.');
        }
        $contact_id = (int) $conn->insert_id;
        $stmt->close();
        ensureContactOrganizationAffiliation($conn, $contact_id, $organization_id, (string) $contact['role_other']);
        $assignment_map[$contact_id] = $contact['roles'];
    }
    return normalizeEngagementContactAssignments($assignment_map);
}
