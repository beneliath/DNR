<?php

declare(strict_types=1);
require_once __DIR__ . '/list_ui_fixture.php';

$checks = 0;
function expectListUi(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}

$lists = [
    'organizations' => ['name', 'asc', ['name' => ['name_sort', 'asc'], 'last_giving' => ['last_giving_sort', 'desc'], 'lifetime_giving' => ['lifetime_giving_sort', 'desc']]],
    'contacts' => ['last_name', 'asc', ['last_name' => ['last_name_sort', 'asc'], 'organization' => ['organization_sort', 'asc']]],
    'speakers' => ['name', 'asc', ['name' => ['name_sort', 'asc']]],
    'engagements' => ['date', 'asc', ['title' => ['title_sort', 'asc'], 'org' => ['org_sort', 'asc'], 'date' => ['date_sort', 'asc'], 'lifecycle' => ['lifecycle_sort', 'asc'], 'status' => ['status_sort', 'asc']]],
    'reimbursements' => ['date', 'desc', ['date' => ['sort_dir', 'desc'], 'merchant' => ['sort_dir', 'asc'], 'owner' => ['sort_dir', 'asc'], 'cost_center' => ['sort_dir', 'asc'], 'amount' => ['sort_dir', 'desc'], 'status' => ['sort_dir', 'asc']]],
    'reimbursement_requests' => ['created', 'desc', ['request' => ['sort_dir', 'desc'], 'owner' => ['sort_dir', 'asc'], 'total' => ['sort_dir', 'desc'], 'created' => ['sort_dir', 'desc'], 'submitted' => ['sort_dir', 'desc']]],
    'reimbursement_cost_centers' => ['coa_number', 'asc', ['coa_number' => ['sort_dir', 'asc'], 'description' => ['sort_dir', 'asc'], 'expenses' => ['sort_dir', 'desc']]],
];
$context = ['q' => 'Shalom & Messiah', 'status' => 'archived', 'show' => 'archived', 'per_page' => 50,
    'page' => 3, 'cursor' => 'old-cursor', 'lifecycle' => 'completed', 'date_from' => '2026-01-01', 'date_to' => '2026-12-31',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31', 'owner_id' => 7, 'state' => 'submitted'];
foreach ($lists as $page => [$defaultColumn, $defaultDirection, $columns]) {
    $default = ['column' => $defaultColumn, 'direction' => $defaultDirection];
    expectListUi(listUiFixture($page)['selection'] === $default, $page . ' uses its requested default.');
    foreach ($columns as $column => [$directionKey]) foreach (['asc', 'desc'] as $direction) {
        $fixture = listUiFixture($page, array_replace($context, ['sort_by' => $column, $directionKey => $direction]));
        $document = new DOMDocument();
        @$document->loadHTML('<meta charset="utf-8"><table>' . $fixture['header'] . '</table>' . $fixture['context'] . $fixture['controls']);
        $xpath = new DOMXPath($document);
        $active = $xpath->query('//th[@aria-sort="' . ($direction === 'asc' ? 'ascending' : 'descending') . '"]');
        expectListUi($active->length === 1, $page . ' identifies exactly one active sort column.');
        $links = $xpath->query('//th/a[@class="table-sort-link"]');
        expectListUi($links->length === count($columns), $page . ' exposes every supported sort on a heading.');
        foreach ($links as $link) {
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            $key = $query['sort_by'] ?? $defaultColumn;
            [$keyDirection, $keyDefault] = $columns[$key];
            $expectedDirection = $key === $column ? ($direction === 'asc' ? 'desc' : 'asc') : $keyDefault;
            expectListUi($query[$keyDirection] === $expectedDirection, $page . ' toggles the active column and starts other columns in their default direction.');
            expectListUi($query['q'] === $context['q'], $page . ' preserves search.');
            if ($page !== 'reimbursement_cost_centers') expectListUi((int) $query['per_page'] === 50, $page . ' preserves page size.');
            expectListUi(!isset($query['cursor']) && (int) ($query['page'] ?? 1) === 1, $page . ' resets pagination after sorting.');
            $arrow = $xpath->evaluate('string(span[@aria-hidden="true"])', $link);
            expectListUi($arrow === ($key === $column ? ($direction === 'asc' ? '↑' : '↓') : '↕'), $page . ' displays the actual current direction.');
            if ($page === 'engagements') expectListUi($query['date_from'] === $context['date_from'] && $query['lifecycle'] === 'completed', 'Engagement dates and lifecycle survive sorting.');
            if (str_starts_with($page, 'reimbursement')) expectListUi(($query['show'] ?? '') === 'archived', 'Reimbursement context survives sorting.');
        }
        if ($page !== 'speakers') expectListUi($xpath->query('//nav[@class="list-context-tabs"]/a[@aria-current="page"]')->length === 1, 'One context tab identifies the current view.');
        foreach ($xpath->query('//nav[@class="list-context-tabs"]/a') as $tab) {
            parse_str((string) parse_url($tab->getAttribute('href'), PHP_URL_QUERY), $query);
            expectListUi(listUiFixture($page, $query)['selection'] === $fixture['selection'],
                $page . ' preserves the selected column and direction when changing visibility.');
        }
    }
    expectListUi(listUiFixture($page)['selection'] === $default, $page . ' returns to its fixed default on a fresh visit.');
    foreach ([['sort_by' => []], ['sort_by' => 'invalid'], ['sort_by' => $defaultColumn, $columns[$defaultColumn][0] => []]] as $invalid) {
        expectListUi(listUiFixture($page, $invalid)['selection'] === $default, $page . ' rejects malformed sort input.');
    }
}
$fixture = listUiFixture('engagements');
$document = new DOMDocument(); @$document->loadHTML($fixture['controls']);
$xpath = new DOMXPath($document);
expectListUi($xpath->evaluate('normalize-space(//nav[@class="list-context-tabs"]/a[@aria-current="page"])') === 'Active'
    && $xpath->evaluate('normalize-space(//div[@aria-label="Engagement Lifecycle Filter"]//a[@aria-current="true"])') === 'Scheduled', 'Engagements opens with Active visibility and Scheduled lifecycle.');
expectListUi($xpath->query('//div[@class="engagement-date-filter-row"]/fieldset[@data-engagement-date-window]')->length === 1
    && $xpath->query('//div[@class="directory-filter-row"]/div[@aria-label="Engagement Lifecycle Filter"]')->length === 1,
    'Lifecycle shares the main controls row; dates have their own compact row.');
ob_start();
renderListContextTabs(['active' => ['Active <script>', 'organizations.php?q=A&B']], 'active', 'Context "label"');
$escaped = ob_get_clean();
expectListUi(str_contains($escaped, 'Active &lt;script&gt;') && str_contains($escaped, 'q=A&amp;B') && str_contains($escaped, '&quot;label&quot;'), 'Context labels and URLs are escaped.');
echo "List UI helpers: {$checks} checks passed.\n";
