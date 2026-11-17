<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/pagination_helpers.php';
require_once __DIR__ . '/../src/follow_up_task_helpers.php';

function expectPaginationPreference(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Pagination preference test failed: {$message}\n");
        exit(1);
    }
}

$_COOKIE = [];
expectPaginationPreference(paginationPageSizePreference('contacts', null) === 20,
    'New visitors use the page default.');
expectPaginationPreference(!isset($_COOKIE['dnr_rows_per_page_contacts']),
    'Visiting a page without a selection does not save the initial default.');
expectPaginationPreference(paginationPageSizePreference('contacts', '50') === 50,
    'Selecting a supported size applies it immediately.');
expectPaginationPreference(paginationPageSizePreference('organizations', null) === 20,
    'A contact preference does not change organizations.');
expectPaginationPreference(paginationPageSizePreference('organizations', '100') === 100,
    'Organizations can have a different preference.');
expectPaginationPreference(paginationPageSizePreference('contacts', null) === 50
    && paginationPageSizePreference('organizations', null) === 100,
    'Returning without a page-size parameter restores each page separately.');

foreach ([[], 'invalid', 0, -1, 500] as $invalid) {
    expectPaginationPreference(paginationPageSizePreference('contacts', $invalid) === 50,
        'Invalid requests cannot overwrite a remembered setting.');
}
expectPaginationPreference(paginationPageSizePreference('contacts', 20) === 20
    && paginationPageSizePreference('contacts', null) === 20,
    'Explicitly choosing the default replaces the previous selection.');
expectPaginationPreference(paginationPageSizePreference('view_organization_chron', 50) === 50
    && paginationPageSizePreference('view_organization_events', null) === 20
    && paginationPageSizePreference('edit_organization_chron', null) === 20,
    'Separate lists and page types have independent preferences.');
expectPaginationPreference(paginationPageSizePreference('map', null) === 20
    && paginationPageSizePreference('map', 100) === 100
    && paginationPageSizePreference('map', null, 20, [20, 50]) === 20,
    'Stored preferences respect the current route size limit.');
$_COOKIE['dnr_rows_per_page_map'] = '500';
expectPaginationPreference(paginationPageSizePreference('map', null) === 20,
    'The retired 500-row map preference falls back to 20.');

$_COOKIE['dnr_rows_per_page_users'] = ['malformed'];
expectPaginationPreference(paginationPageSizePreference('users', null) === 20,
    'Malformed cookies fall back safely.');
$_COOKIE['dnr_rows_per_page_users'] = '99999';
expectPaginationPreference(paginationPageSizePreference('users', null) === 20,
    'Cookies cannot request unsupported row limits.');

$_COOKIE = ['dnr_rows_per_page_tasks_everyone' => '50'];
expectPaginationPreference(followUpTaskPageSizePreference('everyone', 11, null) === 50,
    'An existing ownership preference migrates to the account/user/view preference.');
expectPaginationPreference(followUpTaskPageSizePreference('mine', 11, null) === 20
    && followUpTaskPageSizePreference('everyone', 11, null) === 50,
    'Returning through My Tasks retains the separately saved Everyone setting.');
expectPaginationPreference(followUpTaskPageSizePreference('unassigned', 11, '100') === 100
    && followUpTaskPageSizePreference('everyone', 11, null) === 50,
    'Unassigned Tasks can remember a different choice.');
unset($_COOKIE['dnr_rows_per_page_tasks_everyone']);
expectPaginationPreference(followUpTaskPageSizePreference('everyone', 22, null) === 20
    && followUpTaskPageSizePreference('everyone', 11, null) === 50,
    'A different signed-in user cannot overwrite the saved choice.');
expectPaginationPreference(followUpTaskPageSizePreference('everyone', 11, '500') === 50
    && followUpTaskPageSizePreference('everyone', 11, '20') === 20
    && followUpTaskPageSizePreference('unassigned', 11, null) === 100,
    'Invalid choices are ignored, and explicitly choosing 20 changes only that ownership view.');
$tasksSource = file_get_contents(__DIR__ . '/../src/tasks.php');
$queue_parameters = ['view' => 'all', 'scope' => 'everyone', 'per_page' => 50, 'q' => 'follow up'];
$queueStart = strpos($tasksSource, '$queue_url = static function');
$queueEnd = strpos($tasksSource, '$subject_filter_record =', $queueStart);
eval(substr($tasksSource, $queueStart, $queueEnd - $queueStart));
parse_str(parse_url($queue_url(['scope' => 'mine']), PHP_URL_QUERY), $nextQueue);
expectPaginationPreference($nextQueue['scope'] === 'mine' && !isset($nextQueue['per_page'])
    && $nextQueue['q'] === 'follow up',
    'Ownership links restore the target view preference while preserving the search.');
parse_str(parse_url($queue_url(['view' => 'waiting']), PHP_URL_QUERY), $sameOwnership);
expectPaginationPreference($sameOwnership['per_page'] === '50',
    'Changing a status filter within the same ownership view retains its size.');
expectPaginationPreference(paginationUserPageSizePreference('engagements', 11, '50') === 50
    && paginationUserPageSizePreference('engagements', 11, null) === 50
    && paginationUserPageSizePreference('engagements', 22, null) === 20,
    'Engagements restores each signed-in user\'s own page-size selection.');

echo "Pagination preference tests passed.\n";
