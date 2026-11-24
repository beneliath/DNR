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

HOST_GROUP = '3_moed operations'
HOST_CHECKS = ('account-worker', 'nas-recovery', 'test-account-refresh', 'deployment-retention', 'scheduler')
CRON_COMMANDS = ('run_nas_recovery.py', 'recovery_monitor.py', 'test_account_baseline_refresh.py', 'push_container_health.py')


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
    return (labels.get('com.docker.compose.oneoff', '').lower() == 'false'
            and container.get('HostConfig', {}).get('RestartPolicy', {}).get('Name') in ('always', 'unless-stopped', 'on-failure'))


def plan(config, document, containers, *, queue_ready=False):
    """Preserve unrelated checks/tokens; missing activated targets remain failures."""
    config, document = copy.deepcopy(config), copy.deepcopy(document)
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
    if write:
        if planned != document: atomic_write(path, yaml.safe_dump(planned, sort_keys=False))
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
                  '--property=ActiveState,SubState,Result,ExecMainStatus,ExecMainExitTimestampMonotonic'], env=environment)
    return dict(line.split('=', 1) for line in output.splitlines() if '=' in line)


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
            timer = service_properties('moed-deployment-retention.timer')
            service = service_properties('moed-deployment-retention.service')
            completed = int(service.get('ExecMainExitTimestampMonotonic', 0)) / 1_000_000
            healthy = (timer.get('ActiveState') == 'active' and service.get('Result') == 'success'
                and service.get('ExecMainStatus') == '0' and completed > 0
                and 0 <= time.monotonic() - completed <= 27 * 3600)
        elif check == 'scheduler':
            service = service_properties('cron.service', user=False)
            entries = '\n'.join(line for line in run(['crontab', '-l']).splitlines() if not line.lstrip().startswith('#'))
            healthy = service.get('ActiveState') == 'active' and all(command in entries for command in CRON_COMMANDS)
        elif check == 'queue-outcomes':
            receipt = state(home / '.local/state/moed-queues/health.json')
            reports = receipt.get('accounts', {})
            healthy = bool(reports) and fresh(receipt.get('checked_at'), 15 * 60, now) \
                and all(report.get('healthy') is True for report in reports.values())
        else:
            return False, 'Unknown MOED operational check'
        return (True, '') if healthy else (False, 'MOED ' + check + ' failed or its successful result is stale')
    except (OSError, ValueError, TypeError, KeyError, subprocess.SubprocessError):
        return False, 'MOED ' + check + ' state could not be verified'
