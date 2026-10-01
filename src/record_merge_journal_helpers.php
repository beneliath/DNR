<?php

declare(strict_types=1);

use Dnr\Security\ApplicationKey;

/** Explicit table/column allowlist also governs reversal; no names come from the journal. */
function mergeJournalRelations(string $kind): array
{
    return $kind === 'contact'
        ? ['contact_organizations'=>'contact_id', 'engagement_contacts'=>'contact_id',
            'contact_chron_entries'=>'contact_id', 'follow_up_tasks'=>'contact_id',
            'booking_inquiries'=>'primary_contact_id', 'engagement_email_deliveries'=>'contact_id']
        : ['contacts'=>'organization_id', 'engagements'=>'organization_id',
            'contact_organizations'=>'organization_id', 'organization_chron_entries'=>'organization_id',
            'follow_up_tasks'=>'organization_id', 'booking_inquiries'=>'organization_id',
            'engagement_email_messages'=>'organization_id'];
}

function mergeJournalSnapshot(mysqli $conn, string $kind, int $source, int $target): array
{
    $table=mergeRecordTable($kind);
    $snapshot=[$table=>$conn->execute_query("SELECT * FROM {$table} WHERE id IN (?,?) OR merged_into_id IN (?,?) ORDER BY id FOR UPDATE",[$source,$target,$source,$target])->fetch_all(MYSQLI_ASSOC)];
    foreach (mergeJournalRelations($kind) as $relation=>$column) {
        $order=match($relation) {'contact_organizations'=>'contact_id,organization_id', 'engagement_contacts'=>'engagement_id,contact_id,contact_role', default=>'id'};
        $snapshot[$relation]=$conn->execute_query("SELECT * FROM {$relation} WHERE {$column} IN (?,?) ORDER BY {$order} LIMIT 10001 FOR UPDATE",[$source,$target])->fetch_all(MYSQLI_ASSOC);
        if(count($snapshot[$relation])>10000) throw new InvalidArgumentException('This merge exceeds the reviewable relationship limit. Contact an administrator.');
    }
    return $snapshot;
}

function journalRecordMerge(mysqli $conn, string $kind, int $source, int $target, int $actor, array $before): void
{
    $payload=serialize(['before'=>$before,'after'=>mergeJournalSnapshot($conn,$kind,$source,$target)]);
    if(strlen($payload)>4*1024*1024) throw new InvalidArgumentException('This merge is too large for reversible storage.');
    $conn->execute_query('INSERT INTO record_merge_journal (entity_type,source_id,target_id,actor_user_id,snapshot_ciphertext,expires_at)
        VALUES (?,?,?,?,?,UTC_TIMESTAMP(6)+INTERVAL 90 DAY)',[$kind,$source,$target,$actor,ApplicationKey::seal($payload)]);
}

/** Updates rows in place: child FKs, files and completed financial reports never cascade. */
function restoreMergeJournalRow(mysqli $conn, string $table, array $row): void
{
    $keys=match($table) {'contact_organizations'=>['contact_id','organization_id'], 'engagement_contacts'=>['engagement_id','contact_id','contact_role'], default=>['id']};
    $values=array_diff_key($row,array_flip([...$keys,'normalized_email']));
    // A restored record has a new edit version; never revive an old concurrency token.
    if(array_key_exists('updated_at',$values)) unset($values['updated_at']);
    $sets=implode(',',array_map(static fn(string $column):string=>"`{$column}`=?",array_keys($values)));
    if(array_key_exists('updated_at',$row)) $sets.=',updated_at=UTC_TIMESTAMP(6)';
    $where=implode(' AND ',array_map(static fn(string $column):string=>"`{$column}`=?",$keys));
    $conn->execute_query("UPDATE {$table} SET {$sets} WHERE {$where}",[...array_values($values),...array_map(static fn(string $key)=>$row[$key],$keys)]);
}

function undoRecordMerge(mysqli $conn, int $journalId, int $actor): void
{
    $conn->begin_transaction();
    try {
        $journal=$conn->execute_query('SELECT *,expires_at>UTC_TIMESTAMP(6) AS available FROM record_merge_journal WHERE id=? FOR UPDATE',[$journalId])->fetch_assoc();
        if(!$journal || !$journal['available'] || $journal['undone_at']!==null || !$journal['snapshot_ciphertext']) throw new InvalidArgumentException('This merge is no longer available for undo.');
        $kind=$journal['entity_type']; $source=(int)$journal['source_id']; $target=(int)$journal['target_id'];
        $saved=unserialize(ApplicationKey::open($journal['snapshot_ciphertext']),['allowed_classes'=>false]);
        if(!is_array($saved) || !is_array($saved['before']??null) || !is_array($saved['after']??null)) throw new RuntimeException('Invalid merge journal.');
        $current=mergeJournalSnapshot($conn,$kind,$source,$target);
        if($current!==$saved['after']) throw new InvalidArgumentException('Records or relationships changed after this merge. Undo would overwrite later work; review the records manually.');
        $table=mergeRecordTable($kind);
        foreach($saved['before'][$table] as $row) restoreMergeJournalRow($conn,$table,$row);
        // Restore ordinary references before rebuilding the affiliation and role sets.
        foreach(mergeJournalRelations($kind) as $relation=>$column) {
            if(in_array($relation,['contact_organizations','engagement_contacts'],true)) continue;
            foreach($saved['before'][$relation] as $row) restoreMergeJournalRow($conn,$relation,$row);
        }
        foreach(['contact_organizations','engagement_contacts'] as $relation) {
            if(!isset($saved['before'][$relation])) continue;
            $column=mergeJournalRelations($kind)[$relation];
            $conn->execute_query("DELETE FROM {$relation} WHERE {$column} IN (?,?)",[$source,$target]);
            foreach($saved['before'][$relation] as $row) {
                $columns=array_keys($row);
                $names=implode(',',array_map(static fn(string $column):string=>"`{$column}`",$columns));
                $placeholders=implode(',',array_fill(0,count($columns),'?'));
                $conn->execute_query("INSERT INTO {$relation} ({$names}) VALUES ({$placeholders})",array_values($row));
                if($relation==='engagement_contacts') restoreMergeJournalRow($conn,$relation,$row);
            }
        }
        $conn->execute_query('UPDATE record_merge_journal SET undone_at=UTC_TIMESTAMP(6),undone_by=?,snapshot_ciphertext=NULL WHERE id=?',[$actor,$journalId]);
        if(!recordAuditEvent($conn,['event_category'=>'database_change','event_type'=>'record_merge_undone','actor_user_id'=>$actor,'entity_type'=>$table,'entity_id'=>$target,'details'=>'Undid merge journal #'.$journalId.'; restored source #'.$source.'.'])) throw new RuntimeException('Unable to audit merge undo.');
        $conn->commit();
    } catch(Throwable $exception) { $conn->rollback(); throw $exception; }
}
