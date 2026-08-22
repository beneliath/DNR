<?php

function expectEventDescriptionFeature($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$init = file_get_contents(__DIR__ . '/../init.sql');
$migration = file_get_contents(__DIR__ . '/../migrations/20260817_add_event_description.sql');
$create_engagement = file_get_contents(__DIR__ . '/../src/index.php');
$edit_engagement = file_get_contents(__DIR__ . '/../src/edit_engagement.php');
$engagement_update_plan = file_get_contents(__DIR__ . '/../src/app/Service/EngagementUpdatePlan.php');
$view_engagement = file_get_contents(__DIR__ . '/../src/view_engagement.php');

expectEventDescriptionFeature(
    str_contains($init, 'event_description TEXT')
        && str_contains($migration, 'ADD COLUMN event_description TEXT'),
    'the schema and upgrade migration must include the event description.'
);
expectEventDescriptionFeature(
    str_contains($create_engagement, 'name="event_description"')
        && str_contains($create_engagement, 'organization_id, event_title, event_description'),
    'new engagements must accept and save an event description.'
);
expectEventDescriptionFeature(
    str_contains($edit_engagement, 'name="event_description"')
        && str_contains($edit_engagement, 'EngagementUpdatePlan::build')
        && str_contains($engagement_update_plan, "'event_description'"),
    'existing engagements must display and save an event description.'
);
expectEventDescriptionFeature(
    str_contains($view_engagement, "nl2br(htmlspecialchars(\$engagement['event_description']))"),
    'engagement details must safely preserve description line breaks.'
);

echo "Event description feature tests passed.\n";
