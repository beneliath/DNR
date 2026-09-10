<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/email_template_helpers.php';
function expectEmailTemplate(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('Email template test failed: ' . $message);
}
$input = ['name' => 'Welcome', 'subject_template' => 'Welcome to {{event_name}}',
    'body_template' => "Hello,\r\n\r\nSee you on {{ event_dates }}.\r\n{{presentation_schedule}}",
    'suggested_roles' => ['primary_host', 'travel', 'primary_host'], 'sort_order' => '20'];
$normalized = normalizeEmailMessageTemplateInput($input);
expectEmailTemplate($normalized['suggested_roles'] === ['primary_host', 'travel']
    && $normalized['sort_order'] === 20 && !str_contains($normalized['body_template'], "\r"),
    'input should normalize line endings, ordering, and duplicate contact suggestions');
$values = ['event_name' => 'Literal {{event_dates}}', 'event_dates' => "October 10\nOctober 11"];
expectEmailTemplate(renderEmailMessageTemplateText('{{event_name}} on {{ event_dates }}', $values)
    === "Literal {{event_dates}} on October 10\nOctober 11", 'event values must be substituted exactly once');
expectEmailTemplate(renderEmailMessageTemplateText('Dates: {{event_dates}}', $values, true)
    === 'Dates: October 10 October 11', 'subject fields should not introduce header line breaks');
foreach ([
    ['name' => ''], ['name' => str_repeat('x', 101)], ['name' => ['bad']],
    ['subject_template' => "Subject\r\nBcc: hidden@example.test"],
    ['subject_template' => '{{presentation_schedule}}'],
    ['body_template' => '{{unknown_field}}'], ['body_template' => '{{event_name}'],
    ['body_template' => 'event_name}}'], ['body_template' => '{{event_name | unsafe}}'],
    ['body_template' => ''], ['body_template' => str_repeat('x', 100001)],
    ['suggested_roles' => ['speaker']], ['suggested_roles' => 'primary_host'],
    ['sort_order' => '-1'], ['sort_order' => '65536'], ['sort_order' => []],
] as $change) {
    try {
        normalizeEmailMessageTemplateInput(array_replace($input, $change));
        expectEmailTemplate(false, 'invalid template input must be rejected');
    } catch (InvalidArgumentException) {
        // Expected.
    }
}
echo "Email template helper tests passed.\n";
