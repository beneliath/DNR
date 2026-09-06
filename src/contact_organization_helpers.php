<?php

declare(strict_types=1);

/**
 * Normalize the additional affiliations submitted by a contact form.
 * The primary organization remains in contacts for older integrations.
 *
 * @return list<array{organization_id: int, role_title: string}>
 */
function normalizeContactOrganizationAffiliations(mixed $submitted, ?int $primary_organization_id = null): array
{
    if ($submitted === null || $submitted === '') {
        return [];
    }
    if (!is_array($submitted) || count($submitted) > 100) {
        throw new InvalidArgumentException('Select valid additional organizations.');
    }
    $affiliations = [];
    foreach ($submitted as $row) {
        if (!is_array($row)
            || !is_scalar($row['organization_id'] ?? '')
            || !is_scalar($row['role_title'] ?? '')
        ) {
            throw new InvalidArgumentException('Select valid additional organizations and titles.');
        }
        $id_text = trim((string) ($row['organization_id'] ?? ''));
        $role_title = trim((string) ($row['role_title'] ?? ''));
        if ($id_text === '' && $role_title === '') {
            continue;
        }
        $organization_id = filter_var($id_text, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($organization_id === false) {
            throw new InvalidArgumentException('Select an organization for each additional title.');
        }
        if ($organization_id === $primary_organization_id || isset($affiliations[$organization_id])) {
            throw new InvalidArgumentException('Select each organization only once.');
        }
        if (mb_strlen($role_title) > 255) {
            throw new InvalidArgumentException('Organization role or title must be 255 characters or fewer.');
        }
        $affiliations[$organization_id] = [
            'organization_id' => $organization_id,
            'role_title' => $role_title,
        ];
    }
    ksort($affiliations);
    return array_values($affiliations);
}

/** @return list<array<string, mixed>> */
function fetchContactOrganizations(mysqli $conn, int $contact_id): array
{
    return fetchContactOrganizationsForContacts($conn, [$contact_id])[$contact_id] ?? [];
}

/**
 * @param list<int> $contact_ids
 * @return array<int, list<array<string, mixed>>>
 */
function fetchContactOrganizationsForContacts(mysqli $conn, array $contact_ids): array
{
    $contact_ids = array_values(array_unique(array_filter(array_map('intval', $contact_ids), static fn(int $id): bool => $id > 0)));
    if ($contact_ids === []) {
        return [];
    }
    // Values are normalized integers, so this bounded ID list is safe to interpolate.
    $id_list = implode(',', $contact_ids);
    $result = $conn->query(
        "SELECT co.contact_id, co.organization_id, co.role_title,
                o.organization_name, o.is_deleted AS organization_is_deleted,
                (c.organization_id = co.organization_id) AS is_primary
         FROM contact_organizations co
         INNER JOIN contacts c ON c.id = co.contact_id
         INNER JOIN organizations o ON o.id = co.organization_id
         WHERE co.contact_id IN ({$id_list})
         ORDER BY is_primary DESC, o.organization_name, co.organization_id"
    );
    if (!$result) {
        throw new RuntimeException('Unable to load contact organizations.');
    }
    $affiliations = [];
    while ($row = $result->fetch_assoc()) {
        $affiliations[(int) $row['contact_id']][] = $row;
    }
    return $affiliations;
}

function contactBelongsToOrganization(mysqli $conn, int $contact_id, int $organization_id): bool
{
    $stmt = $conn->prepare(
        'SELECT co.contact_id FROM contact_organizations co
         INNER JOIN contacts c ON c.id = co.contact_id AND c.is_deleted = 0
         INNER JOIN organizations o ON o.id = co.organization_id AND o.is_deleted = 0
         WHERE co.contact_id = ? AND co.organization_id = ?'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to check the contact organization.');
    }
    $stmt->bind_param('ii', $contact_id, $organization_id);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

/**
 * Add an affiliation without changing the primary organization or an existing title.
 * Call inside the same transaction as the associated event/contact save.
 */
function ensureContactOrganization(mysqli $conn, int $contact_id, int $organization_id, string $role_title = ''): bool
{
    $role_title = trim($role_title);
    if ($contact_id < 1 || $organization_id < 1 || mb_strlen($role_title) > 255) {
        throw new InvalidArgumentException('Select a valid contact, organization, and title.');
    }
    $stmt = $conn->prepare(
        'SELECT c.id FROM contacts c CROSS JOIN organizations o
         WHERE c.id = ? AND c.is_deleted = 0 AND o.id = ? AND o.is_deleted = 0
         FOR UPDATE'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the contact organization.');
    }
    $stmt->bind_param('ii', $contact_id, $organization_id);
    $stmt->execute();
    $valid = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    if (!$valid) {
        throw new InvalidArgumentException('Select an active contact and organization.');
    }
    if (contactBelongsToOrganization($conn, $contact_id, $organization_id)) {
        return false;
    }
    $stmt = $conn->prepare(
        'INSERT INTO contact_organizations (contact_id, organization_id, role_title) VALUES (?, ?, ?)'
    );
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare the contact organization.');
    }
    $stmt->bind_param('iis', $contact_id, $organization_id, $role_title);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to add the contact organization.');
    }
    $stmt->close();
    // An event can add an affiliation while a separate contact edit is open.
    // Invalidate that stale form so it cannot silently remove the new link.
    $touch_stmt = $conn->prepare('UPDATE contacts SET updated_at = CURRENT_TIMESTAMP(6) WHERE id = ?');
    if (!$touch_stmt) {
        throw new RuntimeException('Unable to update the contact timestamp.');
    }
    $touch_stmt->bind_param('i', $contact_id);
    if (!$touch_stmt->execute()) {
        $touch_stmt->close();
        throw new RuntimeException('Unable to update the contact timestamp.');
    }
    $touch_stmt->close();
    return true;
}

function ensureContactOrganizationAffiliation(mysqli $conn, int $contact_id, int $organization_id, string $role_title = ''): bool
{
    return ensureContactOrganization($conn, $contact_id, $organization_id, $role_title);
}

/**
 * Replace affiliations after saving the legacy primary fields, within the caller's
 * transaction. Existing archived organizations may be retained, but cannot be added.
 * Deleting a link prunes only event assignments for that particular organization.
 *
 * @param list<array{organization_id: int, role_title: string}> $additional_affiliations
 */
function syncContactOrganizations(
    mysqli $conn,
    int $contact_id,
    ?int $primary_organization_id,
    string $primary_role_title,
    array $additional_affiliations
): bool {
    $desired = normalizeContactOrganizationAffiliations($additional_affiliations, $primary_organization_id);
    if ($primary_organization_id !== null) {
        if ($primary_organization_id < 1 || mb_strlen($primary_role_title) > 255) {
            throw new InvalidArgumentException('Select a valid primary organization and title.');
        }
        $desired[] = ['organization_id' => $primary_organization_id, 'role_title' => trim($primary_role_title)];
    }
    $lock_stmt = $conn->prepare('SELECT organization_id FROM contacts WHERE id = ? FOR UPDATE');
    if (!$lock_stmt) {
        throw new RuntimeException('Unable to prepare the contact organization changes.');
    }
    $lock_stmt->bind_param('i', $contact_id);
    $lock_stmt->execute();
    $contact = $lock_stmt->get_result()->fetch_assoc();
    $lock_stmt->close();
    if (!$contact || ($contact['organization_id'] === null ? null : (int) $contact['organization_id']) !== $primary_organization_id) {
        throw new InvalidArgumentException('Save the contact primary organization before its additional organizations.');
    }
    $current = [];
    foreach (fetchContactOrganizations($conn, $contact_id) as $affiliation) {
        $current[(int) $affiliation['organization_id']] = (string) $affiliation['role_title'];
    }
    $desired_map = [];
    foreach ($desired as $affiliation) {
        $organization_id = $affiliation['organization_id'];
        $org_stmt = $conn->prepare('SELECT is_deleted FROM organizations WHERE id = ? FOR UPDATE');
        if (!$org_stmt) {
            throw new RuntimeException('Unable to prepare the selected organization.');
        }
        $org_stmt->bind_param('i', $organization_id);
        $org_stmt->execute();
        $organization = $org_stmt->get_result()->fetch_assoc();
        $org_stmt->close();
        if (!$organization || ((int) $organization['is_deleted'] === 1 && !array_key_exists($organization_id, $current))) {
            throw new InvalidArgumentException('Select only active organizations for new affiliations.');
        }
        $desired_map[$organization_id] = $affiliation['role_title'];
    }
    $changed = false;
    foreach ($desired_map as $organization_id => $role_title) {
        if (array_key_exists($organization_id, $current) && $current[$organization_id] === $role_title) {
            continue;
        }
        $save_stmt = $conn->prepare(
            'INSERT INTO contact_organizations (contact_id, organization_id, role_title) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE role_title = ?'
        );
        if (!$save_stmt) {
            throw new RuntimeException('Unable to prepare the contact organizations.');
        }
        $save_stmt->bind_param('iiss', $contact_id, $organization_id, $role_title, $role_title);
        if (!$save_stmt->execute()) {
            $save_stmt->close();
            throw new RuntimeException('Unable to save contact organizations.');
        }
        $save_stmt->close();
        $changed = true;
    }
    foreach (array_diff_key($current, $desired_map) as $organization_id => $_title) {
        $delete_stmt = $conn->prepare('DELETE FROM contact_organizations WHERE contact_id = ? AND organization_id = ?');
        if (!$delete_stmt) {
            throw new RuntimeException('Unable to prepare the contact organization removal.');
        }
        $delete_stmt->bind_param('ii', $contact_id, $organization_id);
        if (!$delete_stmt->execute()) {
            $delete_stmt->close();
            throw new RuntimeException('Unable to remove the contact organization.');
        }
        $delete_stmt->close();
        $changed = true;
    }
    return $changed;
}
