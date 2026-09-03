<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/financial_report_helpers.php';

function expectFinancialTracking(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Financial tracking feature test failed: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/migrations/20260823_add_engagement_financial_reports.sql');
$migrationOrder = file_get_contents($root . '/migrations/order.txt');
$privileges = file_get_contents($root . '/scripts/configure_database_privileges.sh');
$closeout = file_get_contents($root . '/src/close_engagement.php');
$engagementView = file_get_contents($root . '/src/view_engagement.php');
$engagementList = file_get_contents($root . '/src/engagements.php');
$organizationView = file_get_contents($root . '/src/view_organization.php');
$organizationList = file_get_contents($root . '/src/organizations.php');
$helpers = file_get_contents($root . '/src/financial_report_helpers.php');
$correctionPosition = is_string($closeout)
    ? strpos($closeout, 'UPDATE engagement_financial_reports')
    : false;
$taskHoldPosition = is_string($closeout)
    ? strpos($closeout, 'fetchEngagementCloseoutTaskReadiness(')
    : false;
$initialClosePosition = is_string($closeout)
    ? strpos($closeout, 'INSERT INTO engagement_financial_reports')
    : false;

expectFinancialTracking(
    is_string($migration)
        && str_contains($migration, 'CREATE TABLE engagement_financial_reports')
        && str_contains($migration, 'engagement_id INT PRIMARY KEY')
        && substr_count($migration, 'DECIMAL(12,2) NOT NULL') === 3
        && str_contains($migration, 'FOREIGN KEY (engagement_id) REFERENCES engagements(id) ON DELETE CASCADE')
        && str_contains($migration, 'CHECK (giving_income_received >= 0)')
        && str_contains($migration, 'audit_engagement_financial_reports_after_insert')
        && str_contains($migration, 'audit_engagement_financial_reports_after_update'),
    'one audited, constrained final report should belong to each engagement.'
);
expectFinancialTracking(
    is_string($migrationOrder)
        && str_contains($migrationOrder, '20260823_add_engagement_financial_reports.sql')
        && is_string($privileges)
        && str_contains($privileges, 'engagement_financial_reports'),
    'deployment should apply the financial migration and grant the app explicit table access.'
);

expectFinancialTracking(
    is_string($closeout)
        && str_contains($closeout, 'requireValidCsrfToken()')
        && substr_count($closeout, 'FOR UPDATE') >= 2
        && str_contains($closeout, 'hash_equals(')
        && str_contains($closeout, 'confirm_final')
        && str_contains($closeout, 'INSERT INTO engagement_financial_reports')
        && str_contains($closeout, 'UPDATE engagement_financial_reports')
        && str_contains($closeout, "in_array(\$user_role, ['admin', 'editor'], true)"),
    'event closeout should be editor-protected, explicit, CSRF-safe, serialized, and optimistic-lock aware.'
);

expectFinancialTracking(
    is_string($helpers)
        && str_contains($helpers, 'function fetchEngagementCloseoutTaskReadiness(')
        && str_contains($helpers, 'AND is_archived = 0')
        && str_contains($helpers, 'AND due_date <= ?')
        && str_contains($helpers, "AND status <> 'completed'")
        && str_contains($closeout, 'engagementCloseoutTaskHoldMessage(')
        && str_contains($closeout, 'if (!$closeout_is_held)')
        && $correctionPosition !== false
        && $taskHoldPosition !== false
        && $initialClosePosition !== false
        && $correctionPosition < $taskHoldPosition
        && $taskHoldPosition < $initialClosePosition,
    'initial closeout should be held by every non-completed task due through the last active presentation without blocking corrections.'
);

expectFinancialTracking(
    is_string($helpers)
        && str_contains($helpers, 'SUM(report.giving_income_received) AS lifetime_giving')
        && str_contains($helpers, 'AVG(report.giving_income_received) AS average_event_giving')
        && str_contains($helpers, 'PARTITION BY engagement.organization_id')
        && str_contains($helpers, 'ORDER BY engagement.event_end_date DESC')
        && !str_contains($helpers, 'WHERE engagement.is_deleted = 0'),
    'organization totals should include finalized archived history and select last giving by event date.'
);

expectFinancialTracking(
    is_string($engagementView)
        && str_contains($engagementView, 'id="financial-closeout"')
        && str_contains($engagementView, 'Giving / income')
        && str_contains($engagementView, 'Total received')
        && str_contains($engagementView, 'close_engagement.php?id=')
        && is_string($engagementList)
        && str_contains($engagementList, 'AS financially_closed')
        && str_contains($engagementList, '<th>Closeout</th>')
        && str_contains($engagementList, 'event-closeout-badge')
        && str_contains($engagementList, "'Not applicable'")
        && str_contains($engagementList, "? 'Closed'")
        && is_string($organizationView)
        && str_contains($organizationView, 'Lifetime giving')
        && str_contains($organizationView, 'Last event giving')
        && str_contains($organizationView, 'Average event giving')
        && str_contains($organizationView, 'financial-history-table')
        && is_string($organizationList)
        && str_contains($organizationList, '<th>Last Giving</th>')
        && str_contains($organizationList, '<th>Lifetime Giving</th>'),
    'event and organization screens should expose the final report and required giving metrics.'
);

expectFinancialTracking(
    financialReportTotal([
        'giving_income_received' => '100.10',
        'lodging_received' => '20.20',
        'travel_received' => '3.03',
    ]) === '123.33',
    'display totals should use the same exact fixed-precision arithmetic as input normalization.'
);
expectFinancialTracking(
    formatFinancialAmount('9999999999.99') === '$9,999,999,999.99'
        && formatFinancialAmount('133.335000') === '$133.34',
    'financial display should preserve large exact decimals and round database averages to cents without floats.'
);

echo "Financial tracking feature tests passed.\n";
