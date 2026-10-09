<?php

declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/record_merge_helpers.php';
startSecureSession(); requireAdmin();
$kind=\Dnr\Http\RequestInput::enum($_GET,'kind',['contact','organization'],'contact');
$sourceId=(int)($_GET['source']??0); $targetId=(int)($_GET['target']??0);
$table=mergeRecordTable($kind); $error='';
if($_SERVER['REQUEST_METHOD']==='POST') {
    requireValidCsrfToken(); requireRecentAdminElevation('record_merge.php?'.http_build_query(['kind'=>$kind,'source'=>$sourceId,'target'=>$targetId]));
    try {
        if(($_POST['action']??'')==='undo') undoRecordMerge($conn,(int)($_POST['journal_id']??0),(int)$_SESSION['user_id']);
        elseif(($_POST['action']??'')==='merge') {
            $choices=$_POST['choices']??[];
            if(!is_array($choices)) throw new InvalidArgumentException('Invalid merge choices.');
            mergeRecords($conn,$kind,$sourceId,$targetId,(string)($_POST['source_version']??''),(string)($_POST['target_version']??''),$choices,(int)$_SESSION['user_id']);
        } else throw new InvalidArgumentException('Choose a merge action.');
        header('Location: record_merge.php?'.http_build_query(['kind'=>$kind,'source'=>$sourceId,'target'=>$targetId]),true,303); exit;
    } catch(InvalidArgumentException $exception) { $error=$exception->getMessage(); }
    catch(Throwable $exception) { applicationLog('error','Record merge failed',['error'=>$exception->getMessage()]); $error='The change was rolled back. Please try again.'; }
}
$source=$conn->execute_query("SELECT * FROM {$table} WHERE id=?",[$sourceId])->fetch_assoc();
$target=$conn->execute_query("SELECT * FROM {$table} WHERE id=?",[$targetId])->fetch_assoc();
$candidates=$source?recordDuplicateCandidates($conn,$kind,$source):[];
$journals=$conn->execute_query("SELECT id,source_id,target_id,created_at,expires_at,undone_at,snapshot_ciphertext IS NOT NULL AND expires_at>UTC_TIMESTAMP(6) AND undone_at IS NULL AS available FROM record_merge_journal WHERE entity_type=? AND (source_id=? OR target_id=?) ORDER BY id DESC LIMIT 25",[$kind,$sourceId,$targetId])->fetch_all(MYSQLI_ASSOC);
$h=static fn(mixed $value):string=>htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8');
?>
<!doctype html><html lang="en"><?php renderPageHead(applicationPageTitle('Review Record Merge')); ?><body>
<?php include 'templates/header.php'; ?><main class="container">
<h1>Review Record Merge</h1>
<?php if($error!==''): ?><p class="error" role="alert"><?= $h($error) ?></p><?php endif; ?>
<p>Review both records and every relationship before merging. The source is archived and its relationships move to the surviving record. An encrypted undo snapshot is kept for 90 days. Undo is available only while the records and affected relationships remain unchanged.</p>
<?php if($source): ?><h2>Source: <?= $h(mergeRecordLabel($kind,$source)) ?> (#<?= $sourceId ?>)</h2><?php endif; ?>
<form method="get"><input type="hidden" name="kind" value="<?= $h($kind) ?>"><input type="hidden" name="source" value="<?= $sourceId ?>">
<label for="merge-target">Surviving Record ID</label><input id="merge-target" name="target" type="number" min="1" required value="<?= $targetId?:'' ?>"><button type="submit" class="button-primary">Review</button></form>
<?php if($candidates): ?><ul><?php foreach($candidates as $candidate): ?><li><a href="record_merge.php?<?= $h(http_build_query(['kind'=>$kind,'source'=>$sourceId,'target'=>$candidate['id']])) ?>"><?= $h($candidate['label']) ?> (#<?= (int)$candidate['id'] ?>)</a></li><?php endforeach; ?></ul><?php endif; ?>
<?php if($source && $target && $sourceId!==$targetId && !$source['merged_into_id'] && !$target['merged_into_id'] && !$target['is_deleted']): ?>
<h2>Survivor: <?= $h(mergeRecordLabel($kind,$target)) ?> (#<?= $targetId ?>)</h2>
<form method="post"><?= csrfInput() ?><input type="hidden" name="action" value="merge"><input type="hidden" name="source_version" value="<?= $h($source['updated_at']) ?>"><input type="hidden" name="target_version" value="<?= $h($target['updated_at']) ?>">
<table class="data-table"><thead><tr><th>Field</th><th>Source</th><th>Survivor</th><th>Keep</th></tr></thead><tbody>
<?php foreach(mergeRecordFields($kind) as $field): ?><tr><th><?= $h(ucwords(str_replace('_',' ',$field))) ?></th><td><?= $h($source[$field]??'') ?></td><td><?= $h($target[$field]??'') ?></td><td><select name="choices[<?= $h($field) ?>]" aria-label="Keep value for <?= $h($field) ?>"><option value="target">Survivor</option><option value="source">Source</option></select></td></tr><?php endforeach; ?>
</tbody></table><h3>Relationships Moving from Source</h3><ul><?php foreach(mergeRecordRelationshipCounts($conn,$kind,$sourceId) as $label=>$count): ?><li><?= $h($label) ?>: <?= $count ?></li><?php endforeach; ?></ul>
<button type="submit" class="button-primary" data-admin-unlock-required data-confirm="Merge these records and archive the source?">Merge Records</button></form><?php endif; ?>
<h2>Merge History</h2><?php foreach($journals as $journal): ?><div class="record-section"><p>#<?= (int)$journal['id'] ?>: source #<?= (int)$journal['source_id'] ?> → survivor #<?= (int)$journal['target_id'] ?>. <?= $h($journal['created_at']) ?> UTC.</p>
<?php if($journal['available']): ?><form method="post"><?= csrfInput() ?><input type="hidden" name="action" value="undo"><input type="hidden" name="journal_id" value="<?= (int)$journal['id'] ?>"><button class="button-secondary" data-admin-unlock-required data-confirm="Restore the records and their previous relationships? Later changes prevent undo.">Undo Merge</button></form><p>Expires <?= $h($journal['expires_at']) ?> UTC.</p><?php else: ?><p><?= $journal['undone_at']?'Undone':'Undo expired' ?></p><?php endif; ?></div><?php endforeach; ?>
</main><?php include 'templates/footer.php'; ?></body></html>
