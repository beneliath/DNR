#!/usr/bin/env python3
"""External per-account queue alerts; --check-only reads health without sending mail."""
import argparse
import datetime
from email.message import EmailMessage
import fcntl
import json
import os
from pathlib import Path
import subprocess
import sys

from recovery_accounts import account_directory, resolve_service
from recovery_monitor import ALERT_RECIPIENT, alert_due, load_smtp_configuration, send_smtp_message
from run_nas_recovery import write_status


def unavailable(reason):
    return {'healthy': False, 'unavailable': reason, 'queues': []}


def collect_health(root, project):
    try:
        # An intentionally disabled or not-yet-provisioned account must not
        # suppress checks for active accounts. Backup discovery stays strict.
        accounts = account_directory(root, project, ready_only=True)
    except Exception:
        return {'account-directory': unavailable('Account discovery unavailable; account coverage cannot be confirmed.')}
    reports = {}
    for account in accounts:
        try:
            web = resolve_service(account['project'], 'web')
            result = subprocess.run(['docker', 'exec', '-u', 'www-data', web, 'php',
                '/opt/dnr/bin/report_queue_health.php'], capture_output=True, text=True, timeout=30)
            if result.returncode not in (0, 1):
                raise ValueError('Health query failed')
            health = json.loads(result.stdout)
            if health.get('account_key') != account['key'] or not isinstance(health.get('healthy'), bool):
                raise ValueError('Account identity or report is invalid')
            if not isinstance(health.get('queues'), list) or not health['queues']:
                raise ValueError('Queue coverage is missing')
            if result.returncode != (0 if health['healthy'] else 1):
                raise ValueError('Health result disagrees with exit status')
            if len({q['key'] for q in health['queues']}) != 8 or health['healthy'] != (not any(q['issues'] for q in health['queues'])):
                raise ValueError('Incomplete or inconsistent queue outcomes')
            reports[account['key']] = health
        except Exception:
            reports[account['key']] = unavailable('Queue health unavailable; check this account’s web service and database.')
    return reports or {'account-directory': unavailable('No accounts discovered.')}


def fingerprint(report):
    if report.get('unavailable'):
        return ['health-unavailable']
    return sorted(queue['key'] + ':' + issue for queue in report['queues'] for issue in queue['issues'])


def queue_alert_due(previous, report, now):
    return (alert_due(previous, report['healthy'], now)
            or (not report['healthy'] and previous.get('issues') != fingerprint(report)))


def send_queue_alert(config, recipient, account, report):
    message = EmailMessage()
    message['From'] = config['sender']
    message['To'] = recipient
    message['Subject'] = 'MOED queue health ' + ('restored' if report['healthy'] else 'needs attention') + ': ' + account
    lines = ['Account: ' + account, 'Operations: /a/' + account + '/operations.php']
    if report['healthy']:
        lines.append('The reported queue conditions have cleared.')
    elif report.get('unavailable'):
        lines.append(report['unavailable'])
    else:
        for queue in report['queues']:
            if queue['issues']:
                lines.extend(['', queue['label'] + ': ' + ', '.join(queue['issues']),
                    'Waiting: {queued}; retries due: {retry}; processing: {processing}; failed (24h): {failed}; '
                    'partial jobs (24h): {partial}; uncertain: {uncertain}; stalled: {stalled}; oldest due: {oldest_due_seconds}s.'.format(**queue),
                    queue['action']])
    lines.append('\nNo delivery has been retried by this monitor. Check provider receipts before resending uncertain mail.')
    message.set_content('\n'.join(lines))
    send_smtp_message(config, message)


def monitor(root, project, state, recipient, check_only=False):
    if check_only:
        reports = collect_health(root, project)
        healthy = all(report['healthy'] for report in reports.values())
        print(json.dumps(reports, sort_keys=True))
        return 0 if healthy else 1
    state.mkdir(parents=True, exist_ok=True, mode=0o700)
    with (state / 'monitor.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        reports = collect_health(root, project)
        healthy = all(report['healthy'] for report in reports.values())
        path = state / 'alerts.json'
        previous = json.loads(path.read_text()) if path.exists() else {}
        now = datetime.datetime.now(datetime.timezone.utc)
        # Warm the protected cache while services are available, so an outage
        # does not require the unhealthy application's delivery worker.
        config = load_smtp_configuration(project, state / 'smtp-connection.json')
        for account, report in reports.items():
            if queue_alert_due(previous.get(account, {}), report, now):
                send_queue_alert(config, recipient, account, report)
                previous[account] = {'healthy': report['healthy'], 'issues': fingerprint(report), 'sent_at': now.isoformat()}
                write_status(path, previous)  # Only acknowledge accepted alerts.
        write_status(state / 'health.json', {'checked_at': now.isoformat(), 'accounts': reports})
    print('Queue health: ' + ('healthy' if healthy else 'attention required') + '; accounts checked: ' + str(len(reports)))
    return 0 if healthy else 1


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', type=Path, default=Path('/home/dgilmore/moed'))
    parser.add_argument('--compose-project', default='moed')
    parser.add_argument('--state-dir', type=Path, default=Path.home() / '.local/state/moed-queues')
    parser.add_argument('--recipient', default=ALERT_RECIPIENT)
    parser.add_argument('--check-only', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    try:
        return monitor(args.project, args.compose_project, args.state_dir, args.recipient, args.check_only)
    except Exception as error:
        print('Queue monitor failed (' + type(error).__name__ + '); no unsent alert was acknowledged.', file=sys.stderr)
        return 2


if __name__ == '__main__':
    sys.exit(main())
