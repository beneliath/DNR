<?php
/** Operator-only ownership transfer for the fictional Test Account baseline. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Forbidden.'); }
$options = getopt('', ['apply', 'rollback']);
$root = is_file(__DIR__ . '/../src/bootstrap.php') ? __DIR__ . '/../src' : '/var/www/html';
require_once $root . '/bootstrap.php';
require_once $root . '/reimbursement_submission_helpers.php';
require_once $root . '/data_consistency_helpers.php';
if (!accountsEnabled() || accountIsPrimary() || currentAccountKey() !== 'test-account'
    || currentAccountProfile()['account_key'] !== 'test-account') {
    throw new RuntimeException('Ownership transfer is restricted to the Test Account member.');
}
$editor = $conn->execute_query("SELECT * FROM users WHERE username='TestEditor' AND role='editor' AND account_status='active'
    AND is_superadmin=0 AND platform_identity_id IS NULL")->fetch_assoc();
if (!$editor || !demoPasswordOnlyAuthenticationAllowed($editor)) throw new RuntimeException('The pinned ordinary TestEditor login must remain eligible.');
$uid = (int) $editor['id'];
$counts = [];
foreach (['follow_up_tasks','engagements','booking_inquiries','reimbursement_expenses','reimbursement_requests'] as $table) {
    $counts[$table] = (int) $conn->query('SELECT COUNT(*) FROM `' . $table . '`')->fetch_row()[0];
}
if (!isset($options['apply'])) {
    echo json_encode(['account'=>'test-account','owner'=>'TestEditor','user_id'=>$uid,'rows'=>$counts,
        'changes'=>['task assignees','engagement callers','inquiry owners','expense and request owners','submitted package owner details']], JSON_PRETTY_PRINT), "\n";
    exit;
}
if ((int) $conn->query('SELECT COUNT(*) FROM reimbursement_email_deliveries')->fetch_row()[0] !== 0) {
    throw new RuntimeException('Review existing reimbursement deliveries before changing ownership; this operator never rewrites sent mail.');
}
lockPersistentFiles();
$conn->begin_transaction();
try {
    $usersBefore = hash('sha256', serialize($conn->query('SELECT * FROM users ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC)));
    $profileBefore = serialize($conn->query('SELECT * FROM account_profile WHERE id=1')->fetch_assoc());
    $owner = $conn->execute_query('SELECT id,username,first_name,last_name,email,reimbursement_reviewer_email FROM users WHERE id=?',[$uid])->fetch_assoc();
    $owner['display_name'] = trim($owner['first_name'] . ' ' . $owner['last_name']) ?: $owner['username'];
    $conn->execute_query('UPDATE follow_up_tasks SET assigned_to=?,updated_at=updated_at WHERE NOT(assigned_to<=>?)',[$uid,$uid]);
    $conn->execute_query('UPDATE engagements SET caller_user_id=?,caller_name=?,updated_at=updated_at WHERE NOT(caller_user_id<=>?) OR caller_name<>?',[$uid,$editor['username'],$uid,$editor['username']]);
    $conn->execute_query('UPDATE booking_inquiries SET owner_user_id=?,updated_at=updated_at WHERE NOT(owner_user_id<=>?)',[$uid,$uid]);
    $conn->execute_query('UPDATE reimbursement_expenses SET user_id=?,version=version+1,updated_at=updated_at WHERE user_id<>?',[$uid,$uid]);
    $packages = 0;
    foreach ($conn->query('SELECT * FROM reimbursement_requests ORDER BY id FOR UPDATE')->fetch_all(MYSQLI_ASSOC) as $request) {
        $changed = (int) $request['user_id'] !== $uid || ($request['status']==='submitted' && $request['owner_name_snapshot'] !== $owner['display_name']);
        $conn->execute_query('UPDATE reimbursement_requests SET user_id=?,owner_name_snapshot=? WHERE id=?',
            [$uid,$request['status']==='submitted'?$owner['display_name']:$request['owner_name_snapshot'],(int)$request['id']]);
        if ($request['status'] !== 'submitted' || !$changed) continue;
        $submission = $conn->execute_query('SELECT * FROM reimbursement_submissions WHERE id=? FOR UPDATE',[(int)$request['id']])->fetch_assoc();
        if (!$submission) throw new RuntimeException('Submitted demo request has no saved package.');
        $request['user_id'] = $uid; $request['owner_name_snapshot'] = $owner['display_name'];
        $items = $conn->execute_query('SELECT ri.expense_id,COALESCE(ri.expense_date,e.expense_date) AS expense_date,
            COALESCE(ri.merchant,e.merchant) AS merchant,COALESCE(ri.description,e.description) AS description,
            COALESCE(ri.amount_cents,e.amount_cents) AS amount_cents,COALESCE(ri.coa_number,c.coa_number) AS coa_number,
            COALESCE(ri.coa_description,c.description) AS coa_description
            FROM reimbursement_request_items ri JOIN reimbursement_expenses e ON e.id=ri.expense_id
            JOIN reimbursement_cost_centers c ON c.id=e.cost_center_id WHERE ri.request_id=? ORDER BY expense_date,ri.expense_id',[(int)$request['id']])->fetch_all(MYSQLI_ASSOC);
        $receipts = $conn->execute_query('SELECT rr.*,sf.size,sf.checksum,rf.size AS report_size,rf.checksum AS report_checksum
            FROM reimbursement_receipts rr JOIN stored_files sf ON sf.storage_key=rr.storage_key
            LEFT JOIN stored_files rf ON rf.storage_key=rr.report_key JOIN reimbursement_request_items ri ON ri.expense_id=rr.expense_id
            WHERE ri.request_id=? ORDER BY rr.expense_id,rr.id',[(int)$request['id']])->fetch_all(MYSQLI_ASSOC);
        $context = compact('request','owner','items','receipts'); $context['setup'] = reimbursementSetup($conn);
        $snapshot = empty($submission['snapshot_ciphertext']) ? null : json_decode(\Dnr\Security\ApplicationKey::open($submission['snapshot_ciphertext']),true,32,JSON_THROW_ON_ERROR);
        if ($snapshot !== null && (!isset($snapshot['context']) || !empty($snapshot['deliveries']))) throw new RuntimeException('Review non-fixture submission history before transfer.');
        $path = reimbursementBuildPackage($context);
        try {
            $key = storePersistentFileFromPath($conn,$path,reimbursementPackageBase($request).'.zip','application/zip',filesize($path),hash_file('sha256',$path));
        } finally { unlink($path); }
        if ($snapshot !== null) { $snapshot['context']=$context; $snapshot['recipients']=reimbursementSubmissionRecipients($context); }
        $ciphertext = $snapshot === null ? null : \Dnr\Security\ApplicationKey::seal(json_encode($snapshot,JSON_THROW_ON_ERROR));
        $conn->execute_query('UPDATE reimbursement_submissions SET attachment_key=?,snapshot_ciphertext=? WHERE id=?',[$key,$ciphertext,(int)$request['id']]);
        $packages++;
    }
    foreach (['follow_up_tasks'=>'assigned_to','engagements'=>'caller_user_id','booking_inquiries'=>'owner_user_id','reimbursement_expenses'=>'user_id','reimbursement_requests'=>'user_id'] as $table=>$column) {
        if ((int)$conn->execute_query('SELECT COUNT(*) FROM `'.$table.'` WHERE NOT(`'.$column.'`<=>?)',[$uid])->fetch_row()[0] !== 0) throw new RuntimeException('Ownership verification failed: '.$table);
        if ((int)$conn->query('SELECT COUNT(*) FROM `'.$table.'`')->fetch_row()[0] !== $counts[$table]) throw new RuntimeException('Row count changed: '.$table);
    }
    if (hash('sha256',serialize($conn->query('SELECT * FROM users ORDER BY id')->fetch_all(MYSQLI_ASSOC))) !== $usersBefore
        || serialize($conn->query('SELECT * FROM account_profile WHERE id=1')->fetch_assoc()) !== $profileBefore) throw new RuntimeException('Authentication/account settings changed unexpectedly.');
    $consistency = applicationDataConsistencyCounts($conn);
    if (array_sum($consistency)!==0) throw new RuntimeException('Data consistency failed: '.json_encode($consistency));
    if (isset($options['rollback'])) $conn->rollback(); else $conn->commit();
    echo json_encode(['account'=>'test-account','owner'=>'TestEditor','user_id'=>$uid,'rows'=>$counts,'submitted_packages_rebuilt'=>$packages,
        'users_and_settings_unchanged'=>true,'consistency'=>$consistency,'rolled_back'=>isset($options['rollback'])],JSON_PRETTY_PRINT),"\n";
} catch (Throwable $error) { $conn->rollback(); throw $error; }
