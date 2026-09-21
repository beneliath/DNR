<?php

declare(strict_types=1);

/** @return array{table: string, label_sql: string, plural: string, page: string, warning: string} */
function bulkDeleteType(string $entity): array
{
    $types = [
        'organization' => ['organizations', 'organization_name', 'organizations', 'organizations.php',
            'Deleting organizations also deletes their engagements, presentations, tasks, Chron entries, and contacts with no remaining organization affiliation. Contacts shared with an organization you keep are preserved.'],
        'contact' => ['contacts', "CONCAT_WS(' ', contact_first_name, contact_last_name)", 'contacts', 'contacts.php',
            'Deleting contacts also removes their affiliations, assigned engagement roles, tasks, and Chron entries.'],
        'speaker' => ['speakers', 'name', 'speakers', 'speakers.php',
            'Speakers referenced by presentations, short links, notes, or slide decks cannot be deleted. Those speakers will be kept.'],
        'task' => ['follow_up_tasks', 'title', 'tasks', 'tasks.php',
            'Only the selected tasks are deleted. Their related records and standard task definitions are kept.'],
        'engagement' => ['engagements', "COALESCE(NULLIF(event_title, ''), CONCAT('Engagement #', id))", 'engagements', 'engagements.php',
            'Deleting engagements also deletes their presentations, files, short links, statistics, tasks, financial records, and Chron entries.'],
        'user' => ['users', 'username', 'inactive users', 'users.php',
            'Only inactive or invited accounts can be deleted. Active accounts and your own account are protected. Account history is permanently removed according to the existing user-deletion rules.'],
    ];
    if (!isset($types[$entity])) {
        throw new InvalidArgumentException('Select a supported record type.');
    }
    [$table, $label_sql, $plural, $page, $warning] = $types[$entity];
    return compact('table', 'label_sql', 'plural', 'page', 'warning');
}

/** @return list<int> */
function bulkDeleteIds(mixed $input): array
{
    if (!is_array($input) || !array_is_list($input) || count($input) < 1 || count($input) > 100) {
        throw new InvalidArgumentException('Select between 1 and 100 items from this page.');
    }
    $ids = [];
    foreach ($input as $value) {
        if ((!is_string($value) && !is_int($value))
            || !preg_match('/\A[1-9][0-9]*\z/', (string) $value)
            || filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]) === false) {
            throw new InvalidArgumentException('The selection contains an invalid item. Reload the list and try again.');
        }
        $ids[] = (int) $value;
    }
    return array_values(array_unique($ids));
}

/** @param list<int> $ids
 * @return list<array{id: int, label: string, blocked: string}>
 */
function bulkDeleteRecords(mysqli $conn, string $entity, array $ids, int $actor_id): array
{
    $type = bulkDeleteType($entity);
    $ids = bulkDeleteIds($ids);
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $extra = $entity === 'user' ? ', account_status' : '';
    if ($entity === 'speaker') {
        $extra = ', (EXISTS (SELECT 1 FROM presentations WHERE speaker_id = speakers.id)
            OR EXISTS (SELECT 1 FROM short_links WHERE speaker_id = speakers.id)
            OR EXISTS (SELECT 1 FROM presentation_notes WHERE speaker_id = speakers.id)
            OR EXISTS (SELECT 1 FROM presentation_slidedecks WHERE speaker_id = speakers.id)) AS linked';
    }
    $result = $conn->execute_query("SELECT id, {$type['label_sql']} AS label {$extra}
        FROM {$type['table']} WHERE id IN ({$placeholders})", $ids);
    $found = [];
    foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
        $found[(int) $row['id']] = $row;
    }
    $records = [];
    foreach ($ids as $id) {
        $row = $found[$id] ?? null;
        $blocked = '';
        if ($row === null) {
            $blocked = 'This item is no longer available.';
        } elseif ($entity === 'user' && ($id === $actor_id || $row['account_status'] === 'active')) {
            $blocked = 'Active accounts and your own account cannot be deleted.';
        } elseif ($entity === 'speaker' && $row['linked']) {
            $blocked = 'This speaker is still referenced by a presentation, link, or file.';
        }
        $records[] = ['id' => $id, 'label' => (string) ($row['label'] ?? ('Item #' . $id)), 'blocked' => $blocked];
    }
    return $records;
}

/** Each record uses the existing deletion transaction; failures never report success.
 * @param list<int> $ids
 * @return array{deleted: int, failed: int}
 */
function permanentlyDeleteSelectedRecords(mysqli $conn, string $entity, array $ids, int $actor_id): array
{
    if (!canDeleteEntries($_SESSION['role'] ?? null) || !hasRecentAdminElevation()
        || $actor_id !== (int) ($_SESSION['user_id'] ?? 0)) {
        throw new RuntimeException('Administrator unlock is required.');
    }
    $records = bulkDeleteRecords($conn, $entity, $ids, $actor_id);
    $deleted = 0;
    foreach ($records as $record) {
        if ($record['blocked'] !== '') continue;
        try {
            if ($entity === 'user') {
                $success = deleteInactiveUserAccount($conn, $record['id'], $actor_id);
            } elseif ($entity === 'speaker' || $entity === 'task') {
                $table = bulkDeleteType($entity)['table'];
                // Speaker foreign keys recheck references even if they changed after review.
                $conn->execute_query("DELETE FROM {$table} WHERE id = ?", [$record['id']]);
                $success = $conn->affected_rows === 1;
            } else {
                $success = permanentlyDeleteEntity($conn, $entity, $record['id']);
            }
            if ($success) $deleted++;
        } catch (Throwable $exception) {
            // Legacy organization/engagement helpers can throw mid-transaction.
            $conn->rollback();
            applicationLog('error', 'Bulk deletion item failed', [
                'entity' => $entity, 'entity_id' => $record['id'], 'error' => $exception->getMessage(),
            ]);
        }
    }
    return ['deleted' => $deleted, 'failed' => count($records) - $deleted];
}

function renderBulkDeleteToolbar(string $entity, string $return_to): void
{
    if (!canDeleteEntries($_SESSION['role'] ?? null)) return;
    $type = bulkDeleteType($entity);
    ?>
    <form id="bulk-delete-form" class="bulk-delete-toolbar" method="post" action="bulk_delete.php" data-bulk-delete>
        <?php echo csrfInput(); ?>
        <input type="hidden" name="action" value="review">
        <input type="hidden" name="entity" value="<?php echo htmlspecialchars($entity, ENT_QUOTES, 'UTF-8'); ?>">
        <input type="hidden" name="return_to" value="<?php echo htmlspecialchars($return_to, ENT_QUOTES, 'UTF-8'); ?>">
        <label class="bulk-delete-select-all"><input type="checkbox" data-bulk-select-all> Select all on this page</label>
        <span data-bulk-count role="status" aria-live="polite">0 selected</span>
        <button type="button" class="button-secondary" data-bulk-clear hidden>Clear selection</button>
        <button type="submit" class="delete-button" data-bulk-submit>Delete selected</button>
        <p class="bulk-delete-help">Select <?php echo htmlspecialchars($type['plural'], ENT_QUOTES, 'UTF-8'); ?> on this page. Review the selection and unlock admin actions before deleting.</p>
    </form>
    <?php
    renderScript('assets/js/bulk-delete.min.js');
}

function renderBulkDeleteCheckbox(int $id, string $label): void
{
    if (!canDeleteEntries($_SESSION['role'] ?? null)) return;
    ?>
    <input type="checkbox" class="bulk-delete-checkbox" form="bulk-delete-form" name="selected_ids[]"
           value="<?php echo $id; ?>" data-bulk-item aria-label="<?php echo htmlspecialchars('Select ' . $label, ENT_QUOTES, 'UTF-8'); ?>">
    <?php
}
