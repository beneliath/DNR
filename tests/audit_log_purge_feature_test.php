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
$prune_wrapper = file_get_contents(__DIR__ . '/../scripts/prune_audit_log.sh');
$prune_command = file_get_contents(__DIR__ . '/../scripts/prune_audit_log.php');
$grants = file_get_contents(__DIR__ . '/../scripts/configure_database_privileges.sh');
$dockerfile = file_get_contents(__DIR__ . '/../Dockerfile');
$manual = file_get_contents(__DIR__ . '/../src/help.php');
$readme = file_get_contents(__DIR__ . '/../README.md');
$migration = file_get_contents(__DIR__ . '/../migrations/20260830_add_audit_log_pruning.sql');
$migration_order = file_get_contents(__DIR__ . '/../migrations/order.txt');

expectAuditLogPurgeFeature(
    str_contains($page, 'requireAdmin();')
        && str_contains($page, "\$_SERVER['REQUEST_METHOD'] === 'POST'")
        && str_contains($page, 'requireValidCsrfToken();')
        && str_contains($page, 'requireRecentAdminElevation($return_url);')
        && str_contains($page, "\$_SESSION['_audit_log_prune_preview']")
        && str_contains($page, 'Preview this retention period before pruning.')
        && str_contains($page, "hash_equals('PRUNE', \$confirmation)")
        && str_contains($page, 'CALL prune_security_audit_log(?, ?, ?, ?)')
        && !str_contains($page, 'DELETE FROM security_audit_log')
        && str_contains($page, 'append-only')
        && str_contains($page, 'id="audit-retention"')
        && str_contains($page, 'Preview Pruning')
        && str_contains($page, 'Unlock Pruning'),
    'the web audit log should provide a CSRF-protected, elevated, explicitly confirmed pruning workflow without direct delete SQL.'
);
expectAuditLogPurgeFeature(
    str_contains($page, 'class="list-search-form audit-filter-form"')
        && str_contains($page, 'class="page-intro audit-retention-note"')
        && str_contains($page, 'class="audit-search-field"')
        && str_contains($styles, 'align-self: flex-end;')
        && str_contains($styles, 'flex: 0 0 auto !important;')
        && str_contains($styles, 'height: 44px !important;')
        && str_contains($styles, 'margin: 0 !important;')
        && str_contains($styles, '.audit-retention-card')
        && str_contains($styles, '.audit-retention-preview')
        && str_contains($styles, '.audit-retention-confirm-row')
        && preg_match('/\\.audit-retention-note\\s*\\{[^}]*margin-bottom:\\s*24px;/s', $styles) === 1,
    'audit controls and the retention preview should have responsive, clearly separated layouts.'
);
expectAuditLogPurgeFeature(
    str_contains($migration, 'CREATE PROCEDURE prune_security_audit_log')
        && str_contains($migration, 'SQL SECURITY DEFINER')
        && str_contains($migration, 'p_retention_days < 1 OR p_retention_days > 36500')
        && str_contains($migration, "GET_LOCK('dnr_audit_log_prune', 0)")
        && str_contains($migration, 'START TRANSACTION')
        && str_contains($migration, 'DELETE FROM security_audit_log')
        && str_contains($migration, "'audit_log_pruned'")
        && str_contains($migration, 'ROLLBACK')
        && str_contains($migration, 'COMMIT')
        && str_contains($migration_order, '20260830_add_audit_log_pruning.sql'),
    'the UI should prune through a serialized, range-limited, atomic definer procedure installed by a new migration.'
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
    str_contains($prune_wrapper, 'DAYS_TO_KEEP [PRUNE]')
        && str_contains($prune_wrapper, 'docker compose run --rm --no-deps --entrypoint php maintenance')
        && str_contains($prune_wrapper, '/opt/dnr/bin/prune_audit_log.php')
        && str_contains($dockerfile, 'scripts/prune_audit_log.php /opt/dnr/bin/prune_audit_log.php'),
    'retention pruning should be a one-command deployment-host operation using the isolated maintenance identity.'
);
expectAuditLogPurgeFeature(
    str_contains($prune_command, "\$confirmation !== 'PRUNE'")
        && str_contains($prune_command, 'Preview: %d audit %s older than %s UTC')
        && str_contains($prune_command, "SELECT GET_LOCK('dnr_audit_log_prune', 0)")
        && str_contains($prune_command, 'begin_transaction()')
        && str_contains($prune_command, 'DELETE FROM security_audit_log WHERE created_at < ?')
        && str_contains($prune_command, "'audit_log_pruned'")
        && str_contains($prune_command, 'commit()')
        && str_contains($prune_command, 'rollback()'),
    'pruning must preview by default, require explicit confirmation, serialize runs, and atomically retain its maintenance event.'
);
expectAuditLogPurgeFeature(
    str_contains($page, "'audit_log_pruned' => 'Audit log pruned'")
        && str_contains($manual, 'In the Retention panel')
        && str_contains($manual, './scripts/prune_audit_log.sh DAYS')
        && str_contains($readme, '### Audit-log retention')
        && str_contains($readme, '**Users → Audit Log**')
        && str_contains($readme, './scripts/prune_audit_log.sh 365 PRUNE'),
    'the Audit Log, user manual, and deployment guide should explain and label both UI and terminal pruning.'
);
expectAuditLogPurgeFeature(
    str_contains($grants, 'GRANT SELECT, INSERT ON \`${MYSQL_DATABASE}\`.security_audit_log')
        && str_contains($grants, 'GRANT EXECUTE ON PROCEDURE \`${MYSQL_DATABASE}\`.prune_security_audit_log')
        && !str_contains($grants, 'UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.security_audit_log'),
    'the runtime database user must retain append-only table access and receive only procedure execution for pruning.'
);

echo "Audit log purge feature tests passed.\n";
