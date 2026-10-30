<?php

declare(strict_types=1);

use Dnr\Service\ArchiveService;

function requireActiveOrganization(mysqli $conn, $organization_id, $lock = false) {
    $organization_id = (int) $organization_id;
    if ($organization_id < 1) {
        throw new InvalidArgumentException('Please select an active organization.');
    }
    $sql = 'SELECT id FROM organizations WHERE id = ? AND is_deleted = 0';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to verify the selected organization.');
    }
    $stmt->bind_param('i', $organization_id);
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Unable to verify the selected organization.');
    }
    $exists = $stmt->get_result()->num_rows === 1;
    $stmt->close();
    if (!$exists) {
        throw new InvalidArgumentException('Please select an active organization.');
    }
    return $organization_id;
}

// Keep archive operations consistent across each of the record lists. The
// existing is_deleted columns are the reversible archive flag; permanent
// deletion is handled separately below.
function archiveEntity(mysqli $conn, $entity, $id) {
    return setEntityArchived($conn, $entity, $id, true);
}

function restoreEntity(mysqli $conn, $entity, $id) {
    return setEntityArchived($conn, $entity, $id, false);
}

function organizationActiveDependencyCounts(mysqli $conn, $organization_id) {
    try {
        return ArchiveService::organizationActiveDependencyCounts(
            $conn,
            (int) $organization_id
        );
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to count active organization dependencies', [
            'organization_id' => (int) $organization_id,
            'error' => $exception->getMessage(),
        ]);
        return null;
    }
}

function organizationArchiveDependencyMessage(array $dependencies) {
    $parts = [];
    $contacts = max(0, (int) ($dependencies['contacts'] ?? 0));
    $engagements = max(0, (int) ($dependencies['engagements'] ?? 0));
    $inquiries = max(0, (int) ($dependencies['inquiries'] ?? 0));

    if ($contacts > 0) {
        $parts[] = $contacts . ' active contact' . ($contacts === 1 ? '' : 's');
    }
    if ($engagements > 0) {
        $parts[] = $engagements . ' active engagement' . ($engagements === 1 ? '' : 's');
    }
    if ($inquiries > 0) {
        $parts[] = $inquiries . ' active ' . ($inquiries === 1 ? 'inquiry' : 'inquiries');
    }

    if (!$parts) {
        return '';
    }

    $last_part = array_pop($parts);
    $dependency_summary = $parts === []
        ? $last_part
        : implode(', ', $parts) . (count($parts) === 1 ? ' and ' : ', and ') . $last_part;

    return 'This organization cannot be archived while it has ' . $dependency_summary
        . '. Archive those related records first, or move them to another organization.';
}

function contactActiveInquiryCount(mysqli $conn, $contact_id) {
    try {
        return ArchiveService::contactActiveInquiryCount($conn, (int) $contact_id);
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to count active contact inquiries', [
            'contact_id' => (int) $contact_id,
            'error' => $exception->getMessage(),
        ]);
        return null;
    }
}

function setEntityArchived(mysqli $conn, $entity, $id, $is_archived) {
    if (!canArchiveEntries($_SESSION['role'] ?? null)) {
        return false;
    }

    $id = (int) $id;
    $archive_value = $is_archived ? 1 : 0;
    try {
        return ArchiveService::setArchived($conn, (string) $entity, $id, (bool) $is_archived);
    } catch (Throwable $exception) {
        applicationLog('error', 'Unable to change archive state', [
            'entity' => $entity,
            'entity_id' => $id,
            'is_archived' => $archive_value,
            'error' => $exception->getMessage(),
        ]);
        return false;
    }
}

// Permanently remove an entity. Organization deletion explicitly removes its
// dependent records so the operation behaves consistently on existing
// databases whose foreign keys do not use ON DELETE CASCADE.
function permanentlyDeleteEntity(mysqli $conn, $entity, $id) {
    if (!canDeleteEntries($_SESSION['role'] ?? null)) {
        return false;
    }

    $id = (int) $id;
    if ($id < 1) {
        return false;
    }

    if ($entity === 'organization') {
        return permanentlyDeleteOrganization($conn, $id);
    }

    if ($entity === 'engagement' || $entity === 'event') {
        return permanentlyDeleteEngagement($conn, $id);
    }

    if ($entity !== 'contact') {
        return false;
    }

    $stmt = $conn->prepare('DELETE FROM contacts WHERE id = ?');
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('i', $id);
    $success = $stmt->execute() && $stmt->affected_rows === 1;
    $stmt->close();

    return $success;
}

function permanentlyDeleteEngagement(mysqli $conn, $engagement_id) {
    require_once dirname(__DIR__) . '/financial_report_helpers.php';
    requireDeletableFinancialParent($conn, 'engagement', (int) $engagement_id);
    if (!$conn->begin_transaction()) {
        return false;
    }

    $chron_stmt = $conn->prepare(
        'DELETE FROM engagement_chron_entries WHERE engagement_id = ?'
    );
    $presentation_stmt = $conn->prepare(
        'DELETE FROM presentations WHERE engagement_id = ?'
    );
    $engagement_stmt = $conn->prepare('DELETE FROM engagements WHERE id = ?');
    if (!$chron_stmt || !$presentation_stmt || !$engagement_stmt) {
        if ($chron_stmt) {
            $chron_stmt->close();
        }
        if ($presentation_stmt) {
            $presentation_stmt->close();
        }
        if ($engagement_stmt) {
            $engagement_stmt->close();
        }
        $conn->rollback();
        return false;
    }

    $chron_stmt->bind_param('i', $engagement_id);
    $chron_entries_deleted = $chron_stmt->execute();
    $chron_stmt->close();

    $presentation_stmt->bind_param('i', $engagement_id);
    $presentations_deleted = $presentation_stmt->execute();
    $presentation_stmt->close();

    $engagement_stmt->bind_param('i', $engagement_id);
    $engagement_deleted = $engagement_stmt->execute()
        && $engagement_stmt->affected_rows === 1;
    $engagement_stmt->close();

    if (!$chron_entries_deleted || !$presentations_deleted || !$engagement_deleted || !$conn->commit()) {
        $conn->rollback();
        return false;
    }

    return true;
}

function permanentlyDeleteOrganization(mysqli $conn, $organization_id) {
    require_once dirname(__DIR__) . '/financial_report_helpers.php';
    requireDeletableFinancialParent($conn, 'organization', (int) $organization_id);
    if (!$conn->begin_transaction()) {
        return false;
    }

    // Keep shared people, choosing a remaining affiliation as their primary.
    $shared_stmt = $conn->prepare(
        'SELECT c.id, co.organization_id, co.role_title
         FROM contacts c
         INNER JOIN contact_organizations co ON co.contact_id = c.id
         INNER JOIN organizations o ON o.id = co.organization_id
         WHERE c.organization_id = ? AND co.organization_id <> ?
         ORDER BY c.id, o.is_deleted, co.organization_id FOR UPDATE'
    );
    $shared_stmt->bind_param('ii', $organization_id, $organization_id);
    $shared_stmt->execute();
    $shared_contacts = $shared_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $shared_stmt->close();
    $promoted = [];
    foreach ($shared_contacts as $contact) {
        $contact_id = (int) $contact['id'];
        if (isset($promoted[$contact_id])) continue;
        $replacement_id = (int) $contact['organization_id'];
        $replacement_title = trim((string) $contact['role_title']) ?: 'Contact';
        $promote = $conn->prepare(
            'UPDATE contacts SET organization_id = ?, contact_role = \'other\',
             contact_role_other = ? WHERE id = ?'
        );
        $promote->bind_param('isi', $replacement_id, $replacement_title, $contact_id);
        $promote->execute();
        $promote->close();
        $promoted[$contact_id] = true;
    }

    $queries = [
        'DELETE ce FROM engagement_chron_entries ce
         INNER JOIN engagements e ON e.id = ce.engagement_id
         WHERE e.organization_id = ?',
        'DELETE p FROM presentations p
         INNER JOIN engagements e ON e.id = p.engagement_id
         WHERE e.organization_id = ?',
        'DELETE FROM engagements WHERE organization_id = ?',
        'DELETE FROM contacts WHERE organization_id = ?',
        'DELETE FROM organizations WHERE id = ?',
    ];

    foreach ($queries as $index => $query) {
        $stmt = $conn->prepare($query);
        if (!$stmt) {
            $conn->rollback();
            return false;
        }

        $stmt->bind_param('i', $organization_id);
        $success = $stmt->execute();
        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        $is_organization_delete = $index === count($queries) - 1;
        if (!$success || ($is_organization_delete && $affected_rows !== 1)) {
            $conn->rollback();
            return false;
        }
    }

    if (!$conn->commit()) {
        $conn->rollback();
        return false;
    }

    return true;
}
