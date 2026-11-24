<?php
// Render the current list headings, context controls, filters, and sample tables
// directly from the production templates without opening a database.
require_once __DIR__ . '/../list_ui_fixture.php';
require_once __DIR__ . '/../../src/follow_up_task_helpers.php';
require_once __DIR__ . '/../../src/shared/csrf.php';
$root = dirname(__DIR__, 2) . '/src';
parse_str($argv[2] ?? '', $_GET);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/' . ($argv[1] ?? 'engagements') . '.php';
$page = $argv[1] ?? 'engagements';
if (!in_array($page, ['engagements','tasks','organizations','contacts','speakers','email_templates','reimbursements','reimbursement_requests','reimbursement_cost_centers','standard_tasks','inquiries'])) { http_response_code(404); exit; }
$source = file_get_contents($root . '/' . $page . '.php');
$archived = $show_archived = $showArchived = in_array('archived', [$_GET['status'] ?? '', $_GET['show'] ?? '', $_GET['view'] ?? ''], true);
$list_status = $status = $archived ? 'archived' : 'active';
$view = $page === 'tasks' ? ($archived ? 'archived' : 'all') : ($_GET['show'] ?? 'active');
$_SESSION = ['user_id'=>11, 'role'=>'admin', '_csrf_token'=>'layout-fixture'];
$userId=11; $user_role='admin'; $can_manage_tasks=$can_manage_speakers=$canManage=$canViewAll=true;
$canSelect=!$archived; $selectionRemoved=false;
$search=$action_message=$action_error=$message=$error=$date_error=$date_from=$date_to='';
$page_size=$pageSize=20; $current_page=1; $list_current_url=$listUrl=$returnUrl=$page.'.php';
$total_contacts=$total_speakers=1; // One sample row is rendered below, including pagination.
$sort_column=$sortBy='name'; $name_sort=$last_name_sort=$organization_sort=$last_giving_sort=$lifetime_giving_sort=$date_sort=$status_sort=$lifecycle_sort=$org_sort=$title_sort=$sortDir=$direction='asc';
$sort='created'; $state='all'; $lifecycle_filter='active';
$date_from=$_GET['date_from'] ?? ''; $date_to=$_GET['date_to'] ?? '';
$date_filter_active=$date_from!=='' || $date_to!=='';
$start='2026-01-01'; $end='2026-11-16'; $ownerFilterId=$filterOwner=0;
$owners=$expenseOwners=[['id'=>11,'name'=>'Example User','owner_name'=>'Example User']];
$stateLabels=['all'=>'All','incomplete'=>'Incomplete','available'=>'Available','draft'=>'Draft','submitted'=>'Submitted'];
$listParams=['show'=>$archived?'archived':'active'];
$link = static fn($changes=[]) => $page.'.php?'.http_build_query(array_replace($_GET,$changes));
$list_url=$listLink=$queue_url=$sortUrl=$link;
$stateUrl=static fn($value) => $link(['state'=>$value]);
$queue_parameters=[]; $scope='mine'; $scope_labels=['mine'=>'My Tasks','everyone'=>'Everyone’s Tasks','unassigned'=>'Unassigned Tasks'];
$view_labels=followUpTaskQueueViews();$view_labels['completed']='Completed or canceled';
$summary=array_fill_keys(['all','overdue','today','upcoming','waiting','completed','active','postponed','canceled','archived'],0);
$has_subject_filter=false;$selected_task_id=0;$new_task_url='edit_task.php';$engagementId=null;
$editingCenter=null;$formValues=['coa_number'=>'','description'=>''];
$pagination=['total'=>0,'page'=>1];
$fixture = null;
if (!in_array($page,['tasks','email_templates','standard_tasks','inquiries'])) $fixture=listUiFixture($page,$_GET);
if ($page === 'reimbursement_requests') $status='';
$notice=$exportQuery=$priority=$timing=$owner='';
if($page==='inquiries')$view=$archived?'archived':'active';
$pageTitle=$archived?'Archived Inquiries':'Booking Pipeline';
$starts = strpos($source,'<main ');
$ends = strpos($source, match($page){
 'engagements','organizations','contacts','speakers'=>'<?php renderBulkDeleteToolbar',
 'tasks'=>'<?php if ($subject_filter_record): ?>',
 'email_templates','inquiries'=>'<?php renderPagination',
 'standard_tasks'=>'<div class="data-table-scroll">',
 'reimbursements'=>'<div class="reimbursement-table-wrap">',
 'reimbursement_requests'=>'<?php renderPagination',
 default=>'<div class="reimbursement-table-wrap">'
},$starts);
$body=substr($source,$starts,$ends-$starts);
$body=str_replace("include __DIR__ . '/templates/reimbursement_progress.php'", "include '".$root."/templates/reimbursement_progress.php'",$body);
preg_match('/renderPageHead\(.*?\);/s',$source,$head);
ob_start(); echo '<!doctype html><html>'; eval($head[0]); echo '<body>';
if ($page === 'reimbursements' && isset($_GET['fixture_selection'])) {
    $savedSelection = [
        'scope' => hash('sha256', json_encode([$start, $end, $ownerFilterId, $search])),
        'ids' => ['901', '902'],
        'metadata' => $_GET['fixture_selection'] === 'saved-legacy' ? (object) [] : [
            '901' => ['amount' => 1234567, 'missing' => true],
            '902' => ['amount' => 678900, 'missing' => true],
        ],
    ];
    echo '<script>window.DnrAccountContext.session.setItem("reimbursement-selection-11",'
        . json_encode(json_encode($savedSelection)) . ');</script>';
}
eval('?>'.$body);
if ($fixture) {
 echo (str_starts_with($page,'reimbursement')?'<div class="reimbursement-table-wrap">':'').$fixture['table'].$fixture['header'].'<tbody><tr>';
 preg_match_all('/<th\b.*?<\/th>/s',$fixture['header'],$headers);
 foreach ($headers[0] as $i=>$header) echo '<td data-field="'.htmlspecialchars(trim(preg_replace('/[↑↓↕]/u','',strip_tags($header)))).'">Example '.$i.'</td>';
 echo '</tr></tbody></table>';if(str_starts_with($page,'reimbursement'))echo '</div>';
} else echo '<p>Example list content</p>';
if ($page==='reimbursements') echo '</div></form></section>';
if ($page === 'reimbursement_requests') echo '</section>';
if ($page === 'reimbursement_cost_centers') echo '</div></section>';
echo '</main><span hidden data-initial-layout-parsed></span>';
renderScript('assets/js/workflow-ui.min.js');
echo '</body></html>';
echo ob_get_clean();
