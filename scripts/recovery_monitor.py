#!/usr/bin/env python3
"""External recovery freshness monitor, deduplicated SMTP alerts and sanitized DB health."""
import argparse
import datetime
from email.message import EmailMessage
import fcntl
import json
import os
from pathlib import Path
import smtplib
import ssl
import subprocess
import sys
from run_nas_recovery import age_seconds, resolve_service, write_status
import run_nas_recovery

ALERT_RECIPIENT = 'admin@beneliath.com'
MAX_SNAPSHOT_AGE_SECONDS = getattr(run_nas_recovery, 'MAX_SNAPSHOT_AGE_SECONDS', 3600)


def recovery_health(status):
    try:
        timestamp = status.get('last_success', {}).get('snapshot_started_at')
        then = datetime.datetime.fromisoformat(timestamp) if timestamp else None
        age = age_seconds(status)
        valid_time = then is not None and then.tzinfo is not None and then <= datetime.datetime.now(datetime.timezone.utc) + datetime.timedelta(minutes=2)
    except (TypeError, ValueError):
        age = None; valid_time = False
    healthy = valid_time and age is not None and age <= MAX_SNAPSHOT_AGE_SECONDS and not status.get('last_error')
    success = status.get('last_success', {})
    return {'Status': 'Healthy' if healthy else 'Attention required',
            'Snapshot age, minutes': round(age / 60, 1) if age is not None else 'No verified copy',
            'Last successful NAS copy': success.get('completed_at', 'Never'),
            'NAS destination': '//192.168.1.18/MOED_Backups',
            'Verification': 'Authenticated restore and NAS read-back checksum' if success else 'None recorded',
            'Last failure': status.get('last_error', {}).get('type', 'None'),
            'Alert recipient': ALERT_RECIPIENT,
            'Freshness limit, minutes': MAX_SNAPSHOT_AGE_SECONDS // 60,
            'Off-site protection': 'Separate destination not configured'}


def alert_due(previous, healthy, now):
    if healthy:
        return previous.get('healthy') is False
    sent = previous.get('sent_at')
    return previous.get('healthy') is not False or not sent or now - datetime.datetime.fromisoformat(sent) >= datetime.timedelta(hours=6)


def smtp_configuration(project):
    container = resolve_service(project, 'mail-dispatch')
    info = json.loads(subprocess.check_output(['docker', 'inspect', container]))[0]
    env = dict(item.split('=', 1) for item in info['Config']['Env'])
    def mounted(name):
        destination = env.get(name, '')
        paths = [m['Source'] for m in info['Mounts'] if m['Destination'] == destination]
        if len(paths) != 1:
            raise ValueError('Required SMTP secret mount is unavailable')
        return paths[0]
    host = env.get('DNR_SMTP_HOST', '')
    if not host:
        raise ValueError('SMTP host is not configured')
    # Docker-only hostnames cannot be resolved by the host process. Preserve the
    # certificate peer name, but connect to that service's private bridge address.
    connect_host = host
    ids = subprocess.check_output(['docker', 'ps', '-q', '--filter', 'label=com.docker.compose.project=' + project], text=True).split()
    for peer in json.loads(subprocess.check_output(['docker', 'inspect', *ids])):
        for network_name, network in peer['NetworkSettings']['Networks'].items():
            if network_name in info['NetworkSettings']['Networks'] and host in (network.get('Aliases') or []):
                connect_host = network['IPAddress']
    return dict(host=host, connect_host=connect_host, port=int(env.get('DNR_SMTP_PORT', '587')),
                encryption=env.get('DNR_SMTP_ENCRYPTION', 'starttls'), username=env.get('DNR_SMTP_USERNAME', ''),
                password_file=mounted('DNR_SMTP_PASSWORD_FILE'),
                sender=env.get('DNR_MAIL_FROM', ''), ca=mounted('DNR_SMTP_CA_FILE') if env.get('DNR_SMTP_CA_FILE') else None,
                peer=env.get('DNR_SMTP_PEER_NAME') or host)


def load_smtp_configuration(project, cache):
    try:
        config = smtp_configuration(project)
        write_status(cache, config)
        cache.chmod(0o600)
        return config
    except Exception:
        info = cache.stat()
        if info.st_uid != os.getuid() or info.st_mode & 0o077:
            raise ValueError('Unsafe cached SMTP configuration')
        return json.loads(cache.read_text())


def send_alert(config, healthy, verification=False):
    message = EmailMessage()
    message['From'] = config['sender']; message['To'] = ALERT_RECIPIENT
    message['Subject'] = 'MOED backup alert delivery verification' if verification else ('MOED recovery restored' if healthy else 'MOED backup recovery needs attention')
    message.set_content('This message verifies delivery of the external backup monitor to the configured administrator address. It does not indicate a backup failure.' if verification else ('Verified backups to the Synology NAS are current again.' if healthy else
                        'MOED has no verified NAS recovery snapshot within the configured freshness limit, or the latest backup attempt failed. '
                        'Inspect the s1 backup job and ~/.local/state/moed-backup/status.json. Previous copies have been retained.'))
    context = ssl.create_default_context(cafile=config['ca'])
    if config['encryption'] not in ('starttls', 'tls'):
        raise ValueError('External backup alerts require verified TLS')
    # Resolve the Docker service address without changing the TLS certificate name.
    client = smtplib.SMTP_SSL(context=context, timeout=15) if config['encryption'] == 'tls' else smtplib.SMTP(timeout=15)
    client._host = config['peer']
    client.connect(config['connect_host'], config['port'])
    try:
        client.ehlo()
        if config['encryption'] == 'starttls':
            client.starttls(context=context); client.ehlo()
        if config['username']:
            client.login(config['username'], Path(config['password_file']).read_text().rstrip('\r\n'))
        client.send_message(message)
    finally:
        client.close()


def publish_health(project, health):
    db = resolve_service(project, 'db')
    # No secrets in argv, status, SQL literals, or diagnostics. The JSON is base64
    # encoded so generated SQL has a restricted alphabet.
    import base64
    encoded = base64.b64encode(json.dumps(health).encode()).decode()
    sql = "INSERT INTO dnr.data_management_health (name,state_json) VALUES ('recovery',FROM_BASE64('%s')) ON DUPLICATE KEY UPDATE state_json=VALUES(state_json),checked_at=UTC_TIMESTAMP(6);" % encoded
    command = ['docker', 'exec', '-i', db, 'sh', '-c',
               'MYSQL_PWD=$(cat "$MYSQL_MAINTENANCE_PASSWORD_FILE"); export MYSQL_PWD; exec mysql -udnrmaintenance -NBr', 'recovery-health']
    result = subprocess.run(command, input=sql, text=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=20)
    return result.returncode == 0


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--compose-project', default='moed')
    parser.add_argument('--check-only', action='store_true')
    parser.add_argument('--verify-delivery', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    state = Path.home() / '.local/state/moed-backup'; state.mkdir(parents=True, exist_ok=True, mode=0o700)
    with (state / 'monitor.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        path = state / 'status.json'
        status = json.loads(path.read_text()) if path.exists() else {}
        health = recovery_health(status); healthy = health['Status'] == 'Healthy'
        if args.check_only:
            print(health['Status']); return 0 if healthy else 1
        if args.verify_delivery:
            send_alert(load_smtp_configuration(args.compose_project, state/'smtp-connection.json'), healthy, verification=True)
            print('Backup alert delivery verification accepted by the SMTP server.'); return 0
        try:
            published = publish_health(args.compose_project, health)
        except Exception:
            published = False  # Alerts must still run when the database is unavailable.
        alerts = state / 'alert-status.json'; previous = json.loads(alerts.read_text()) if alerts.exists() else {}
        now = datetime.datetime.now(datetime.timezone.utc)
        if healthy:
            load_smtp_configuration(args.compose_project, state/'smtp-connection.json')
        if alert_due(previous, healthy, now):
            try:
                send_alert(load_smtp_configuration(args.compose_project, state/'smtp-connection.json'), healthy)
                write_status(alerts, {'healthy': healthy, 'sent_at': now.isoformat()})
            except Exception as error:
                print('Backup alert delivery failed (' + type(error).__name__ + '); delivery will be retried.', file=sys.stderr)
                return 2
        print(health['Status'] + ('; application health published.' if published else '; application health publication unavailable.'))
        return 0 if healthy else 1


if __name__ == '__main__':
    sys.exit(main())
