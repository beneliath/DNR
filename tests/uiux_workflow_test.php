<?php

declare(strict_types=1);
require_once __DIR__ . '/../src/functions.php';
require_once __DIR__ . '/../src/booking_inquiry_helpers.php';
require_once __DIR__ . '/../src/follow_up_task_helpers.php';
require_once __DIR__ . '/../src/financial_report_helpers.php';

function expectUiux(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function expectUiuxInvalid(callable $action, string $message): void {
    try { $action(); } catch (InvalidArgumentException $exception) { return; }
    throw new RuntimeException($message);
}
$tasks = [['id' => 11], ['id' => 12]];
expectUiux(bookingInquirySelectedTaskIds($tasks, [], false) === [11, 12], 'Opening a review defaults to all available tasks');
expectUiux(bookingInquirySelectedTaskIds($tasks, [], true) === [], 'Submitting no checkboxes moves no tasks');
expectUiux(bookingInquirySelectedTaskIds($tasks, ['task_ids' => ['12']], true) === [12], 'One checked task moves only itself');
expectUiux(bookingInquirySelectedTaskIds($tasks, ['task_ids' => ['11', '12']], true) === [11, 12], 'All checked tasks move');
expectUiux(bookingInquirySelectedTaskIds($tasks, ['task_ids' => ['11', '11', '999', []]], true) === [11], 'Duplicate and unavailable task IDs do not alter selected work');
$inquiry = ['next_action' => 'Send venue requirements', 'next_action_due_date' => '2026-09-20'];
expectUiuxInvalid(fn() => bookingInquiryNextActionResolution($inquiry, ''), 'A next action requires a decision');
expectUiuxInvalid(fn() => bookingInquiryNextActionResolution($inquiry, 'resolved'), 'Resolution requires an explanation');
$carried = bookingInquiryNextActionResolution($inquiry, 'carry_forward');
expectUiux(str_contains($carried, $inquiry['next_action']) && str_contains($carried, '2026-09-20'), 'Carry-forward history includes commitment and due date');
$resolved = bookingInquiryNextActionResolution($inquiry, 'resolved', 'Sent while reviewing the booking');
expectUiux(str_contains($resolved, 'Sent while reviewing') && str_contains($resolved, 'resolved at booking'), 'Resolution records the user decision and reason');
expectUiux(bookingInquiryNextActionResolution([], '') === '', 'Inquiries without a next action require no invented decision');
foreach ([
    [[], false, ['scope' => 'mine', 'view' => 'all']],
    [['view' => 'today', 'owner' => 'me'], false, ['scope' => 'mine', 'view' => 'today']],
    [['view' => 'unassigned'], false, ['scope' => 'unassigned', 'view' => 'all']],
    [['view' => 'waiting', 'scope' => 'mine'], false, ['scope' => 'mine', 'view' => 'waiting']],
    [[], true, ['scope' => 'everyone', 'view' => 'all']],
] as [$input, $hasSubject, $expected]) expectUiux(followUpTaskQueueState($input, $hasSubject) === $expected, 'Ownership survives legacy links and view switches');
$draft = normalizeFinancialDraftInput(['giving_income_received' => '12.30', 'lodging_received' => '', 'travel_received' => '0']);
expectUiux($draft['giving_income_received'] === '12.30' && $draft['lodging_received'] === null && $draft['travel_received'] === '0.00', 'Drafts distinguish unknown from confirmed zero');
expectUiux($draft['total_received'] === '12.30', 'Draft total includes entered amounts only');
expectUiux(normalizeFinancialDraftInput([])['giving_income_received'] === null, 'Empty drafts do not invent zero receipts');
expectUiuxInvalid(fn() => normalizeFinancialDraftInput(['giving_income_received' => '-1']), 'Drafts enforce non-negative amounts');
expectUiuxInvalid(fn() => Dnr\Domain\FinancialReportInput::normalize($draft), 'An incomplete draft cannot finalize');
requireFinancialDraftVersion(null, '');
requireFinancialDraftVersion(['updated_at' => '2026-09-20 12:00:00.123456'], '2026-09-20 12:00:00.123456');
expectUiuxInvalid(fn() => requireFinancialDraftVersion(['updated_at' => 'new'], ''), 'A draft created concurrently must not be overwritten');
expectUiuxInvalid(fn() => requireFinancialDraftVersion(['updated_at' => 'new'], 'old'), 'Stale draft edits must not overwrite newer receipts');
expectUiuxInvalid(fn() => requireFinancialDraftVersion(null, 'old'), 'A finalized/deleted draft cannot be recreated from stale edits');
echo "UI/UX workflow tests passed.\n";
