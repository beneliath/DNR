<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/record_merge_helpers.php';
require_once __DIR__ . '/inquiry_relationship_helpers.php';
require_once __DIR__ . '/two_factor_helpers.php';
startSecureSession();
requireAdmin();
header('Cache-Control: private, no-store');
if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) { header('Allow: GET, POST'); http_response_code(405); exit; }
$kind = \Dnr\Http\RequestInput::enum($_GET, 'kind', ['contact', 'organization'], 'contact');
$table = mergeRecordTable($kind);
$sourceId = \Dnr\Http\RequestInput::positiveInt($_GET, 'source') ?? 0;
$targetId = \Dnr\Http\RequestInput::positiveInt($_GET, 'target') ?? 0;
$source = $conn->execute_query("SELECT * FROM {$table} WHERE id=?", [$sourceId])->fetch_assoc();
if (!$source) { http_response_code(404); exit('Record not found.'); }
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireValidCsrfToken();
    requireRecentAdminElevation('record_merge.php?' . http_build_query(['kind' => $kind, 'source' => $sourceId, 'target' => $targetId]));
    try {
        if (($_POST['confirm'] ?? '') !== 'merge') throw new InvalidArgumentException('Confirm that these records represent the same entity.');
        mergeRecords($conn, $kind, $sourceId, $targetId,
            \Dnr\Http\RequestInput::string($_POST, 'source_version'), \Dnr\Http\RequestInput::string($_POST, 'target_version'),
            is_array($_POST['fields'] ?? null) ? $_POST['fields'] : [], (int) $_SESSION['user_id']);
        $_SESSION['success_message'] = 'Records merged. Original values remain on the archived source record.';
        header('Location: view_' . $kind . '.php?id=' . $targetId);
        exit;
    } catch (Throwable $exception) {
        $error = $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'Unable to merge the records. Nothing was merged. Reload and try again.';
        if (!$exception instanceof InvalidArgumentException) applicationLog('error', 'Record merge failed', ['error' => $exception->getMessage()]);
    }
}
$target = $targetId > 0 ? $conn->execute_query("SELECT * FROM {$table} WHERE id=?", [$targetId])->fetch_assoc() : null;
$relationship_counts = mergeRecordRelationshipCounts($conn, $kind, $sourceId);
$candidates = recordDuplicateCandidates($conn, $kind, $source);
$search = searchInquiryRelationships($conn, $kind, \Dnr\Http\RequestInput::string($_GET, 'q'), null, $targetId ?: null);
$h = static fn($value) => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
generateCsrfToken();
releaseApplicationSessionLock();
?>
<!DOCTYPE html><html lang="en">
<?php renderPageHead(applicationPageTitle('Merge Records'), ['styles' => ['assets/css/style.min.css', 'assets/css/modern.min.css']]); ?>
<body><?php include 'templates/header.php'; ?><main class="container">
<h1>Review a Possible Duplicate</h1>
<p>Source: <a href="view_<?php echo $h($kind); ?>.php?id=<?php echo $sourceId; ?>"><?php echo $h(mergeRecordLabel($kind, $source)); ?></a>. A shared email or similar name does not necessarily mean two records are the same.</p>
<?php if ($error !== ''): ?><p class="error" role="alert"><?php echo $h($error); ?></p><?php endif; ?>
<?php if ($source['merged_into_id'] !== null): ?>
<p>This source has already been merged into <a href="view_<?php echo $h($kind); ?>.php?id=<?php echo (int) $source['merged_into_id']; ?>">the surviving record</a>.</p>
<?php else: ?>
<?php if ($candidates): ?><p>Possible matches:</p><ul><?php foreach ($candidates as $candidate): ?><li><a href="record_merge.php?<?php echo $h(http_build_query(['kind' => $kind, 'source' => $sourceId, 'target' => $candidate['id']])); ?>"><?php echo $h($candidate['label']); ?></a> <?php echo $h($candidate['email']); ?></li><?php endforeach; ?></ul><?php endif; ?>
<form method="get" action="record_merge.php" class="record-merge-picker">
<input type="hidden" name="kind" value="<?php echo $h($kind); ?>"><input type="hidden" name="source" value="<?php echo $sourceId; ?>">
<div class="record-merge-picker-field">
<label for="merge-search">Find the Record to Keep</label>
<div class="record-merge-picker-controls"><input id="merge-search" type="search" name="q" value="<?php echo $h($_GET['q'] ?? ''); ?>"><button type="submit">Search</button></div>
</div>
<div class="record-merge-picker-field">
<label for="merge-target">Surviving Record</label>
<div class="record-merge-picker-controls"><select id="merge-target" name="target"><option value="">Choose a Record</option><?php foreach (inquiryRelationshipFormOptions($search) as $option): if ((int) $option['id'] === $sourceId) continue; ?><option value="<?php echo (int) $option['id']; ?>" <?php echo (int) $option['id'] === $targetId ? 'selected' : ''; ?>><?php echo $h($option['label']); ?></option><?php endforeach; ?></select><button type="submit">Preview Merge</button></div>
</div>
<?php if ($search['has_more']): ?><p>More matches are available. Refine the search.</p><?php endif; ?>
</form>
<?php if ($target && $targetId !== $sourceId && !$target['is_deleted'] && $target['merged_into_id'] === null): ?>
<h2>Keep <?php echo $h(mergeRecordLabel($kind, $target)); ?></h2>
<p>Choose the surviving field values below. Relationships, chron log entries, tasks, inquiries, and email references will move to this record. Identical relationship assignments are combined; conflicting affiliation roles must be resolved first. No messages are sent. The source is archived, its original field values remain available, and it cannot be restored as a separate active record.</p>
<?php if ($kind === 'contact'): ?><p>The surviving contact keeps its primary organization, role and photo. The source's affiliations are added; its original photo remains on the archived record.</p><?php endif; ?>
<dl><?php foreach ($relationship_counts as $label => $count): ?><dt><?php echo $h($label); ?></dt><dd><?php echo $count; ?></dd><?php endforeach; ?></dl>
<form method="post">
<?php echo csrfInput(); ?><input type="hidden" name="source_version" value="<?php echo $h($source['updated_at']); ?>"><input type="hidden" name="target_version" value="<?php echo $h($target['updated_at']); ?>">
<?php foreach (mergeRecordFields($kind) as $field): ?>
<fieldset><legend><?php echo $h(ucwords(str_replace('_', ' ', $field))); ?></legend>
<label><input type="radio" name="fields[<?php echo $h($field); ?>]" value="target" <?php echo (string) ($target[$field] ?? '') !== '' ? 'checked' : ''; ?>> Keep: <?php echo $h($target[$field] ?? '(empty)'); ?></label>
<label><input type="radio" name="fields[<?php echo $h($field); ?>]" value="source" <?php echo (string) ($target[$field] ?? '') === '' ? 'checked' : ''; ?>> Use source: <?php echo $h($source[$field] ?? '(empty)'); ?></label>
</fieldset>
<?php endforeach; ?>
<label><input type="checkbox" name="confirm" value="merge" required> I reviewed these records and confirm they represent the same <?php echo $h($kind); ?></label>
<button type="submit" class="button-primary">Merge into Surviving Record</button>
</form>
<?php endif; endif; ?>
</main><?php include 'templates/footer.php'; ?></body></html>
