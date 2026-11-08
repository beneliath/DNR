<?php
declare(strict_types=1);

require __DIR__ . '/../../src/functions.php';
require __DIR__ . '/../../src/follow_up_task_helpers.php';
require __DIR__ . '/../../src/booking_inquiry_helpers.php';
require_once __DIR__ . '/../../src/app/Domain/ReferenceData.php';

function reimbursementCenterH(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = ['user_id' => 11, '_csrf_token' => 'layout-fixture'];
$case = $argv[1] ?? 'task';
$draftState = $argv[2] ?? 'none';
$styles = ['assets/css/style.min.css', 'assets/css/modern.min.css'];
$page = str_starts_with($case, 'list:') ? substr($case, 5) : $case;
if (is_file(__DIR__ . '/../../src/assets/css/pages/' . $page . '.min.css')) $styles[] = 'assets/css/pages/' . $page . '.min.css';
if ($case === 'inquiry') $styles[] = 'assets/css/pages/booking_inquiries.min.css';
if ($draftState === 'cleared') $_SESSION['clear_form_drafts'] = ['layout'];

echo '<!doctype html><html>';
renderPageHead('Initial layout', ['styles' => $styles]);
echo '<body class="has-app-shell"><aside id="app-sidebar" class="app-sidebar" data-nav-preference-user="layout-fixture"></aside><main class="container">';

if (str_starts_with($case, 'task')) {
    $task_users = [['id' => 11, 'username' => 'Example user', 'role' => 'admin']];
    $task_form_values = ['assigned_to' => 11];
    $task_selected_subject = 'general';
    if ($case === 'task-linked') {
        $task_selected_subject = 'engagement:17';
        $task_selected_record = ['label' => 'An existing event with a longer title', 'active' => true];
    }
    if ($case === 'task-duplicate') {
        $task_selected_subject = '';
        $task_require_engagement_subject = true;
    }
    include __DIR__ . '/../../src/templates/follow_up_task_form.php';
} elseif (str_starts_with($case, 'list:')) {
    $source = file_get_contents(__DIR__ . '/../../src/' . $page . '.php');
    preg_match('/<form\b[^>]*class="list-search-form[^>]*>/', $source, $match, PREG_OFFSET_CAPTURE);
    $start = $match[0][1];
    $end = strpos($source, '</form>', $start) + strlen('</form>');
    $search = '';
    $view = 'all'; $scope = 'mine'; $has_subject_filter = false; $selected_task_id = 0;
    $page_size = 20; $list_status = $status = 'active'; $engagementId = null;
    $sort_column = 'name'; $last_name_sort = $organization_sort = $name_sort = 'asc';
    $last_giving_sort = $lifetime_giving_sort = $date_sort = $status_sort = $lifecycle_sort = $org_sort = 'asc';
    $lifecycle_filter = 'all'; $date_filter_active = false; $date_from = $date_to = $date_error = '';
    $list_url = static fn(array $changes = []): string => $page . '.php?' . http_build_query($changes);
    $showArchived = false;
    $sortBy = 'coa_number'; $sortDir = 'asc';
    $category = $from_date = $to_date = $ip_filter = '';
    eval('?>' . substr($source, $start, $end - $start));
    if (in_array($page, ['tasks', 'contacts', 'organizations', 'speakers', 'engagements'], true)
        && preg_match('/<table\b[^>]*>/', $source, $table, PREG_OFFSET_CAPTURE)) {
        $tableStart = $table[0][1];
        $tableSource = substr($source, $tableStart, strpos($source, '</table>', $tableStart) - $tableStart);
        preg_match('/<thead>.*?<\/thead>/s', $tableSource, $head);
        echo $table[0][0] . $head[0] . '<tbody><tr>';
        preg_match_all('/<td\b[^>]*>/', $tableSource, $cells);
        foreach ($cells[0] as $index => $cell) {
            if (str_contains($cell, 'colspan=')) continue;
            echo $cell . 'Sample value ' . $index . '</td>';
        }
        echo '</tr></tbody></table>';
    }
    echo '<p id="after-content">After the list</p>';
} elseif ($case === 'affiliations') {
    $contact_organization_options = [['id' => 1, 'organization_name' => 'Primary organization'], ['id' => 2, 'organization_name' => 'Second organization']];
    $additional_organization_rows = [['organization_id' => 2, 'role_title' => 'Coordinator']];
    echo '<form><input name="organization_id" value="1" type="hidden"><select name="contact_role"><option value="pastor">Pastor</option></select><input name="contact_role_other" type="hidden">';
    include __DIR__ . '/../../src/templates/contact_organization_affiliations.php';
    echo '<button type="submit" id="after-content">Save Contact</button></form>';
    renderScript('assets/js/record-workspace.min.js');
} elseif ($case === 'inquiry') {
    include __DIR__ . '/../../src/templates/booking_inquiry_form.php';
    renderScript('assets/js/page-actions.min.js');
} else {
    echo '<form data-recoverable-draft="layout">';
    if ($case === 'draft-chron') {
        $source = file_get_contents(__DIR__ . '/../../src/edit_engagement.php');
        $start = strpos($source, '<input type="hidden" name="engagement_version"');
        $end = strpos($source, '<?php renderFormDraftPanel(); ?>', $start);
        $engagement = ['updated_at' => '1'];
        $chron_entries = [['id' => 8, 'updated_at' => '2'], ['id' => 9, 'updated_at' => '3']];
        $submitted_chron_versions = [];
        eval('?>' . substr($source, $start, $end - $start));
    } else {
        echo '<input type="hidden" name="version" value="1">';
    }
    renderFormDraftPanel();
    echo '<section class="form-section" id="after-content"><h2>Record details</h2><label>Name<input name="name"></label><button>Save</button></section></form>';
}
echo '</main><span hidden data-initial-layout-parsed></span>';
renderScript('assets/js/workflow-ui.min.js');
echo '</body></html>';
