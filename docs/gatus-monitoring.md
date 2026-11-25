# MOED monitoring in Gatus

The s1 Gatus collector checks every deployed long-running MOED container once
per minute, including individual AI Coach replicas and each Account's private
workers. It also checks the host provisioning worker, verified NAS recovery,
nightly Test Account refresh, deployment retention, and the cron scheduler.
The existing public MOED HTTPS check continues to cover the public route.

The collector uses Gatus [external endpoints](https://github.com/TwiN/gatus#external-endpoints).
Each endpoint expects an update within three minutes. Missing, stopped,
unhealthy, or unchecked containers fail; a running process alone does not pass.
The MOED checks use the existing Discord, email, and Mattermost connections,
with three failing checks before notification and two successful checks before
recovery notification. The deployment incident alerts on its first failure.
Push tokens and notification credentials stay private
on s1 and are never stored in this repository.

## Host checks

| Check | Required evidence |
| --- | --- |
| Account provisioning | Active systemd user service and a successful heartbeat within three minutes |
| NAS recovery | Verified copies for every expected Account, no reported failure, and all snapshots younger than 270 minutes |
| Test Account refresh | Successful completed refresh within 27 hours, allowing the daylight-saving transition |
| Deployment retention | Active timer, no current service failure, and the latest oneshot run confirmed successful in the persistent systemd journal within 27 hours |
| Scheduler | Active cron service and entries for backups, recovery alerts, nightly refresh, and Gatus collection |
| Queue outcomes | Once installed: recent independent queue-monitor report, with every Account healthy; stale after 15 minutes |
| Deployment | Valid host deployment state, live deployment lock during maintenance, and recovery within 15 minutes |

Checks only read operational state. They do not refresh data, run backups, retry
mail, start workers, or change application releases. User-service checks supply
the systemd user-bus environment that cron normally lacks.

Deployment retention reads systemd's structured job events across boots. A host
restart clears the service's in-memory completion timestamp, but the persistent
journal still proves when the job finished. A timer's last trigger is not proof
of success. A newer failed, skipped, or unfinished run prevents an older success
from passing; missing or unreadable journal evidence also fails the check. The
probe requires persistent user journals and never reruns retention to clear an
alert. See systemd's [journalctl documentation](https://www.freedesktop.org/software/systemd/man/latest/journalctl.html)
and [job event definitions](https://github.com/systemd/systemd/blob/main/src/systemd/sd-messages.h).

## New services and releases

`scripts/gatus_moed.py` augments the existing collector. Every minute it discovers
long-running containers in the `moed` and `moed-account-*` Compose projects and
adds missing checks. Names must start with their Compose project's stable prefix.
One-off jobs, completed migration containers, and temporary `<id>_<name>`
replacement names are excluded. An existing stable check remains active when
its container disappears during replacement.
Existing unrelated Gatus endpoints, tokens, connections, and schedules are preserved.

The primary and Test Account `audience-jobs` checks are preconfigured and disabled
until their first production deployment. The queue-outcome check is similarly
staged until the independent queue monitor is scheduled or publishes a report. This
keeps unreleased features from appearing as production outages. Once a check has
activated, disappearance remains a failure; it never silently returns to the
pending state. Deliberate Account retirement must include explicit retirement
of its monitoring targets after the application lifecycle work is verified.

Queue failures in Gatus identify the affected Account and queue, with uncertain,
failed/partial (last 24 hours), stalled, and overdue counts. Missing or stale
monitor reports have separate explanations. Delivery payloads, addresses, and
raw exception text are excluded; large reports explicitly direct operators to
the Account Operations pages for remaining issues.

The queue's Mattermost alert uses Gatus's supported custom HTTP template with
`[RESULT_ERRORS]`, because the native Mattermost provider omits external-check
errors. It reuses the existing webhook, channel, client settings, mentions, and
alert thresholds. Discord and email alerts remain intact; the independent queue
monitor also emails full per-Account details. The custom template is static, so
changing counts do not reload Gatus or reset notification state. When changing
Mattermost connection settings, also update the queue and deployment alerts'
`provider-override`.

## Deployment notifications

The collector reads the host-owned deployment notice and release record every
minute. Suppression begins in the `deploying` phase. Preparation and the save
countdown retain normal alerting. A recent baseline of healthy checks limits
suppression to previously healthy MOED containers, public availability, and the
account worker. Existing failures and unrelated checks continue alerting.

Planned maintenance has a fixed 15-minute deadline. Gatus keeps recording real
failed checks while per-endpoint maintenance windows suppress their notifications.
After successful deployment, up to two minutes within that same deadline allow
fresh recovery samples. The collector removes its windows when recovery finishes.
The separate `moed-tls` check retains certificate warnings throughout maintenance.
The public HTTP check runs every minute with at least three failures before
notification, allowing discovery and Gatus's reload to finish when deployment
starts between scheduled checks. Longer existing failure thresholds are retained.

A failed deployment, a released deployment lock before completion, or an expired
deadline triggers `moed-host-deployment` on its first failing sample. Expected
component failures are grouped under that incident, with service names in the
notification. The deployment incident remains open until recovery is verified.
It sends reminders every 30 minutes and one recovery notification after two
successful samples. Independent failures keep their ordinary alerts. A missing
collector heartbeat also triggers this check, including if the deployment
process or collector crashes.

`deployment-alert-state.json` stores the baseline and ownership of temporary
windows and alert flags. Config updates use a recovery journal so an interrupted
write can be completed on the next cycle. State and configuration files remain
private to the host user. The short native Gatus window expires even if the
collector stops. Its weekly schedule representation is removed by reconciliation
and must also be removed when decommissioning the collector.

The queue monitor runs every minute and records unavailable or delayed queues
with the deployment ID when they were healthy before maintenance. It suppresses
email for those expected conditions without sending a later recovery email.
Failed, partial, and uncertain deliveries continue to notify. Backup monitoring
uses the existing receipt's account coverage while the application directory is
paused and continues checking backup freshness and recorded failures.

Gatus alerts mention Doug in Mattermost. His Mattermost email notifications also
deliver those mentions to his configured address. Suppression therefore covers
both direct Gatus email and Mattermost-generated email. Genuine incidents retain
the existing notification destinations.

## Install or update

Stage `gatus_moed.py`, `gatus_deployment.py`, `deployment_monitoring.py`, and
`install_gatus_moed.py` together on s1, then run:

```sh
python3 install_gatus_moed.py
python3 install_gatus_moed.py --apply
env -u XDG_RUNTIME_DIR -u DBUS_SESSION_BUS_ADDRESS \
  python3 /home/dgilmore/gatus/monitoring/push_container_health.py
```

The first command reports the proposed checks and current host health without
changing configuration. Installation locks the existing collector, preserves
private recovery copies under `~/.local/state/moed-gatus/config-backups/`, and
updates the existing minute collector. Gatus reloads its configuration; no
MOED container or network needs restarting. The collector allows 35 seconds
after a configuration change for Gatus's 30-second reload poll before pushing
new results. Verify the next scheduled cycle
in `~/gatus/monitoring/last-result.json` and the Gatus dashboard/API.

The extension requires Python's PyYAML package, already available in s1's Python
environment, for reading and updating Gatus configuration.

To roll back, hold `~/gatus/monitoring/collector.lock` while restoring the saved
`config.yaml`, `targets.json`, and `push_container_health.py` to their original
locations. Restore the saved `gatus_moed.py` when present. Keep files private and
verify the next scheduled collector cycle after Gatus reloads.

The independent recovery bundle also needs the same `deployment_monitoring.py`
alongside the reviewed `queue_monitor.py`, `recovery_monitor.py`, and
`install_queue_monitor.py`. Running the queue installer updates its managed cron
block to once per minute and preserves other schedules.
