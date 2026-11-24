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
recovery notification. Push tokens and notification credentials stay private
on s1 and are never stored in this repository.

## Host checks

| Check | Required evidence |
| --- | --- |
| Account provisioning | Active systemd user service and a successful heartbeat within three minutes |
| NAS recovery | Verified copies for every expected Account, no reported failure, and all snapshots younger than 270 minutes |
| Test Account refresh | Successful completed refresh within 27 hours, allowing the daylight-saving transition |
| Deployment retention | Active timer and successful completed service run within 27 hours |
| Scheduler | Active cron service and entries for backups, recovery alerts, nightly refresh, and Gatus collection |
| Queue outcomes | Once installed: recent independent queue-monitor report, with every Account healthy; stale after 15 minutes |

Checks only read operational state. They do not refresh data, run backups, retry
mail, start workers, or change application releases. User-service checks supply
the systemd user-bus environment that cron normally lacks.

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
Mattermost connection settings, also update this queue alert's `provider-override`.

## Install or update

Stage these two reviewed scripts together on s1, then run:

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
MOED container or network needs restarting. Verify the next scheduled cycle
in `~/gatus/monitoring/last-result.json` and the Gatus dashboard/API.

The extension requires Python's PyYAML package, already available in s1's Python
environment, for reading and updating Gatus configuration.

To roll back, hold `~/gatus/monitoring/collector.lock` while restoring the saved
`config.yaml`, `targets.json`, and `push_container_health.py` to their original
locations. Restore the saved `gatus_moed.py` when present. Keep files private and
verify the next scheduled collector cycle after Gatus reloads.
