<?php

function expectCreateFormActions($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "Create form actions test failed: {$message}\n");
        exit(1);
    }
}

$modern_styles = file_get_contents(__DIR__ . '/../src/assets/css/modern.css');

foreach (['index.php', 'add_organization.php', 'add_contact.php', 'register.php'] as $page) {
    $source = file_get_contents(__DIR__ . '/../src/' . $page);
    expectCreateFormActions(
        str_contains($source, 'create-form-actions'),
        $page . ' should use the shared create-form action layout.'
    );
}

expectCreateFormActions(
    preg_match('/\.create-form-actions\s*\{[^}]*display:\s*flex\s*!important;[^}]*gap:\s*10px\s*!important;/s', $modern_styles) === 1,
    'Create-form buttons should use the same 10px separation as New Engagement.'
);

echo "Create form action tests passed.\n";
