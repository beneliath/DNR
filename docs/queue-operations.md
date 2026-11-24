# Background jobs and queue operations

Each account runs the same `audience-jobs` service against its own database. It
processes imports, exports, and bulk connection changes without an open browser.
The browser polls progress; the legacy `process_job` POST only acknowledges the
job. Each pass processes at most ten jobs, with forty rows per transaction.

Workers claim a job using `FOR UPDATE SKIP LOCKED` inside the batch transaction.
Concurrent workers skip an occupied claim. A stopped process loses its database
connection, rolling back the unfinished batch and releasing the claim. Committed
rows survive and are excluded from subsequent batches. Cancellation takes effect
between batches. A user must still be active and authorized when each batch runs;
deletes require an administrator. Resuming a failed job assigns the remaining
work to the resuming user, and a delete resume requires Admin Unlock again.

The worker uses the existing application database grants and only the private
backend network. There are no schema changes, additional static addresses, or
new outbound mail credentials. Compose, production account provisioning, local
provisioning, and release writer shutdown all include the new service. Existing
deployments must apply the current grant script (queue timestamp metadata only)
and start `audience-jobs` with the same qualified application image as their web
service. Source-bound local accounts use `update_local_development.py --apply`
for grant synchronization; check all accounts afterward with `--check`.

## Read the outcomes

The administrator's Operations page reads fresh queue outcomes directly from
that account's database. It covers account mail, task digests, correspondence,
reimbursement mail, MessageMonkey service mail, campaign recipients, bulk jobs,
and automation runs. The CLI equivalent is:

```sh
docker compose exec -T -u www-data web php /opt/dnr/bin/report_queue_health.php
```

Exit codes are 0 for healthy, 1 for attention required, and 2 for unavailable.
The JSON contains counts and fixed recovery guidance, without recipients,
message bodies, credentials, or encrypted payloads.

- Waiting and retry counts include only work due now. Future schedules and
  paused campaigns/automations do not trigger overdue warnings.
- Bulk jobs trigger attention after five minutes waiting or without progress.
- Due mail/automation work and processing delivery claims trigger attention
  after fifteen minutes. Check the worker, database, provider limits, and budget.
- Failures and completed jobs with row errors cover the previous 24 hours.
  Review their error details before retrying or importing corrected rows.
- Uncertain deliveries remain visible until resolved. Check provider receipts
  before any resend. The monitor never retries a delivery.

Worker container health continues to measure liveness. Successful heartbeats
cannot hide these separate delivery failures or stalled-job warnings.

## External alerts

`scripts/queue_monitor.py` discovers all active accounts through the primary directory,
checks each account independently, verifies report identity, and sends alerts
directly through the existing verified-TLS SMTP connection. It does not enqueue
alerts in the application. Missing accounts, unavailable services, and query
failures produce attention reports. Disabled and not-yet-provisioned accounts
are excluded without suppressing checks for active accounts. Backup coverage
retains its existing stricter discovery policy. A private SMTP cache allows alerts when the
application mail worker is stopped. Docker metadata probes time out after thirty
seconds so an unresponsive engine cannot indefinitely prevent the alert or its
cached SMTP fallback.

Alerts identify the account, affected queue, counts, and recovery action. A new
condition alerts immediately; unchanged failures repeat after six hours; a
cleared condition sends one recovery notice. Alert state is saved only after
SMTP acceptance, independently for each account. The default recipient is the
existing recovery-monitor administrator; `--recipient` overrides it.

For an authorized deployment, install the reviewed helper bundle containing
`queue_monitor.py`, `install_queue_monitor.py`, and their existing recovery helper
dependencies at `/home/dgilmore/.local/lib/moed-recovery/v1/`. Then:

```sh
python3 ~/.local/lib/moed-recovery/v1/queue_monitor.py --check-only
python3 ~/.local/lib/moed-recovery/v1/install_queue_monitor.py
```

The installer preserves unrelated cron entries, saves the previous schedule,
and adds a five-minute check. State is private under `~/.local/state/moed-queues/`;
syslog uses `moed-queue-monitor`. `--check-only` never sends mail or writes alert
state. Source changes alone do not install this production schedule.

[Gatus monitoring](gatus-monitoring.md) covers the deployed workers and host
jobs, automatically enrolls newly deployed containers, and activates its queue
outcome check when this independent monitor is scheduled. A missing first report
then fails the check rather than leaving an installed monitor unobserved.

## Focused verification

`python3 scripts/integration_environment.py queue-workers` creates an isolated
database and verifies bounded processing, concurrent claims, cancellation,
permission revocation, forced process termination and recovery, queue metrics
under actual web grants, and HTTP imports/exports without a processing POST.
External-alert tests mock SMTP and exercise deduplication, recovery, discovery
failures, identity mismatches, and per-account acknowledgement. No live messages
are sent by these tests.
