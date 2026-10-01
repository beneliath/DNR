<?php

declare(strict_types=1);
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/record_merge_journal_helpers.php';

function mergeRecordTable(string $kind): string
{
    return match ($kind) { 'contact' => 'contacts', 'organization' => 'organizations',
        default => throw new InvalidArgumentException('Choose a contact or organization.') };
}

function mergeRecordLabel(string $kind, array $row): string
{
    return $kind === 'contact' ? trim($row['contact_first_name'] . ' ' . $row['contact_last_name']) : $row['organization_name'];
}

/** Match candidates, never automatically equate shared email addresses with identities. */
function recordDuplicateCandidates(mysqli $conn, string $kind, array $source): array
{
    if ($kind === 'contact') {
        return $conn->execute_query("SELECT id, CONCAT(contact_first_name,' ',contact_last_name) AS label,
            contact_email AS email, contact_phone AS phone FROM contacts WHERE id<>? AND is_deleted=0 AND merged_into_id IS NULL
            AND ((contact_email<>'' AND LOWER(contact_email)=LOWER(?))
              OR (contact_phone<>'' AND contact_phone=?)
              OR (contact_first_name=? AND contact_last_name=?)) ORDER BY id LIMIT 25",
            [$source['id'], $source['contact_email'], $source['contact_phone'], $source['contact_first_name'], $source['contact_last_name']])->fetch_all(MYSQLI_ASSOC);
    }
    return $conn->execute_query("SELECT id, organization_name AS label, email, phone, CONCAT_WS(', ', NULLIF(physical_city,''), NULLIF(physical_state,'')) AS location FROM organizations
        WHERE id<>? AND is_deleted=0 AND merged_into_id IS NULL
          AND ((email<>'' AND LOWER(email)=LOWER(?)) OR (phone<>'' AND phone=?)
            OR organization_name LIKE ?
            OR (physical_address_line_1<>'' AND physical_address_line_1=? AND physical_city=? AND physical_state=? AND physical_country=?)) ORDER BY organization_name LIMIT 25",
        [$source['id'], $source['email'], $source['phone'], addcslashes(mb_substr($source['organization_name'], 0, 20), '%_\\') . '%',
         $source['physical_address_line_1']??'', $source['physical_city']??'', $source['physical_state']??'', $source['physical_country']??''])->fetch_all(MYSQLI_ASSOC);
}

function mergeRecordFields(string $kind): array
{
    return $kind === 'contact'
        ? ['contact_first_name', 'contact_last_name', 'contact_email', 'contact_phone', 'contact_birthday', 'contact_notes']
        : ['organization_name', 'notes', 'affiliation', 'distinctives', 'website_url', 'phone', 'fax', 'email',
            'mailing_address_line_1', 'mailing_address_line_2', 'mailing_city', 'mailing_state', 'mailing_zipcode', 'mailing_country',
            'physical_address_line_1', 'physical_address_line_2', 'physical_city', 'physical_state', 'physical_zipcode', 'physical_country'];
}

/** Only explicit field choices replace a nonempty surviving value. */
function mergeRecordValues(string $kind, array $source, array $target, array $choices): array
{
    if (array_diff(array_keys($choices), mergeRecordFields($kind)) !== []) throw new InvalidArgumentException('Unknown merge field.');
    $values = [];
    foreach (mergeRecordFields($kind) as $field) {
        $choice = $choices[$field] ?? ((string) ($target[$field] ?? '') === '' ? 'source' : 'target');
        if (!in_array($choice, ['source', 'target'], true)) throw new InvalidArgumentException('Choose a value for each merge field.');
        $values[$field] = ($choice === 'source' ? $source : $target)[$field] ?? null;
    }
    return $values;
}

/** Counts make the relationship movement reviewable before confirmation. */
function mergeRecordRelationshipCounts(mysqli $conn, string $kind, int $source): array
{
    $relations = $kind === 'contact'
        ? ['Affiliations' => ['contact_organizations', 'contact_id'], 'Engagement roles' => ['engagement_contacts', 'contact_id'],
            'Chron entries' => ['contact_chron_entries', 'contact_id'], 'Tasks' => ['follow_up_tasks', 'contact_id'],
            'Inquiries' => ['booking_inquiries', 'primary_contact_id'], 'Email references' => ['engagement_email_deliveries', 'contact_id']]
        : ['Affiliations' => ['contact_organizations', 'organization_id'], 'Engagements' => ['engagements', 'organization_id'],
            'Primary contacts' => ['contacts', 'organization_id'], 'Chron entries' => ['organization_chron_entries', 'organization_id'],
            'Tasks' => ['follow_up_tasks', 'organization_id'], 'Inquiries' => ['booking_inquiries', 'organization_id'],
            'Email references' => ['engagement_email_messages', 'organization_id']];
    $counts = [];
    foreach ($relations as $label => [$table, $column]) {
        $counts[$label] = (int) $conn->execute_query("SELECT COUNT(*) FROM {$table} WHERE {$column}=?", [$source])->fetch_row()[0];
    }
    return $counts;
}

/** Live relationships move; original record values and existing audit snapshots remain. */
function mergeRecords(mysqli $conn, string $kind, int $sourceId, int $targetId, string $sourceVersion, string $targetVersion, array $choices, int $actor): void
{
    $table = mergeRecordTable($kind);
    if ($sourceId < 1 || $targetId < 1 || $sourceId === $targetId) throw new InvalidArgumentException('Choose two different records.');
    $conn->begin_transaction();
    try {
        $rows = $conn->execute_query("SELECT * FROM {$table} WHERE id IN (?,?) ORDER BY id FOR UPDATE", [$sourceId, $targetId])->fetch_all(MYSQLI_ASSOC);
        $records = array_column($rows, null, 'id');
        $source = $records[$sourceId] ?? null; $target = $records[$targetId] ?? null;
        if (!$source || !$target || $source['merged_into_id'] !== null || $target['merged_into_id'] !== null || $target['is_deleted']) {
            throw new InvalidArgumentException('Choose an unmerged source and an active surviving record.');
        }
        if (!hash_equals((string) $source['updated_at'], $sourceVersion) || !hash_equals((string) $target['updated_at'], $targetVersion)) {
            throw new InvalidArgumentException('A record changed after the preview. Reload and review the merge again.');
        }
        $before = mergeJournalSnapshot($conn, $kind, $sourceId, $targetId);
        $values = mergeRecordValues($kind, $source, $target, $choices);
        $sets = implode(', ', array_map(static fn($field) => "$field=?", array_keys($values)));
        $conn->execute_query("UPDATE {$table} SET {$sets}, updated_at=UTC_TIMESTAMP(6) WHERE id=?", [...array_values($values), $targetId]);
        $key = $kind === 'contact' ? 'contact_id' : 'organization_id';
        $other = $kind === 'contact' ? 'organization_id' : 'contact_id';
        $affiliations = $conn->execute_query("SELECT * FROM contact_organizations WHERE {$key} IN (?,?) ORDER BY contact_id, organization_id FOR UPDATE", [$sourceId, $targetId])->fetch_all(MYSQLI_ASSOC);
        $targetRoles = [];
        foreach ($affiliations as $row) if ((int) $row[$key] === $targetId) $targetRoles[$row[$other]] = $row['role_title'];
        foreach ($affiliations as $row) {
            if ((int) $row[$key] !== $sourceId) continue;
            $title = $row['role_title'];
            $existing = $targetRoles[$row[$other]] ?? '';
            if ($existing !== '' && $title !== '' && $existing !== $title) {
                throw new InvalidArgumentException('The same affiliation has different role titles. Resolve that conflict on the records before merging.');
            }
            $contact = $kind === 'contact' ? $targetId : (int) $row['contact_id'];
            $organization = $kind === 'organization' ? $targetId : (int) $row['organization_id'];
            $conn->execute_query("INSERT INTO contact_organizations (contact_id, organization_id, role_title) VALUES (?,?,?)
                ON DUPLICATE KEY UPDATE role_title=IF(role_title='',?,role_title)", [$contact, $organization, $title, $title]);
        }
        if ($kind === 'contact') {
            $conn->execute_query('SET @dnr_merge_source_contact_id=?',[$sourceId]);
            $conn->execute_query('INSERT INTO engagement_contacts (engagement_id,contact_id,contact_role,created_by,created_at,organization_id_snapshot,contact_first_name_snapshot,contact_last_name_snapshot,contact_email_snapshot,contact_phone_snapshot,role_title_snapshot)
                SELECT source.engagement_id,?,source.contact_role,source.created_by,source.created_at,source.organization_id_snapshot,source.contact_first_name_snapshot,source.contact_last_name_snapshot,source.contact_email_snapshot,source.contact_phone_snapshot,source.role_title_snapshot FROM engagement_contacts AS source WHERE source.contact_id=?
                ON DUPLICATE KEY UPDATE engagement_id=engagement_contacts.engagement_id', [$targetId, $sourceId]);
            $conn->execute_query('DELETE FROM engagement_contacts WHERE contact_id=?', [$sourceId]);
            $relations = ['contact_chron_entries' => 'contact_id', 'follow_up_tasks' => 'contact_id',
                'booking_inquiries' => 'primary_contact_id', 'engagement_email_deliveries' => 'contact_id'];
        } else {
            $relations = ['engagements' => 'organization_id', 'contacts' => 'organization_id',
                'organization_chron_entries' => 'organization_id', 'follow_up_tasks' => 'organization_id',
                'booking_inquiries' => 'organization_id', 'engagement_email_messages' => 'organization_id'];
        }
        foreach ($relations as $relation => $column) $conn->execute_query("UPDATE {$relation} SET {$column}=? WHERE {$column}=?", [$targetId, $sourceId]);
        $conn->execute_query("DELETE FROM contact_organizations WHERE {$key}=?", [$sourceId]);
        $conn->execute_query("UPDATE {$table} SET merged_into_id=? WHERE merged_into_id=?", [$targetId, $sourceId]);
        $conn->execute_query("UPDATE {$table} SET is_deleted=1, merged_into_id=?, updated_at=UTC_TIMESTAMP(6) WHERE id=?", [$targetId, $sourceId]);
        if (!recordAuditEvent($conn, ['event_category' => 'database_change', 'event_type' => 'record_merged',
            'actor_user_id' => $actor, 'entity_type' => $table, 'entity_id' => $targetId,
            'entity_label' => mb_strcut(mergeRecordLabel($kind, $target), 0, 255),
            'details' => 'Merged ' . $kind . ' #' . $sourceId . ' into #' . $targetId . '. Original source field values retained on the archived source record.'])) {
            throw new RuntimeException('Unable to audit the merge.');
        }
        journalRecordMerge($conn,$kind,$sourceId,$targetId,$actor,$before);
        $conn->commit();
    } catch (Throwable $exception) { $conn->rollback(); throw $exception; }
    finally { $conn->query('SET @dnr_merge_source_contact_id=NULL'); }
}
