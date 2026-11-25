"""Scoped Gatus maintenance and one incident for a failed MOED deployment."""
import copy
import datetime
import json
import re
import urllib.request

from deployment_monitoring import deployment_status, read_json

DEPLOYMENT_ENDPOINT = 'moed-host-deployment'
PUBLIC_ENDPOINT = 'moed'


def key(endpoint):
    return re.sub(r'[ /_,.#+&]', '-', endpoint.get('group', '').lower()) + '_' \
        + re.sub(r'[ /_,.#+&]', '-', endpoint['name'].lower())


def configure(document):
    for provider in document.get('alerting', {}).values():
        defaults = provider.get('default-alert', {})
        for setting in ('failure', 'success'):
            typo, corrected = setting + '-threshhold', setting + '-threshold'
            if typo in defaults:
                defaults.setdefault(corrected, defaults.pop(typo))
    for endpoint in list(document.get('endpoints', [])):
        if endpoint['name'] != PUBLIC_ENDPOINT:
            continue
        conditions = endpoint.get('conditions', [])
        certificates = [condition for condition in conditions if '[CERTIFICATE_EXPIRATION]' in condition]
        if certificates:
            tls = copy.deepcopy(endpoint)
            tls['name'] = 'moed-tls'
            tls['conditions'] = certificates
            tls.pop('maintenance-windows', None)
            if any(e['name'] == tls['name'] and e.get('group') == tls.get('group') for e in document['endpoints']):
                raise ValueError('A separate MOED TLS check already exists; review before splitting')
            document['endpoints'].append(tls)
            endpoint['conditions'] = [condition for condition in conditions if condition not in certificates]
        # Recovery samples must arrive within the two-minute recovery allowance.
        endpoint['interval'] = '1m'
        # The public check runs independently of the minute collector. Three
        # failures leave time for discovery plus Gatus's 30-second reload poll
        # before it can notify about an expected deployment interruption.
        for alert in endpoint.get('alerts', []):
            defaults = document.get('alerting', {}).get(alert['type'], {}).get('default-alert', {})
            alert['failure-threshold'] = max(3, alert.get('failure-threshold', defaults.get('failure-threshold', 3)))


def statuses(url):
    with urllib.request.urlopen(url + '/api/v1/endpoints/statuses', timeout=10) as response:
        return json.load(response)


def recent_statuses(reports, now):
    observations = {}
    for report in reports:
        results = sorted(report.get('results', []), key=lambda result: result['timestamp'])
        if not results:
            continue
        latest = results[-1]
        stamp = datetime.datetime.fromisoformat(latest['timestamp'].replace('Z', '+00:00')).timestamp()
        observations[report['key']] = {
            'healthy': len(results) >= 2 and all(result.get('success') is True for result in results[-2:]),
            'ready': latest.get('success') is True,
            'timestamp': stamp,
            'fresh': 0 <= now - stamp <= 180,
        }
    return observations


def restore(document, previous):
    for pending in previous.get('pending_restore', []):
        restore(document, pending)
    for endpoint in document.get('endpoints', []) + document.get('external-endpoints', []):
        identity = key(endpoint)
        window = previous.get('windows', {}).get(identity)
        if window:
            remaining = [item for item in endpoint.get('maintenance-windows', []) if item != window]
            if remaining:
                endpoint['maintenance-windows'] = remaining
            else:
                endpoint.pop('maintenance-windows', None)
        original = previous.get('grouped', {}).get(identity, [])
        for alert in endpoint.get('alerts', []):
            for saved in original:
                # Restore only the enabled flag on the exact alert we grouped.
                # Connection edits made by an operator stay in place.
                if {k: v for k, v in alert.items() if k != 'enabled'} == {k: v for k, v in saved.items() if k != 'enabled'}:
                    if 'enabled' in saved:
                        alert['enabled'] = saved['enabled']
                    else:
                        alert.pop('enabled', None)
                    break


def plan(document, previous, deployment, observations, queue_receipt, now):
    document = copy.deepcopy(document)
    restore(document, previous)
    state = {'deployment': deployment, 'windows': {}, 'grouped': {}, 'checked_at': now}
    mode = deployment['mode']
    identifier = deployment.get('id')
    same_deployment = identifier and identifier == previous.get('id')
    baseline = previous.get('baseline', {})
    if not same_deployment:
        age = deployment.get('started_at', 0) - baseline.get('checked_at', 0)
        if not 0 <= age <= 180:
            baseline = {}
    state.update(id=identifier, baseline=baseline)
    eligible = set(baseline.get('healthy', []))
    endpoints = document.get('endpoints', []) + document.get('external-endpoints', [])
    affected = []
    scoped = []
    queue_reports = queue_receipt.get('accounts', {})
    queue_expected = bool(queue_reports) and all(report.get('healthy') is True
        or report.get('deployment_id') == identifier for report in queue_reports.values())
    for endpoint in endpoints:
        name, identity = endpoint['name'], key(endpoint)
        container = (endpoint.get('group', '').startswith('3_moed ') and name.startswith('moed-')
                     and not name.startswith('moed-host-') and name != 'moed-tls')
        expected = container or name in (PUBLIC_ENDPOINT, 'moed-host-account-worker') \
            or (name == 'moed-host-queue-outcomes' and queue_expected)
        if not expected or identity not in eligible:
            continue
        scoped.append(endpoint)
        observed = observations.get(identity, {})
        if not observed.get('ready') or not observed.get('fresh'):
            affected.append(name)
    # A previously reported deployment incident stays grouped until recovery.
    # Normal service incidents after a settled deployment use ordinary alerts.
    previous_mode = previous.get('deployment', {}).get('mode')
    awaiting_recovery = mode == 'normal' and previous_mode in ('recovering', 'incident')
    reported_incident = mode == 'recovering' and previous_mode == 'incident'
    if same_deployment and affected and (awaiting_recovery or reported_incident):
        mode = 'incident'
        deployment = dict(deployment, mode=mode, reason='deployment recovery checks are still failing')
        state['baseline'] = previous['baseline']
        state['deployment'] = deployment
    state['affected'] = sorted(affected)
    if mode in ('maintenance', 'recovering'):
        start = datetime.datetime.fromtimestamp(deployment['started_at'], datetime.timezone.utc).replace(second=0, microsecond=0)
        window = {'start': start.strftime('%H:%M'), 'duration': str(int(deployment['deadline'] - start.timestamp())) + 's',
                  'timezone': 'UTC', 'every': [start.strftime('%A')]}
        for endpoint in scoped:
            endpoint.setdefault('maintenance-windows', []).append(window)
            state['windows'][key(endpoint)] = window
    elif mode == 'incident':
        for endpoint in scoped:
            state['grouped'][key(endpoint)] = copy.deepcopy(endpoint.get('alerts', []))
            for alert in endpoint.get('alerts', []):
                alert['enabled'] = False
    state['healthy'] = mode not in ('incident', 'invalid')
    state['error'] = ''
    if not state['healthy']:
        stage = deployment.get('stage', 'unknown')
        stage = stage if re.fullmatch(r'[a-z0-9-]{1,64}', stage) else 'unknown'
        message = 'MOED ' + deployment['reason'] + '; stage=' + stage + '; affected: '
        for index, name in enumerate(sorted(affected)):
            if len(message) + len(name) > 1300:
                message += str(len(affected) - index) + ' more services; see Gatus'
                break
            message += name + ', '
        state['error'] = message.rstrip(', ') if affected else message + 'inspect the deployment record and account readiness'
    if mode in ('normal', 'invalid'):
        state['baseline'] = {'checked_at': now, 'healthy': [identity for identity, value in observations.items()
                                                          if value['healthy'] and value['fresh']]}
    return document, state


def reconcile(root, config, document, now):
    previous = read_json(root / 'deployment-alert-state.json', {})
    project = root.parent.parent / 'moed'
    deployment = deployment_status(project, now)
    try:
        observations = recent_statuses(statuses(config['gatus_url']), now)
    except (OSError, ValueError, KeyError, TypeError):
        observations = {}
        deployment = {'mode': 'invalid', 'reason': 'deployment monitor cannot read current service checks'}
    try:
        queue = read_json(root.parent.parent / '.local/state/moed-queues/health.json', {})
        # Old receipts cannot authorize suppression after a monitor stops.
        stamp = datetime.datetime.fromisoformat(queue.get('checked_at', '').replace('Z', '+00:00')).timestamp() if queue else 0
        if not 0 <= now - stamp <= 6 * 60:
            queue = {}
    except (OSError, ValueError, KeyError, TypeError):
        queue = {}
    return plan(document, previous, deployment, observations, queue, now)
