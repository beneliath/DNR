<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/application_runtime.php';

use Dnr\Service\EngagementUpdatePlan;

function expectEngagementUpdatePlan(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Engagement update plan test failed: {$message}\n");
        exit(1);
    }
}

$current = [
    'organization_id' => 4,
    'event_title' => 'Existing event',
    'event_description' => 'Description',
    'event_start_date' => '2026-09-10',
    'event_end_date' => '2026-09-12',
    'event_type' => 'conference',
    'event_type_other' => '',
    'book_table' => 0,
    'brochures' => 1,
    'caller_name' => 'editor-one',
    'caller_user_id' => 7,
    'confirmation_status' => 'under_review',
    'travel_covered' => 'yes',
    'travel_amount' => '125.00',
    'compensation_type' => 'Honorarium',
    'other_compensation' => '',
    'housing_type' => 'Provided',
    'other_housing' => '',
    'housing_amount' => null,
    'event_address_line_1' => '100 Main Street',
    'event_address_line_2' => '',
    'event_city' => 'Dallas',
    'event_state' => 'TX',
    'event_zipcode' => '75001',
    'event_country' => 'USA',
];

$submitted = $current;
$submitted['travel_amount'] = 125.0;
$unchanged = EngagementUpdatePlan::build($current, $submitted);
expectEngagementUpdatePlan(
    $unchanged['assignments'] === []
        && $unchanged['values'] === []
        && $unchanged['types'] === ''
        && !$unchanged['date_range_changed'],
    'equivalent normalized values should not produce an update.'
);

$submitted['organization_id'] = 8;
$submitted['event_title'] = 'Updated event';
$submitted['event_start_date'] = '2026-09-11';
$submitted['book_table'] = 1;
$submitted['caller_name'] = '';
$submitted['caller_user_id'] = null;
$submitted['travel_amount'] = null;
$submitted['housing_amount'] = 50.5;
$changed = EngagementUpdatePlan::build($current, $submitted);
expectEngagementUpdatePlan(
    $changed['assignments'] === [
        'organization_id = ?',
        'event_title = ?',
        'event_start_date = ?',
        'book_table = ?',
        'caller_name = ?, caller_user_id = ?',
        'travel_amount = ?',
        'housing_amount = ?',
    ],
    'changed fields should retain deterministic SQL assignment order.'
);
expectEngagementUpdatePlan(
    $changed['types'] === 'issisidd'
        && $changed['values'] === [8, 'Updated event', '2026-09-11', 1, '', null, null, 50.5]
        && $changed['date_range_changed'],
    'the plan should preserve binding types, nullable values, and date-range changes.'
);

echo "Engagement update plan tests passed.\n";
