<?php
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/task_bulk_helpers.php';
$conn=applicationDatabaseConnection(); startSecureSession(); requireLogin();
if (!canManageFollowUpTasks($_SESSION['role'] ?? '')) { http_response_code(403); exit('Forbidden.'); }
header('Cache-Control: no-store');
$error=''; $results=[]; $preview=null;
$return=safeFollowUpTaskReturnUrl($_POST['return_to'] ?? 'tasks.php');
try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new InvalidArgumentException('Select tasks from the Tasks page first.');
    requireValidCsrfToken();
    if (($_POST['action'] ?? '') === 'apply') {
        $token=is_string($_POST['token'] ?? null)?$_POST['token']:'';
        $preview=$_SESSION['task_bulk_previews'][$token] ?? null;
        if (!$preview || $preview['expires']<time()) throw new InvalidArgumentException('This preview expired or was already applied. Select the tasks again.');
        unset($_SESSION['task_bulk_previews'][$token]);
        $return=$preview['return'];
        foreach ($preview['tasks'] as $task) {
            try { applyTaskBulkItem($conn,$task,$preview['operation'],(int)$_SESSION['user_id']); $result='Updated'; }
            catch (Throwable $e) { $result=$e instanceof InvalidArgumentException?$e->getMessage():'Could not update; review the task and try again'; applicationLog('warning','Bulk task update skipped',['task_id'=>$task['id'],'error'=>$e->getMessage()]); }
            $results[]=['title'=>$task['title'],'result'=>$result];
        }
    } else {
        $ids=bulkDeleteIds($_POST['selected_ids'] ?? null); $operation=taskBulkOperation($_POST); $tasks=[];
        $versions=is_array($_POST['versions'] ?? null)?$_POST['versions']:[];
        foreach ($ids as $id) {
            $task=fetchFollowUpTask($conn,$id);
            if (!$task || !is_string($versions[$id] ?? null) || !hash_equals($task['updated_at'],$versions[$id])) throw new InvalidArgumentException('A selected task changed. Reload Tasks before reviewing this update.');
            $tasks[]=$task;
        }
        $valueLabel=$operation['value'];
        if ($operation['operation']==='assign') {
            $owner=$operation['value']?$conn->execute_query("SELECT username FROM users WHERE id=? AND account_status='active'",[$operation['value']])->fetch_assoc():null;
            if ($operation['value'] && !$owner) throw new InvalidArgumentException('Choose an active owner.');
            $valueLabel=$owner['username']??'Unassigned';
        }
        $token=bin2hex(random_bytes(24));
        $preview=['tasks'=>$tasks,'operation'=>$operation,'label'=>$valueLabel?:'No due date','return'=>$return,'expires'=>time()+900];
        if (count($_SESSION['task_bulk_previews'] ?? [])>=5) array_shift($_SESSION['task_bulk_previews']);
        $_SESSION['task_bulk_previews'][$token]=$preview;
    }
} catch (InvalidArgumentException $e) { $error=$e->getMessage(); }
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');
?>
<!DOCTYPE html><html lang="en"><?php renderPageHead(applicationPageTitle('Bulk Task Update'),['styles'=>['assets/css/style.min.css','assets/css/modern.min.css']]); ?><body>
<?php include 'templates/header.php'; ?><main class="container"><h1><?php echo $results?'Bulk Update Results':'Review Task Updates'; ?></h1>
<?php if ($error): ?><p class="error" role="alert"><?php echo $h($error); ?></p>
<?php elseif ($results): ?><p><?php echo count(array_filter($results,fn($r)=>$r['result']==='Updated')); ?> of <?php echo count($results); ?> tasks updated</p><ul><?php foreach($results as $result): ?><li><?php echo $h($result['title'].' — '.$result['result']); ?></li><?php endforeach; ?></ul>
<?php elseif ($preview): ?><p><?php echo $h(['assign'=>'Set owner to ','due'=>'Set due date to ','complete'=>'Set status to '][$preview['operation']['operation']].$preview['label']); ?> for <?php echo count($preview['tasks']); ?> selected tasks</p>
<p>Each task is checked again before applying. Changed, completed, or archived tasks are skipped and reported individually.</p>
<table class="data-table"><thead><tr><th>Task</th><th>Current Owner</th><th>Current Due Date</th><th>Current Status</th></tr></thead><tbody><?php foreach($preview['tasks'] as $task): ?><tr><td><?php echo $h($task['title']); ?></td><td><?php echo $h($task['assignee_username']?:'Unassigned'); ?></td><td><?php echo $h($task['due_date']?:'None'); ?></td><td><?php echo $h($task['status']); ?></td></tr><?php endforeach; ?></tbody></table>
<form method="post" action="task_bulk.php"><?php echo csrfInput(); ?><input type="hidden" name="action" value="apply"><input type="hidden" name="token" value="<?php echo $h($token); ?>"><button type="submit" class="button-primary">Apply Updates</button></form>
<?php endif; ?><a href="<?php echo $h($return); ?>">Back to Tasks</a></main><?php include 'templates/footer.php'; ?></body></html>
