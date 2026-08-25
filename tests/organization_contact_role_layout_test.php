<?php

function expectOrganizationContactRoleLayout(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Organization contact role layout test failed: {$message}\n");
        exit(1);
    }
}

$styles = file_get_contents(__DIR__ . '/../src/assets/css/pages/add_organization.css');

expectOrganizationContactRoleLayout(
    preg_match('/\.role-container\s*\{[^}]*align-items:\s*flex-start;[^}]*gap:\s*20px;/s', $styles) === 1,
    'Role and Describe Other Role should be aligned with a visible gap.'
);

expectOrganizationContactRoleLayout(
    preg_match(
        '/\.role-container \.form-group:first-child,\s*\.role-container \.form-group:last-child\s*\{[^}]*width:\s*200px;[^}]*flex:\s*0 1 200px;/s',
        $styles
    ) === 1,
    'Role and Describe Other Role should use adjacent equal-width controls.'
);

echo "Organization contact role layout tests passed.\n";
