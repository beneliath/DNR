<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/list_ui_helpers.php';
require_once __DIR__ . '/../src/record_workspace_helpers.php';
require_once __DIR__ . '/../src/engagement_lifecycle_helpers.php';
require_once __DIR__ . '/../src/engagement_view_helpers.php';

function reimbursementH(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function reimbursementCenterH(mixed $value): string { return reimbursementH($value); }
function reimbursementRequestsH(mixed $value): string { return reimbursementH($value); }

/** Execute only the route's pure sort/URL setup and render its real UI fragments. */
function listUiFixture(string $page, array $input = []): array
{
    if (!in_array($page, ['organizations', 'contacts', 'speakers', 'engagements', 'reimbursements', 'reimbursement_requests', 'reimbursement_cost_centers'], true)) {
        throw new InvalidArgumentException('Unknown list fixture.');
    }
    $source = file_get_contents(__DIR__ . '/../src/' . $page . '.php');
    $block = static function (string $start, string $end) use ($source): string {
        $offset = strpos($source, $start);
        $limit = $offset === false ? false : strpos($source, $end, $offset + strlen($start));
        if ($offset === false || $limit === false) throw new RuntimeException('Missing fixture source block: ' . $start);
        return substr($source, $offset, $limit - $offset);
    };
    $previousInput = $_GET;
    $_GET = $input;
    try {
        $search = (string) ($input['q'] ?? '');
        $list_status = $input['status'] ?? 'active';
        $page_size = $pageSize = (int) ($input['per_page'] ?? 20);
        $current_page = (int) ($input['page'] ?? 1);
        $showArchived = ($input['show'] ?? '') === 'archived';
        $view = $input['show'] ?? 'active';
        $start = $input['start_date'] ?? '2026-01-01';
        $end = $input['end_date'] ?? '2026-12-31';
        $canViewAll = true;
        $ownerFilterId = $filterOwner = (int) ($input['owner_id'] ?? 0);
        $status = $input['status'] ?? '';
        $state = $input['state'] ?? 'all';
        $date_from = $input['date_from'] ?? '';
        $date_to = $input['date_to'] ?? '';
        $lifecycle_filter = engagementLifecycleFilter($input['lifecycle'] ?? null);
        $date_filter_active = $date_from !== '' || $date_to !== '';
        $date_error = '';
        $summary = ['archived' => 0];

        if ($page === 'organizations') {
            eval($block('$selected_sort =', '$sort_direction ='));
        } elseif ($page === 'contacts') {
            eval($block('$sort_input =', '$search ='));
            if (!function_exists('contactsPageUrl')) eval($block('function contactsPageUrl(', '$list_current_url ='));
        } elseif ($page === 'speakers') {
            eval($block('$selected_sort =', '$direction ='));
            eval($block('$list_current_url =', '?>'));
        } elseif ($page === 'engagements') {
            eval($block('$selected_sort =', '$lifecycle_filter ='));
        } elseif ($page === 'reimbursements') {
            eval($block('$sortDefaults =', '$error ='));
            eval($block('$listParams =', '$expenses ='));
        } elseif ($page === 'reimbursement_requests') {
            eval($block('$sorts=', '$where='));
            eval($block('$listParams=', '$owners='));
        } else {
            eval($block('$sortColumns =', '$editId ='));
        }
        if (in_array($page, ['organizations', 'engagements'], true)) {
            if (!preg_match('/\$list_url = static function\b.*?\n};/s', $source, $urlBlock)) throw new RuntimeException('Missing list URL builder.');
            eval($urlBlock[0]);
        }
        if (!preg_match('/<thead>.*?<\/thead>/s', $source, $head)) throw new RuntimeException('Missing table heading.');
        ob_start();
        eval('?>' . $head[0]);
        $header = ob_get_clean();
        ob_start();
        if ($page === 'reimbursements') {
            eval('?>' . $block('<div class="reimbursement-list-heading reimbursement-expenses-list-heading', '<div class="reimbursement-filter-block">'));
        } elseif ($page !== 'engagements' && preg_match('/<\?php renderListContextTabs\(.*?\?>/s', $source, $context)) {
            eval('?>' . $context[0]);
        }
        $contextHtml = ob_get_clean();
        $controls = '';
        if ($page === 'engagements') {
            $startOffset = strpos($source, '<div class="list-controls engagement-controls');
            $endOffset = strpos($source, '<?php renderBulkDeleteToolbar', $startOffset);
            ob_start();
            eval('?>' . substr($source, $startOffset, $endOffset - $startOffset));
            $controls = ob_get_clean();
        }
        preg_match('/<table\b[^>]*>/', $source, $table);
        return ['header' => $header, 'context' => $contextHtml, 'controls' => $controls, 'table' => $table[0],
            'selection' => $selected_sort ?? $selectedSort];
    } finally {
        $_GET = $previousInput;
    }
}
