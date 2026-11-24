<?php

function expectCreateFormActions($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Create form actions test failed: {$message}\n");
        exit(1);
    }
}

$modern_styles = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');

foreach (['add_organization.php', 'add_contact.php', 'register.php'] as $page) {
    $source = file_get_contents(__DIR__ . '/../src/' . $page);
    expectCreateFormActions(
        str_contains($source, 'create-form-actions'),
        $page . ' should use the shared create-form action layout.'
    );
}

$new_engagement = file_get_contents(__DIR__ . '/../src/index.php');
$lifecycle_toolbar = file_get_contents(__DIR__ . '/../src/templates/engagement_lifecycle_form.php');
expectCreateFormActions(
    str_contains($new_engagement, 'templates/engagement_lifecycle_form.php')
        && str_contains($lifecycle_toolbar, 'engagement-lifecycle-actions')
        && str_contains($lifecycle_toolbar, 'value="Create Engagement"'),
    'New Engagement should use the shared lifecycle toolbar for its create action.'
);

expectCreateFormActions(
    preg_match('/\.create-form-actions\s*\{[^}]*display:\s*flex\s*!important;[^}]*gap:\s*10px\s+16px\s*!important;/s', $modern_styles) === 1,
    'Create-form buttons should keep 10px between wrapped rows and 16px between neighboring buttons.'
);

echo "Create form action tests passed.\n";
