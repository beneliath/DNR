#!/bin/sh
set -eu

case "${MYSQL_DATABASE:-}" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_DATABASE contains unsupported characters" >&2; exit 1 ;;
esac
case "${MYSQL_USER:-}" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_USER contains unsupported characters" >&2; exit 1 ;;
esac

geocoder_user=${MYSQL_GEOCODER_USER:-dnrgeocoder}
mail_ingest_user=${MYSQL_MAIL_INGEST_USER:-dnrmailingest}
mail_dispatch_user=${MYSQL_MAIL_DISPATCH_USER:-dnrmaildispatch}
case "$geocoder_user" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_GEOCODER_USER contains unsupported characters" >&2; exit 1 ;;
esac
case "$mail_ingest_user" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_MAIL_INGEST_USER contains unsupported characters" >&2; exit 1 ;;
esac
case "$mail_dispatch_user" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_MAIL_DISPATCH_USER contains unsupported characters" >&2; exit 1 ;;
esac

root_password=${MYSQL_ROOT_PASSWORD:-}
if [ -n "${MYSQL_ROOT_PASSWORD_FILE:-}" ]; then
    root_password=$(cat "${MYSQL_ROOT_PASSWORD_FILE}")
fi
if [ -z "$root_password" ]; then
    echo "The database root password secret is required" >&2
    exit 1
fi

backup_user=${MYSQL_BACKUP_USER:-dnrbackup}
case "$backup_user" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_BACKUP_USER contains unsupported characters" >&2; exit 1 ;;
esac
backup_password=${MYSQL_BACKUP_PASSWORD:-}
if [ -n "${MYSQL_BACKUP_PASSWORD_FILE:-}" ]; then
    backup_password=$(cat "${MYSQL_BACKUP_PASSWORD_FILE}")
fi
case "$backup_password" in
    ''|*[!A-Za-z0-9]*)
        echo "The backup database password secret must contain only letters and numbers" >&2
        exit 1
        ;;
esac
if [ "${#backup_password}" -lt 32 ]; then
    echo "The backup database password secret must be at least 32 characters" >&2
    exit 1
fi

maintenance_user=${MYSQL_MAINTENANCE_USER:-dnrmaintenance}
case "$maintenance_user" in
    ''|*[!A-Za-z0-9_]*) echo "MYSQL_MAINTENANCE_USER contains unsupported characters" >&2; exit 1 ;;
esac
maintenance_password=${MYSQL_MAINTENANCE_PASSWORD:-}
if [ -n "${MYSQL_MAINTENANCE_PASSWORD_FILE:-}" ]; then
    maintenance_password=$(cat "${MYSQL_MAINTENANCE_PASSWORD_FILE}")
fi
case "$maintenance_password" in
    ''|*[!A-Za-z0-9]*)
        echo "The maintenance password secret must contain only letters and numbers" >&2
        exit 1
        ;;
esac
if [ "${#maintenance_password}" -lt 32 ]; then
    echo "The maintenance password secret must be at least 32 characters" >&2
    exit 1
fi

geocoder_password=${MYSQL_GEOCODER_PASSWORD:-}
if [ -n "${MYSQL_GEOCODER_PASSWORD_FILE:-}" ]; then
    geocoder_password=$(cat "${MYSQL_GEOCODER_PASSWORD_FILE}")
fi
case "$geocoder_password" in
    ''|*[!A-Za-z0-9]*)
        echo "The geocoder password secret must contain only letters and numbers" >&2
        exit 1
        ;;
esac
if [ "${#geocoder_password}" -lt 32 ]; then
    echo "The geocoder password secret must be at least 32 characters" >&2
    exit 1
fi

mail_ingest_password=${MYSQL_MAIL_INGEST_PASSWORD:-}
if [ -n "${MYSQL_MAIL_INGEST_PASSWORD_FILE:-}" ]; then
    mail_ingest_password=$(cat "${MYSQL_MAIL_INGEST_PASSWORD_FILE}")
fi
case "$mail_ingest_password" in
    ''|*[!A-Za-z0-9]*)
        echo "The mail-ingest password secret must contain only letters and numbers" >&2
        exit 1
        ;;
esac
if [ "${#mail_ingest_password}" -lt 32 ]; then
    echo "The mail-ingest password secret must be at least 32 characters" >&2
    exit 1
fi

mail_dispatch_password=${MYSQL_MAIL_DISPATCH_PASSWORD:-}
if [ -n "${MYSQL_MAIL_DISPATCH_PASSWORD_FILE:-}" ]; then
    mail_dispatch_password=$(cat "${MYSQL_MAIL_DISPATCH_PASSWORD_FILE}")
fi
case "$mail_dispatch_password" in
    ''|*[!A-Za-z0-9]*)
        echo "The mail-dispatch password secret must contain only letters and numbers" >&2
        exit 1
        ;;
esac
if [ "${#mail_dispatch_password}" -lt 32 ]; then
    echo "The mail-dispatch password secret must be at least 32 characters" >&2
    exit 1
fi

mysql_admin() {
    if [ -n "${DB_HOST:-}" ]; then
        MYSQL_PWD=$root_password mysql --protocol=tcp -h "$DB_HOST" -uroot "$@"
    else
        MYSQL_PWD=$root_password mysql --protocol=socket -uroot "$@"
    fi
}

mysql_admin <<SQL
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${MYSQL_USER}'@'%';
GRANT SELECT ON \`${MYSQL_DATABASE}\`.schema_migrations TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.users TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.user_email_tokens TO '${MYSQL_USER}'@'%';
GRANT INSERT, UPDATE ON \`${MYSQL_DATABASE}\`.email_outbox TO '${MYSQL_USER}'@'%';
GRANT SELECT (token_id, status) ON \`${MYSQL_DATABASE}\`.email_outbox TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.user_recovery_codes TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT ON \`${MYSQL_DATABASE}\`.security_audit_log TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.authentication_rate_limits TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.inbound_email_messages TO '${MYSQL_USER}'@'%';
GRANT SELECT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.inbound_email_quarantine TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.calendar_subscriptions TO '${MYSQL_USER}'@'%';
GRANT SELECT ON \`${MYSQL_DATABASE}\`.calendar_feed_revision TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.organizations TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.engagements TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.engagement_contacts TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.engagement_financial_reports TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT ON \`${MYSQL_DATABASE}\`.engagement_email_messages TO '${MYSQL_USER}'@'%';
GRANT INSERT, UPDATE ON \`${MYSQL_DATABASE}\`.engagement_email_deliveries TO '${MYSQL_USER}'@'%';
GRANT SELECT (
    id, message_id, contact_id, recipient_name, recipient_email,
    recipient_roles_json, status, attempts, next_attempt_at,
    processing_started_at, sent_at, last_error, created_at, updated_at
) ON \`${MYSQL_DATABASE}\`.engagement_email_deliveries TO '${MYSQL_USER}'@'%';
GRANT SELECT ON \`${MYSQL_DATABASE}\`.engagement_map_geocodes TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE ON \`${MYSQL_DATABASE}\`.engagement_map_geocode_queue TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.engagement_chron_entries TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.contact_chron_entries TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.organization_chron_entries TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.presentations TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.contacts TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.follow_up_tasks TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.standard_event_tasks TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.mattermost_link_codes TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.mattermost_user_links TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.mattermost_idempotency_keys TO '${MYSQL_USER}'@'%';
GRANT SELECT, INSERT, UPDATE ON \`${MYSQL_DATABASE}\`.mattermost_reply_notifications TO '${MYSQL_USER}'@'%';
GRANT EXECUTE ON PROCEDURE \`${MYSQL_DATABASE}\`.prune_security_audit_log TO '${MYSQL_USER}'@'%';

CREATE USER IF NOT EXISTS '${backup_user}'@'%' IDENTIFIED BY '${backup_password}';
ALTER USER '${backup_user}'@'%' IDENTIFIED BY '${backup_password}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${backup_user}'@'%';
GRANT SELECT ON \`${MYSQL_DATABASE}\`.* TO '${backup_user}'@'%';

CREATE USER IF NOT EXISTS '${geocoder_user}'@'%' IDENTIFIED BY '${geocoder_password}';
ALTER USER '${geocoder_user}'@'%' IDENTIFIED BY '${geocoder_password}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${geocoder_user}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.engagement_map_geocodes TO '${geocoder_user}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.engagement_map_geocode_queue TO '${geocoder_user}'@'%';

CREATE USER IF NOT EXISTS '${mail_ingest_user}'@'%' IDENTIFIED BY '${mail_ingest_password}';
ALTER USER '${mail_ingest_user}'@'%' IDENTIFIED BY '${mail_ingest_password}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${mail_ingest_user}'@'%';
GRANT SELECT ON \`${MYSQL_DATABASE}\`.users TO '${mail_ingest_user}'@'%';
GRANT SELECT ON \`${MYSQL_DATABASE}\`.contacts TO '${mail_ingest_user}'@'%';
GRANT SELECT ON \`${MYSQL_DATABASE}\`.organizations TO '${mail_ingest_user}'@'%';
GRANT SELECT ON \`${MYSQL_DATABASE}\`.engagements TO '${mail_ingest_user}'@'%';
GRANT SELECT, INSERT, UPDATE ON \`${MYSQL_DATABASE}\`.inbound_email_messages TO '${mail_ingest_user}'@'%';
GRANT INSERT, UPDATE ON \`${MYSQL_DATABASE}\`.inbound_email_quarantine TO '${mail_ingest_user}'@'%';
GRANT SELECT, INSERT ON \`${MYSQL_DATABASE}\`.engagement_chron_entries TO '${mail_ingest_user}'@'%';
GRANT SELECT, INSERT ON \`${MYSQL_DATABASE}\`.contact_chron_entries TO '${mail_ingest_user}'@'%';
GRANT SELECT, INSERT ON \`${MYSQL_DATABASE}\`.organization_chron_entries TO '${mail_ingest_user}'@'%';
GRANT SELECT ON \`${MYSQL_DATABASE}\`.engagement_email_messages TO '${mail_ingest_user}'@'%';
GRANT SELECT ON \`${MYSQL_DATABASE}\`.engagement_email_deliveries TO '${mail_ingest_user}'@'%';
GRANT SELECT ON \`${MYSQL_DATABASE}\`.mattermost_user_links TO '${mail_ingest_user}'@'%';
GRANT INSERT ON \`${MYSQL_DATABASE}\`.mattermost_reply_notifications TO '${mail_ingest_user}'@'%';

CREATE USER IF NOT EXISTS '${mail_dispatch_user}'@'%' IDENTIFIED BY '${mail_dispatch_password}';
ALTER USER '${mail_dispatch_user}'@'%' IDENTIFIED BY '${mail_dispatch_password}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${mail_dispatch_user}'@'%';
GRANT SELECT, UPDATE ON \`${MYSQL_DATABASE}\`.email_outbox TO '${mail_dispatch_user}'@'%';
GRANT SELECT, UPDATE ON \`${MYSQL_DATABASE}\`.user_email_tokens TO '${mail_dispatch_user}'@'%';
GRANT SELECT, INSERT, UPDATE ON \`${MYSQL_DATABASE}\`.notification_outbox TO '${mail_dispatch_user}'@'%';
GRANT SELECT, UPDATE ON \`${MYSQL_DATABASE}\`.engagement_email_deliveries TO '${mail_dispatch_user}'@'%';
GRANT SELECT (
    id, username, first_name, email, email_verified_at, role,
    auth_version, account_status, task_digest_enabled,
    task_digest_time, task_digest_days
) ON \`${MYSQL_DATABASE}\`.users TO '${mail_dispatch_user}'@'%';
GRANT SELECT (
    id, title, status, priority, due_date, waiting_on, assigned_to
) ON \`${MYSQL_DATABASE}\`.follow_up_tasks TO '${mail_dispatch_user}'@'%';
GRANT SELECT (
    id, organization_id, event_title, event_end_date, is_deleted, lifecycle_status
) ON \`${MYSQL_DATABASE}\`.engagements TO '${mail_dispatch_user}'@'%';
GRANT SELECT (id, organization_name, is_deleted)
    ON \`${MYSQL_DATABASE}\`.organizations TO '${mail_dispatch_user}'@'%';
GRANT SELECT (engagement_id)
    ON \`${MYSQL_DATABASE}\`.engagement_financial_reports TO '${mail_dispatch_user}'@'%';

CREATE USER IF NOT EXISTS '${maintenance_user}'@'%' IDENTIFIED BY '${maintenance_password}';
ALTER USER '${maintenance_user}'@'%' IDENTIFIED BY '${maintenance_password}';
REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${maintenance_user}'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`${MYSQL_DATABASE}\`.* TO '${maintenance_user}'@'%';
GRANT EXECUTE ON PROCEDURE \`${MYSQL_DATABASE}\`.prune_security_audit_log TO '${maintenance_user}'@'%';
GRANT EXECUTE ON PROCEDURE \`${MYSQL_DATABASE}\`.prune_operational_mail_history TO '${maintenance_user}'@'%';
FLUSH PRIVILEGES;
SQL
