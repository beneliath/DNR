<?php

function expectAuditLogPurgeFeature($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "Audit log purge feature test failed: {$message}\n");
        exit(1);
    }
}

$page = file_get_contents(__DIR__ . '/../src/audit_log.php');

expectAuditLogPurgeFeature(
    str_contains($page, 'requireAdmin();')
        && str_contains($page, "\$_SERVER['REQUEST_METHOD'] === 'POST'")
        && str_contains($page, 'requireValidCsrfToken();')
        && str_contains($page, "\$action !== 'purge'")
        && str_contains($page, "\$confirmation !== 'PURGE'"),
    'purging must be restricted to an authenticated administrator using a validated POST request and explicit confirmation.'
);
expectAuditLogPurgeFeature(
    str_contains($page, "DELETE FROM security_audit_log")
        && str_contains($page, 'begin_transaction()')
        && str_contains($page, 'recordAuditEvent($conn')
        && str_contains($page, "'event_type' => 'audit_log_purged'")
        && str_contains($page, '$conn->commit()')
        && str_contains($page, '$conn->rollback()'),
    'purging must be atomic and retain a security event identifying that the prior entries were deleted.'
);
expectAuditLogPurgeFeature(
    str_contains($page, 'id="open-purge-audit-log"')
        && str_contains($page, 'id="purge-audit-log-confirmation"')
        && str_contains($page, 'name="purge_confirmation"')
        && str_contains($page, 'confirmationInput.value !== \'PURGE\''),
    'the Audit Log page should expose a clearly confirmed purge action.'
);

echo "Audit log purge feature tests passed.\n";
