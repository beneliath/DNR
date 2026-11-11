<?php
declare(strict_types=1);
require __DIR__.'/../../src/functions.php';
$screen = $argv[1];
if (in_array($screen, ['public', 'script-free'], true)) {
    require __DIR__.'/../../src/audience_store.php';
    require __DIR__.'/../../src/audience_collection_render.php';
    $document = audienceCollectionDefaults('embedded');
    $document['embedded']['disable_javascript'] = $screen === 'script-free';
    $document['fields'] = ['email', 'custom:1', 'custom:2'];
    $fields = [];
    foreach (['date', 'birthday'] as $index => $type) {
        $fields[] = ['id'=>$index + 1, 'label'=>ucfirst($type), 'field_type'=>$type, 'is_public'=>1,
            'options_json'=>'[]', 'default_value'=>''];
    }
    echo audienceCollectionHtml(['kind'=>'embedded', 'document'=>$document], $fields, [], '/subscribe');
    return;
}
echo '<!doctype html><html lang="en">';
if ($screen === 'home') {
    renderPageHead('MessageMonkey Home', ['styles'=>['assets/css/pages/audience.min.css', 'assets/css/pages/audience-analytics.min.css'],
        'scripts'=>['assets/js/audience-analytics.min.js']]);
    echo '<body class="audience-app">';
    $view = 'home';
    $showDrafts = false;
    $rangeKey = 'last30';
    $presets = [
        'last30'=>['label'=>'Last 30 Days', 'start'=>'2024-02-01', 'end'=>'2024-03-01'],
        'last7'=>['label'=>'Last 7 Days', 'start'=>'2024-02-24', 'end'=>'2024-03-01'],
    ];
    $window = $presets[$rangeKey] + ['bots'=>'filtered'];
    $h = static fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    function audienceInputs(array $values): void {
        foreach ($values as $name=>$value) echo '<input type="hidden" name="'.htmlspecialchars($name).'" value="'.htmlspecialchars($value).'">';
    }
    include __DIR__.'/../../src/templates/audience/analytics-filters.php';
} elseif ($screen === 'expense') {
    renderPageHead('New Expense', ['styles'=>['assets/css/style.min.css', 'assets/css/modern.min.css', 'assets/css/pages/reimbursements.min.css']]);
    echo '<body><main class="container reimbursement-page reimbursement-form-page"><section class="reimbursement-card"><form><div class="reimbursement-form-grid">';
    echo '<label>Expense Date <input type="text" data-date-picker="date" name="expense_date" value="2026-11-08" required></label>';
    echo '<label>Merchant or Payee <input type="text" name="merchant" required></label>';
    echo '</div></form></section></main>';
} else {
    require __DIR__.'/../../src/follow_up_task_helpers.php';
    renderPageHead('New Task');
    echo '<body><main class="container">';
    $task_users = [['id'=>1, 'username'=>'Task owner', 'role'=>'admin']];
    $task_form_values = ['assigned_to'=>1, 'title'=>'Follow up'];
    include __DIR__.'/../../src/templates/follow_up_task_form.php';
    echo '</main>';
}
echo '</body></html>';
