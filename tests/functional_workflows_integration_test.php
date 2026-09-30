<?php

declare(strict_types=1);
if (getenv('DNR_INTEGRATION_TEST') !== '1' || getenv('DNR_INTEGRATION_TARGET') !== 'disposable') {
    echo "Workflow improvement HTTP tests skipped (requires disposable database).\n";
    exit(0);
}
$source = getenv('DNR_TEST_SOURCE_DIR') ?: __DIR__ . '/../src';
require_once $source . '/config.php';
require_once $source . '/functions.php';
require_once $source . '/follow_up_task_helpers.php';
require_once $source . '/booking_inquiry_helpers.php';
require_once $source . '/workflow_task_helpers.php';
require_once $source . '/task_bulk_helpers.php';
require_once $source . '/duplicate_warning_helpers.php';
require_once __DIR__ . '/integration_auth_helpers.php';
function workflowExpect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function workflowHidden(string $html, string $name): string {
    preg_match('/name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $matches);
    workflowExpect(isset($matches[1]), 'Missing field: ' . $name);
    return html_entity_decode($matches[1], ENT_QUOTES, 'UTF-8');
}
$base = rtrim(getenv('DNR_TEST_BASE_URL') ?: 'http://127.0.0.1', '/');
workflowExpect(in_array(parse_url($base, PHP_URL_HOST), ['localhost', '127.0.0.1'], true), 'Use loopback HTTP');
$cookies = tempnam(sys_get_temp_dir(), 'workflow-http-');
$request = static function (string $path, ?array $post = null) use ($base, $cookies): array {
    $curl = curl_init($base . '/' . $path);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
        CURLOPT_COOKIEFILE => $cookies, CURLOPT_COOKIEJAR => $cookies, CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false]);
    if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($post));
    $raw = curl_exec($curl);
    workflowExpect(is_string($raw), 'HTTP request failed');
    $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
    return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => substr($raw, 0, $headerSize), 'body' => substr($raw, $headerSize)];
};
$suffix='functional-'.bin2hex(random_bytes(5));
$password=bin2hex(random_bytes(16));
$conn->execute_query("INSERT INTO users(username,password,role) VALUES (?,?,'editor')",[$suffix,password_hash($password,PASSWORD_DEFAULT)]);
$uid=(int)$conn->insert_id;
$login=$request('login.php'); $login=$request('login.php',['csrf_token'=>workflowHidden($login['body'],'csrf_token'),'username'=>$suffix,'password'=>$password]);
$login=finishIntegrationTestEnrollment($request,$login); workflowExpect($login['status']===302,'Editor login');
$_SESSION['user_id']=$uid;
$data=normalizeBookingInquiryInput($conn,['title'=>$suffix,'event_type'=>'conference','source'=>'other','priority'=>'normal','owner_user_id'=>$uid,'next_action'=>'Call host','next_action_due_date'=>applicationBusinessDate()]);
$id=createBookingInquiry($conn,$data,$uid,$suffix);
$inquiry=fetchBookingInquiry($conn,$id); $taskId=(int)$inquiry['next_action_task_id'];
workflowExpect($taskId>0,'Next action creates a task');
$task=fetchFollowUpTask($conn,$taskId);
$receipt=null; setFollowUpTaskStatus($conn,$taskId,'completed',$task['updated_at'],$uid,$receipt);
workflowExpect(fetchBookingInquiry($conn,$id)['next_action']===null,'Completing task clears next action');
undoFollowUpTaskCompletion($conn,$receipt,$uid);
workflowExpect(fetchBookingInquiry($conn,$id)['next_action']==='Call host','Undo restores next action summary');
$task=fetchFollowUpTask($conn,$taskId);
applyTaskBulkItem($conn,$task,['operation'=>'due','value'=>'2027-01-10'],$uid);
workflowExpect(fetchBookingInquiry($conn,$id)['next_action_due_date']==='2027-01-10','Bulk due change syncs inquiry');
try { applyTaskBulkItem($conn,$task,['operation'=>'complete','value'=>'completed'],$uid); throw new RuntimeException('Stale task accepted'); } catch (InvalidArgumentException $expected) {}
$inquiry=fetchBookingInquiry($conn,$id); $data['next_action']='Call again';
updateBookingInquiry($conn,$id,$data,$inquiry['updated_at']);
workflowExpect((int)fetchBookingInquiry($conn,$id)['next_action_task_id']===$taskId,'Editing keeps same task');
workflowExpect(fetchFollowUpTask($conn,$taskId)['title']==='Call again','Inquiry edit updates task');
$alternate=insertFollowUpTask($conn,['title'=>'Different next action','details'=>null,'status'=>'open','priority'=>'normal','due_date'=>null,'waiting_on'=>null,'subject_type'=>'inquiry','inquiry_id'=>$id,'engagement_id'=>null,'organization_id'=>null,'contact_id'=>null,'assigned_to'=>$uid],$uid);
$inquiry=fetchBookingInquiry($conn,$id);$data['next_action_task_choice']=$alternate;
updateBookingInquiry($conn,$id,$data,$inquiry['updated_at']);
workflowExpect((int)fetchBookingInquiry($conn,$id)['next_action_task_id']===$alternate,'Can designate an existing task');
$data['next_action_task_choice']=99999999;
try { updateBookingInquiry($conn,$id,$data,fetchBookingInquiry($conn,$id)['updated_at']); throw new RuntimeException('Invalid task accepted'); } catch (InvalidArgumentException $expected) {}
$queue=$request('tasks.php?scope=everyone&view=all'); workflowExpect($queue['status']===200,'Tasks renders after migration');
$task=fetchFollowUpTask($conn,$taskId);
$preview=$request('task_bulk.php',['csrf_token'=>workflowHidden($queue['body'],'csrf_token'),'action'=>'review','operation'=>'assign','assignee'=>'0','selected_ids'=>[$taskId],'versions'=>[$taskId=>$task['updated_at']]]);
workflowExpect($preview['status']===200 && str_contains($preview['body'],'Apply Updates'),'Bulk preview available');
$token=workflowHidden($preview['body'],'token');
$result=$request('task_bulk.php',['csrf_token'=>workflowHidden($preview['body'],'csrf_token'),'action'=>'apply','token'=>$token]);
workflowExpect($result['status']===200 && str_contains($result['body'],'1 of 1 tasks updated'),'Bulk apply reports success');
workflowExpect(fetchFollowUpTask($conn,$taskId)['assigned_to']===null,'Bulk unassign applied');
$replay=$request('task_bulk.php',['csrf_token'=>workflowHidden($preview['body'],'csrf_token'),'action'=>'apply','token'=>$token]);
workflowExpect(str_contains($replay['body'],'expired or was already applied'),'Bulk token single use');
$conn->execute_query('INSERT INTO organizations(organization_name,email) VALUES (?,?)',[$suffix,'office@example.test']);$orgId=(int)$conn->insert_id;
$conn->execute_query('INSERT INTO contacts(organization_id,contact_first_name,contact_last_name,contact_email) VALUES (?,?,?,?)',[$orgId,'Functional',$suffix,'host@example.test']);$contactId=(int)$conn->insert_id;
$warning=creationDuplicateWarning($conn,'contact',['contact_first_name'=>'Functional','contact_last_name'=>$suffix,'contact_email'=>'host@example.test']);
workflowExpect(count($warning['matches'])===1 && !creationDuplicatesAcknowledged($warning,[]),'Duplicate requires acknowledgment');
workflowExpect(creationDuplicatesAcknowledged($warning,['duplicate_distinct'=>'1','duplicate_token'=>$warning['token']]),'Distinct record allowed after acknowledgment');
$inquiry=fetchBookingInquiry($conn,$id);$data['next_action_task_choice']=0;$data['organization_id']=$orgId;$data['primary_contact_id']=$contactId;
updateBookingInquiry($conn,$id,$data,$inquiry['updated_at']); $inquiry=fetchBookingInquiry($conn,$id);
$messageId=queueBookingInquiryEmail($conn,$inquiry,'initial_response','Functional reply test','Please reply',$uid,$suffix,'2027-01-10');
$state=emailFollowUpState($conn,$messageId); workflowExpect($state && $state['label']==='Awaiting reply','Email creates waiting task');
$emailTaskId=(int)$state['follow_up_task_id'];
$pageStates=emailFollowUpStatesForTasks($conn,[$taskId,$emailTaskId,$emailTaskId]);
workflowExpect(count($pageStates)===1 && $pageStates[$emailTaskId]['id']===$messageId
    && $pageStates[$emailTaskId]['label']==='Awaiting reply','Task page batches linked email states');
$conn->execute_query('UPDATE engagement_email_deliveries SET smtp_message_id=? WHERE message_id=?',['<functional-test@example.test>',$messageId]);
$raw="In-Reply-To: <functional-test@example.test>\r\n";
$conn->execute_query("INSERT INTO inbound_email_messages(transport,transport_key,deduplication_hash,gateway_address,sender_address,to_addresses,cc_addresses,subject,received_at,body_text,attachment_names,raw_headers,status) VALUES ('file',?,UNHEX(SHA2(?,256)),'inbound@example.test','host@example.test','[]','[]','Reply',DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 SECOND),'Yes','[]',?,'processed')",[$suffix,$suffix,$raw]);$reply=(int)$conn->insert_id;
$conn->execute_query('INSERT INTO booking_inquiry_chron_entries(booking_inquiry_id,inbound_email_message_id,entry_text) VALUES (?,?,?)',[$id,$reply,'Reply']);
$state=emailFollowUpState($conn,$messageId);workflowExpect($state['reply_id']===$reply && $state['task_status']==='waiting','Reply detected without completing task');
$pageStates=emailFollowUpStatesForTasks($conn,[$emailTaskId]);
workflowExpect($pageStates[$emailTaskId]['label']===$state['label'],'Task page recognizes received replies');
$queue=$request('tasks.php?scope=everyone&view=all&task_id='.$emailTaskId);
workflowExpect($queue['status']===200 && str_contains($queue['body'],'Reply received — review needed'),
    'Task page renders the batched reply label');
$conn->execute_query("UPDATE follow_up_tasks SET status='completed', completed_at=CURRENT_TIMESTAMP(6) WHERE id=?",[$emailTaskId]);
$pageStates=emailFollowUpStatesForTasks($conn,[$emailTaskId]);
workflowExpect($pageStates[$emailTaskId]['label']==='Follow-up closed','Task page keeps closed email follow-up label');
$conn->execute_query("UPDATE follow_up_tasks SET status='waiting', completed_at=NULL WHERE id=?",[$emailTaskId]);
foreach(['add_contact.php','add_organization.php','edit_inquiry.php?id='.$id,'compose_inquiry_email.php?id='.$id,'outbound_mail.php?id='.$messageId,'email_templates.php?status=archived'] as $path) {
 $response=$request($path);workflowExpect($response['status']===200 && !str_contains($response['body'],'Fatal error') && !str_contains($response['body'],'Warning:'),'Page renders: '.$path);
}
require_once $source . '/engagement_contact_helpers.php';
require_once $source . '/map_helpers.php';
$inquiry=fetchBookingInquiry($conn,$id);$data['preferred_start_date']='2027-02-01';$data['preferred_end_date']='2027-02-02';
updateBookingInquiry($conn,$id,$data,$inquiry['updated_at']);$inquiry=fetchBookingInquiry($conn,$id);
$linked=(int)$inquiry['next_action_task_id'];
$converted=convertBookingInquiry($conn,$id,$inquiry['updated_at'],true,[$linked],$uid,$suffix,'carry_forward');
workflowExpect($converted['next_action_task_id']===$linked && $converted['moved_task_count']===1,'Conversion moves linked next action without duplicating it');
workflowExpect((int)fetchFollowUpTask($conn,$linked)['engagement_id']===$converted['engagement_id'],'Converted task belongs to new event');
$orgPage=$request('add_organization.php');
$orgPost=['csrf_token'=>workflowHidden($orgPage['body'],'csrf_token'),'save_org'=>'1','organization_name'=>$suffix];
$blocked=$request('add_organization.php',$orgPost);
workflowExpect(str_contains($blocked['body'],'Review possible existing records'),'Duplicate submission blocked until acknowledged');
$before=(int)$conn->execute_query('SELECT COUNT(*) AS total FROM organizations WHERE organization_name=?',[$suffix])->fetch_assoc()['total'];
workflowExpect($before===1,'Duplicate warning creates no record');
$conn->execute_query("UPDATE users SET role='reviewer' WHERE id=?",[$uid]);
workflowExpect($request('task_bulk.php',['csrf_token'=>workflowHidden($queue['body'],'csrf_token'),'action'=>'review'])['status']===403,'Reviewer cannot bulk update');
@unlink($cookies);
echo "Functional workflow integration tests passed.\n";
