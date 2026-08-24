<?php

function expectAuditLogPurgeFeature($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Audit log purge feature test failed: {$message}\n");
        exit(1);
    }
}

$page = file_get_contents(__DIR__ . '/../src/audit_log.php');
$styles = file_get_contents(__DIR__ . '/../src/assets/css/pages/audit_log.css');
$purge = file_get_contents(__DIR__ . '/../scripts/purge_audit_log.sh');
$grants = file_get_contents(__DIR__ . '/../scripts/configure_database_privileges.sh');

expectAuditLogPurgeFeature(
    str_contains($page, 'requireAdmin();')
        && !str_contains($page, "\$_SERVER['REQUEST_METHOD'] === 'POST'")
        && !str_contains($page, 'DELETE FROM security_audit_log')
        && str_contains($page, 'append-only'),
    'the web audit log must be read-only and explain its append-only boundary.'
);
expectAuditLogPurgeFeature(
    str_contains($page, 'class="list-search-form audit-filter-form"')
        && str_contains($page, 'class="audit-search-field"')
        && str_contains($styles, 'align-self: flex-end;')
        && str_contains($styles, 'flex: 0 0 auto !important;')
        && str_contains($styles, 'height: 44px !important;')
        && str_contains($styles, 'margin: 0 !important;'),
    'audit controls must size to their content, align vertically, and anchor category selectors at the bottom right.'
);
expectAuditLogPurgeFeature(
    str_contains($purge, '"${1:-}" != "PURGE"')
        && str_contains($purge, 'START TRANSACTION')
        && str_contains($purge, 'DELETE FROM security_audit_log')
        && str_contains($purge, "'audit_log_purged'")
        && str_contains($purge, 'COMMIT'),
    'purging must require a local literal confirmation and append a replacement maintenance event atomically.'
);
expectAuditLogPurgeFeature(
    str_contains($grants, 'GRANT SELECT, INSERT ON \`${MYSQL_DATABASE}\`.security_audit_log')
        && !str_contains($grants, 'UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.security_audit_log'),
    'the runtime database user must be unable to alter or delete audit entries.'
);

echo "Audit log purge feature tests passed.\n";
