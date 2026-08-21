<?php

function expectAuditLogPurgeFeature($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Audit log purge feature test failed: {$message}\n");
        exit(1);
    }
}

$page = file_get_contents(__DIR__ . '/../src/audit_log.php');
$purge = file_get_contents(__DIR__ . '/../scripts/purge_audit_log.sh');
$grants = file_get_contents(__DIR__ . '/../operations/restrict_app_database_user.sql');

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
        && str_contains($page, 'align-self: flex-end;')
        && str_contains($page, 'flex: 0 0 auto !important;')
        && str_contains($page, 'height: 44px !important;')
        && str_contains($page, 'margin: 0 !important;'),
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
    str_contains($grants, 'GRANT SELECT, INSERT ON dnr.security_audit_log')
        && !str_contains($grants, 'UPDATE, DELETE ON dnr.security_audit_log'),
    'the runtime database user must be unable to alter or delete audit entries.'
);

echo "Audit log purge feature tests passed.\n";
