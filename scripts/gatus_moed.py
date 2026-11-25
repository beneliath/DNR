#!/usr/bin/env python3
"""Extend the existing Gatus collector with MOED discovery and host-job checks."""
import copy
import datetime
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import time
import gatus_deployment

HOST_GROUP = '3_moed operations'
HOST_CHECKS = ('account-worker', 'nas-recovery', 'test-account-refresh', 'deployment-retention', 'scheduler', 'deployment')
CRON_COMMANDS = ('run_nas_recovery.py', 'recovery_monitor.py', 'test_account_baseline_refresh.py', 'push_container_health.py')
# Stable systemd job IDs from src/systemd/sd-messages.h, independent of locale.
SYSTEMD_JOB_STARTED = '39f53479d3a045ac8e11786248231fbf'
SYSTEMD_RETENTION_EVENTS = (
    '7d4958e842da4a758f6c1cdc7b36dcc5',  # starting
    SYSTEMD_JOB_STARTED,                 # finished for Type=oneshot
    'be02cf6855d2428ba40df7e9d022f03d',  # failed start job
    'd9b373ed55a64feb8242e02dbe79a49c',  # failed unit result
    '0e4284a0caca4bfc81c0bb6786972673',  # skipped job
)


def run(command, **options):
    return subprocess.check_output(command, text=True, stderr=subprocess.DEVNULL, timeout=20, **options).strip()


def endpoint_key(group, name):
    return re.sub(r'[ /_,.#+&]', '-', group.lower()) + '_' + re.sub(r'[ /_,.#+&]', '-', name.lower())


def container_group(project):
    return '3_moed production' if project == 'moed' else '3_moed ' + project.removeprefix('moed-account-').replace('-', ' ')


def long_lived_moed(container):
    labels = container.get('Config', {}).get('Labels') or {}
    project = labels.get('com.docker.compose.project', '')
    if project != 'moed' and not re.fullmatch(r'moed-account-[a-z][a-z0-9-]*', project):
        return False
    # Compose temporarily renames the old container to <id>_<name> while
    # replacing it. Its labels and restart policy still look permanent.
    return (container.get('Name', '').lstrip('/').startswith(project + '-')
            and labels.get('com.docker.compose.oneoff', '').lower() == 'false'
            and container.get('HostConfig', {}).get('RestartPolicy', {}).get('Name') in ('always', 'unless-stopped', 'on-failure'))


def queue_alert_details(document, endpoint, *, subject='MOED queue outcomes',
                        action='Review the affected account\u2019s Operations page. Check provider receipts before resending uncertain mail.'):
    """Use Gatus's result template without changing descriptions every cycle.

    The native Mattermost provider omits external-endpoint errors. A scoped
    custom alert can use the same connection and Gatus's existing thresholds.
    Other alerts and any pre-existing custom provider stay untouched.
    """
    for alert in endpoint.get('alerts', []):
        if alert['type'] != 'mattermost':
            continue
        provider = document['alerting']['mattermost']
        connection = copy.deepcopy(provider)
        for override in provider.get('overrides', []):
            if override.get('group') == endpoint.get('group', ''):
                connection.update(override)
                break
        connection.update(alert.get('provider-override', {}))
        description = alert.get('description', provider.get('default-alert', {}).get('description', ''))
        # Keep the existing mention/custom instructions, including @doug.
        description = description.replace('Health Check Failed', subject)
        body = {'text': ('Gatus [ALERT_TRIGGERED_OR_RESOLVED]: [ENDPOINT_GROUP]/[ENDPOINT_NAME]\n'
                         + description + '\n[RESULT_ERRORS]\n'
                         + action)}
        if connection.get('channel'):
            body['channel'] = connection['channel']
        custom = {'url': connection['webhook-url'], 'method': 'POST',
                  'headers': {'Content-Type': 'application/json'},
                  'body': json.dumps(body, ensure_ascii=True),
                  'client': copy.deepcopy(connection.get('client') or {}),
                  'placeholders': {'ALERT_TRIGGERED_OR_RESOLVED': {'TRIGGERED': 'needs attention', 'RESOLVED': 'recovered'}}}
        document['alerting'].setdefault('custom', copy.deepcopy(custom))
        # Materialize native defaults before switching provider so an unrelated
        # custom provider cannot change this alert's delivery policy.
        settings = {**provider.get('default-alert', {}), **alert}
        for key, default in (('enabled', True), ('failure-threshold', 3), ('success-threshold', 2),
                             ('send-on-resolved', True), ('minimum-reminder-interval', '0s')):
            alert[key] = settings.get(key, default)
        alert.update(type='custom', description=description, **{'provider-override': custom})


def plan(config, document, containers, *, queue_ready=False):
    """Preserve unrelated checks/tokens; missing activated targets remain failures."""
    config, document = copy.deepcopy(config), copy.deepcopy(document)
    gatus_deployment.configure(document)
    targets = {t['container']: t for t in config['targets']}
    endpoints = {endpoint_key(e.get('group', ''), e['name']): e for e in document.get('external-endpoints', [])}
    present = {c['Name'].lstrip('/') for c in containers}
    desired = []
    for container in containers:
        if long_lived_moed(container):
            project = container['Config']['Labels']['com.docker.compose.project']
            desired.append((container['Name'].lstrip('/'), container_group(project), 'docker-health', False))
    # Stage new services without pretending that an unreleased worker is live.
    projects = {'moed', 'moed-account-test-account'}
    projects.update(c['Config']['Labels']['com.docker.compose.project'] for c in containers if long_lived_moed(c))
    for project in sorted(projects):
        name = project + '-audience-jobs-1'
        desired.append((name, container_group(project), 'docker-health', name not in present))
    for check in HOST_CHECKS:
        desired.append(('moed-host-' + check, HOST_GROUP, 'moed-host', False))
    desired.append(('moed-host-queue-outcomes', HOST_GROUP, 'moed-host', not queue_ready))
    for name, group, probe, pending in desired:
        if name not in targets:
            key = endpoint_key(group, name)
            existing = endpoints.get(key)
            targets[name] = {'container': name, 'key': key, 'probe': probe,
                             'token': existing['token'] if existing else secrets.token_hex(32)}
            if pending: targets[name]['awaiting_first_deployment'] = True
        if not pending:
            targets[name].pop('awaiting_first_deployment', None)
    providers = document.get('alerting', {})
    alerts = [{'type': provider, 'failure-threshold': 3, 'success-threshold': 2, 'send-on-resolved': True}
              for provider in ('discord', 'email', 'mattermost') if provider in providers]
    for name, target in targets.items():
        if not name.startswith('moed-'):
            continue
        key = target['key']
        if key not in endpoints:
            group = HOST_GROUP if target['probe'] == 'moed-host' else next(group for n, group, _, _ in desired if n == name)
            endpoints[key] = {'name': name, 'group': group, 'token': target['token']}
        endpoint = endpoints[key]
        if endpoint['token'] != target['token']:
            raise ValueError('Gatus endpoint and collector credentials disagree')
        endpoint['heartbeat'] = {'interval': '3m'}
        endpoint['enabled'] = not target.get('awaiting_first_deployment', False)
        if not endpoint.get('alerts') and alerts:
            endpoint['alerts'] = copy.deepcopy(alerts)
        if name == 'moed-host-queue-outcomes':
            queue_alert_details(document, endpoint)
        if name == 'moed-host-deployment':
            for alert in endpoint.get('alerts', []):
                alert.update({'failure-threshold': 1, 'success-threshold': 2,
                              'minimum-reminder-interval': '30m'})
            queue_alert_details(document, endpoint, subject='MOED deployment needs attention',
                                action='Review the deployment record and the affected services in Gatus before resuming work.')
    config['targets'] = list(targets.values())
    document['external-endpoints'] = list(endpoints.values())
    return config, document


def atomic_write(path, content):
    temporary = path.with_name(path.name + '.tmp')
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'w') as stream:
        stream.write(content)
    temporary.chmod(0o600)
    temporary.replace(path)


def inventory():
    ids = run(['/usr/bin/docker', 'ps', '-aq', '--filter', 'name=moed-']).split()
    return json.loads(run(['/usr/bin/docker', 'inspect', *ids])) if ids else []


def queue_monitor_installed(home):
    if (home / '.local/state/moed-queues/health.json').is_file():
        return True
    # A monitor that fails on its first run still needs a failing Gatus check.
    return any(re.search(r'[/ ]queue_monitor\.py(?:\s|$)', line)
               for line in run(['crontab', '-l']).splitlines() if not line.lstrip().startswith('#'))


def reconcile(root, config, *, write=True, home=None):
    import yaml  # Installed on the Gatus host; not needed by pure planning tests.
    home = home or Path.home()
    path = root.parent / 'config/config.yaml'
    document = yaml.safe_load(path.read_text())
    # A failed discovery must not delete or deactivate any existing checks.
    containers = inventory()
    updated, planned = plan(config, document, containers, queue_ready=queue_monitor_installed(home))
    planned, deployment = gatus_deployment.reconcile(root, updated, planned, time.time())
    if write:
        # Persist ownership before the config swap so a retry can restore the
        # prior flags/windows after a crash between these two atomic writes.
        previous = gatus_deployment.read_json(root / 'deployment-alert-state.json', {})
        pending = previous.get('pending_restore', []) + [
            {key: previous.get(key, {}) for key in ('windows', 'grouped')},
            {key: deployment[key] for key in ('windows', 'grouped')}]
        atomic_write(root / 'deployment-alert-state.json', json.dumps(dict(deployment, pending_restore=pending), indent=2) + '\n')
        if planned != document:
            atomic_write(path, yaml.safe_dump(planned, sort_keys=False))
            # Gatus polls every 30 seconds and takes another second to reload.
            # Let suppression/grouping take effect before pushing new results.
            time.sleep(35)
        atomic_write(root / 'deployment-alert-state.json', json.dumps(deployment, indent=2) + '\n')
        if updated != config: atomic_write(root / 'targets.json', json.dumps(updated, indent=2) + '\n')
    active = copy.deepcopy(updated)
    active['targets'] = [t for t in updated['targets'] if not t.get('awaiting_first_deployment')]
    return active


def fresh(value, maximum_age, now):
    if isinstance(value, (int, float)):
        timestamp = value
    else:
        stamp = datetime.datetime.fromisoformat(str(value))
        if stamp.tzinfo is None: return False
        timestamp = stamp.timestamp()
    return -60 <= now - timestamp <= maximum_age


def state(path):
    return json.loads(path.read_text())


def service_properties(name, *, user=True):
    # Cron does not inherit the login session's systemd user-bus variables.
    environment = dict(os.environ)
    if user:
        environment.setdefault('XDG_RUNTIME_DIR', '/run/user/' + str(os.getuid()))
        environment.setdefault('DBUS_SESSION_BUS_ADDRESS', 'unix:path=' + environment['XDG_RUNTIME_DIR'] + '/bus')
    output = run(['systemctl', *(['--user'] if user else []), 'show', name,
                  '--property=ActiveState,SubState,Type,Result,ExecMainStatus'], env=environment)
    return dict(line.split('=', 1) for line in output.splitlines() if '=' in line)


def retention_health(now):
    unit = 'moed-deployment-retention.service'
    timer = service_properties('moed-deployment-retention.timer')
    service = service_properties(unit)
    if timer.get('ActiveState') != 'active':
        return False, 'MOED deployment retention timer is not active'
    if service.get('Type') != 'oneshot':
        return False, 'MOED deployment retention service is not a verified oneshot job'
    if service.get('Result') != 'success' or service.get('ExecMainStatus') != '0':
        return False, 'MOED deployment retention service reports a failed run'
    # ExecMainExitTimestampMonotonic is lost on reboot; LastTriggerUSec only
    # proves the timer fired. Read the latest job event across boots instead.
    # Including starts/failures prevents an interrupted or failed newer run
    # from falling back to an older success after systemd forgets its result.
    output = run(['journalctl', '--user', '--unit=' + unit, '--no-pager',
                  '--output=json', '--lines=1',
                  '--output-fields=__REALTIME_TIMESTAMP,MESSAGE_ID,JOB_RESULT',
                  '_COMM=systemd', 'USER_UNIT=' + unit,
                  *('MESSAGE_ID=' + event for event in SYSTEMD_RETENTION_EVENTS)])
    receipt = json.loads(output or '{}')
    if receipt.get('MESSAGE_ID') != SYSTEMD_JOB_STARTED or receipt.get('JOB_RESULT') != 'done':
        return False, 'MOED deployment retention has no journal confirmation that its latest run completed successfully'
    if not fresh(int(receipt['__REALTIME_TIMESTAMP']) / 1_000_000, 27 * 3600, now):
        return False, 'MOED deployment retention completion is stale or has an invalid timestamp (maximum age 27 hours)'
    return True, ''


def queue_outcomes(receipt, now):
    """Only expose account/queue identifiers and counters, never delivery data."""
    if not fresh(receipt.get('checked_at'), 15 * 60, now):
        return False, 'MOED queue monitor report is stale or has an invalid timestamp (maximum age 15 minutes)'
    reports = receipt.get('accounts', {})
    if not reports:
        return False, 'MOED queue monitor has no account reports; account coverage cannot be confirmed'
    issues = []
    for account, report in sorted(reports.items()):
        # Only canonical identifiers and numeric counters enter the Gatus custom
        # JSON template. Labels, exception text and delivery payloads are private.
        account = account if re.fullmatch(r'[a-z][a-z0-9-]{0,79}', account) else 'unknown-account'
        if report.get('healthy') is True:
            continue
        if report.get('unavailable'):
            issues.append(account + ': queue health unavailable')
            continue
        found = False
        for queue in report.get('queues', []):
            if not queue.get('issues'):
                continue
            key = queue.get('key', '')
            key = key if re.fullmatch(r'[a-z][a-z0-9_]{0,79}', key) else 'unknown-queue'
            counts = []
            for issue in ('uncertain', 'failed', 'partial', 'stalled', 'overdue'):
                if issue not in queue['issues']:
                    continue
                if issue == 'overdue':
                    counts.append('overdue=' + str(max(0, int(queue.get('queued', 0))) + max(0, int(queue.get('retry', 0)))))
                    counts.append('oldest_due_seconds=' + str(max(0, int(queue.get('oldest_due_seconds', 0)))))
                else:
                    label = issue + ('(24h)' if issue in ('failed', 'partial') else '')
                    counts.append(label + '=' + str(max(0, int(queue.get(issue, 0)))))
            issues.append(account + '/' + key + ': ' + (', '.join(counts) or 'queue needs attention'))
            found = True
        if not found:
            issues.append(account + ': unhealthy queue report without issue details')
    if not issues:
        return True, ''
    # Keep webhook payloads comfortably below chat-provider limits as accounts
    # grow, and say explicitly when the Operations pages have further details.
    message = 'MOED queue outcomes: '
    for index, issue in enumerate(issues):
        if len(message) + len(issue) > 1400:
            message += '; ' + str(len(issues) - index) + ' more issues; see account Operations pages'
            break
        message += ('; ' if index else '') + issue
    return False, message


def probe_host(target, *, home=None, now=None):
    home, now = home or Path.home(), time.time() if now is None else now
    check = target['container'].removeprefix('moed-host-')
    try:
        if check == 'account-worker':
            service = service_properties('moed-account-worker.service')
            receipt = state(home / 'moed/var/deployment/account-production-health.json')
            healthy = service.get('ActiveState') == 'active' and service.get('SubState') == 'running' \
                and receipt.get('ok') is True and fresh(receipt.get('checked_at'), 180, now)
        elif check == 'nas-recovery':
            receipt = state(home / '.local/state/moed-backup/status.json')
            success = receipt.get('last_success', {})
            expected = receipt.get('expected_account_keys', [])
            accounts = success.get('accounts', {})
            healthy = (not receipt.get('last_error') and bool(expected) and set(expected) == set(accounts)
                and fresh(success.get('snapshot_started_at'), 270 * 60, now)
                and all(item.get('remote_directory') and fresh(item.get('snapshot_started_at'), 270 * 60, now)
                        for item in accounts.values()))
        elif check == 'test-account-refresh':
            receipt = state(home / '.local/state/moed-test-account-refresh/status.json')
            healthy = receipt.get('ok') is True and fresh(receipt.get('completed_at'), 27 * 3600, now)
        elif check == 'deployment-retention':
            return retention_health(now)
        elif check == 'scheduler':
            service = service_properties('cron.service', user=False)
            entries = '\n'.join(line for line in run(['crontab', '-l']).splitlines() if not line.lstrip().startswith('#'))
            healthy = service.get('ActiveState') == 'active' and all(command in entries for command in CRON_COMMANDS)
        elif check == 'queue-outcomes':
            receipt = state(home / '.local/state/moed-queues/health.json')
            return queue_outcomes(receipt, now)
        elif check == 'deployment':
            receipt = state(home / 'gatus/monitoring/deployment-alert-state.json')
            if not fresh(receipt.get('checked_at'), 180, now):
                return False, 'MOED deployment monitor is stale'
            return receipt['healthy'], receipt['error']
        else:
            return False, 'Unknown MOED operational check'
        return (True, '') if healthy else (False, 'MOED ' + check + ' failed or its successful result is stale')
    except (OSError, ValueError, TypeError, KeyError, AttributeError, OverflowError, subprocess.SubprocessError):
        return False, 'MOED ' + check + ' state could not be verified'
