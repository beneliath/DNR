"""Provision a requested LOCAL Account with independent Compose volumes/secrets.

No source data is copied. Existing Accounts are never overwritten. Production
provisioning must supply its own HTTPS ingress and qualified release images.
"""
from __future__ import annotations
import argparse
import base64
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import time

ROOT = Path(__file__).resolve().parent.parent
SERVICES = ('web', 'downloads', 'receipt-previews', 'document-scans', 'file-monitor',
            'file-migrator', 'ai-coach-worker', 'data-maintenance', 'geocoder',
            'mail-dispatch', 'backup', 'maintenance', 'key-rotation')


def run(args, **kwargs):
    return subprocess.run(args, check=True, text=True, **kwargs)


def control(container, action, key='', value=''):
    result = run(['docker', 'exec', '-i', container, 'php', '/dev/stdin', action, key, value],
                 input=(ROOT/'scripts/account_control.php').read_text(), capture_output=True)
    return json.loads(result.stdout)


def private_file(path, text):
    path.write_text(text); path.chmod(0o600)


def clean_environment():
    return {k: v for k, v in os.environ.items()
            if not k.startswith(('DNR_', 'MYSQL_', 'COMPOSE_', 'DOCKER_HOST'))
            and k not in ('PORT', 'DEFAULT_SPEAKER')}


def provision(args, key):
    if not re.fullmatch(r'[a-z][a-z0-9-]{2,63}', key):
        raise ValueError('Invalid Account key')
    primary = json.loads(run(['docker', 'inspect', args.primary_web], capture_output=True).stdout)[0]
    primary_ingress = json.loads(run(['docker', 'inspect', args.primary_ingress], capture_output=True).stdout)[0]
    bindings = primary_ingress['NetworkSettings']['Ports'].get('80/tcp') or []
    if not any(b['HostIp'] == '127.0.0.1' and b['HostPort'] == str(args.primary_port) for b in bindings):
        raise ValueError('Refusing a primary deployment that is not bound to the requested loopback port')
    account = control(args.primary_web, 'claim', key)
    primary_key = next((v.split('=', 1)[1] for v in primary['Config']['Env'] if v.startswith('DNR_ACCOUNT_KEY=')), '')
    if not re.fullmatch(r'[a-z][a-z0-9-]{2,63}', primary_key):
        raise ValueError('Invalid primary Account key')
    account_url = f'http://localhost:{args.primary_port}/a/{key}'
    primary_url = f'http://localhost:{args.primary_port}/a/{primary_key}'
    directory = ROOT/'var/accounts'/key
    directory.mkdir(parents=True, exist_ok=True); directory.chmod(0o700)
    statefile = directory/'deployment.json'
    if statefile.exists():
        state = json.loads(statefile.read_text())
    else:
        from integration_environment import allocate_test_networks
        ids = run(['docker', 'network', 'ls', '-q'], capture_output=True).stdout.split()
        networks = json.loads(run(['docker', 'network', 'inspect', *ids], capture_output=True).stdout) if ids else []
        allocated = allocate_test_networks(networks)
        state = {'project': 'moed-account-' + key,
                 'networks': {k: str(v) for k, v in allocated.items()}}
        private_file(statefile, json.dumps(state, indent=2))
    project = state['project']
    # A private network allows only member -> primary authenticated control calls;
    # no database or file volume is shared between Accounts.
    platform_network = args.platform_network
    if run(['docker', 'network', 'inspect', platform_network], capture_output=True).returncode != 0:
        raise ValueError('Primary platform network must exist first')
    values = {'DNR_PUBLIC_BASE_URL': account_url, 'DNR_ACCOUNT_GATEWAY_ENABLED': '1',
              'DNR_INBOUND_ADDRESS': 'moed@beneliath.com', 'DNR_MAIL_FROM': 'moed@beneliath.com',
              'DNR_ACCOUNT_MAIL_ENABLED': next((v.split('=', 1)[1] for v in primary['Config']['Env'] if v.startswith('DNR_ACCOUNT_MAIL_ENABLED=')), '0'),
              'DNR_REQUIRE_HTTPS': '0', 'DNR_ACCOUNTS_ENABLED': '1', 'DNR_ACCOUNT_MODE': 'member',
              'DNR_ACCOUNT_KEY': key, 'DNR_PRIMARY_PUBLIC_URL': primary_url,
              'DNR_PRIMARY_INTERNAL_URL': 'http://moed-primary', 'DNR_MAIL_TRANSPORT': 'log',
              'DNR_CONFIG_FILE_HOST': str(directory/'application.yaml'),
              'DNR_BACKEND_SUBNET': state['networks']['backend']}
    import ipaddress
    values['DNR_INGRESS_PROXY_IP'] = str(ipaddress.ip_network(values['DNR_BACKEND_SUBNET']).network_address + 254)
    for role in ('ROOT', 'APP', 'BACKUP', 'MAINTENANCE', 'GEOCODER', 'MAIL_INGEST', 'MAIL_DISPATCH'):
        path = directory/('mysql_' + role.lower())
        if not path.exists(): private_file(path, secrets.token_hex(32))
        path.chmod(0o444)
        values['DNR_MYSQL_' + role + '_PASSWORD_FILE'] = str(path)
    for name in ('DNR_2FA_KEY_FILE', 'DNR_INBOUND_ROUTING_KEY_FILE', 'DNR_BACKUP_PASSWORD_FILE'):
        path = directory/name.lower()
        if not path.exists(): private_file(path, base64.b64encode(secrets.token_bytes(32)).decode())
        path.chmod(0o444)
        values[name] = str(path)
    smtp_file = directory/'smtp_password'
    if not smtp_file.exists(): private_file(smtp_file, '')
    values['DNR_SMTP_PASSWORD_SECRET_FILE'] = str(smtp_file)
    api_key_file = directory/'platform_api_key'
    private_file(api_key_file, account['api_key'])
    api_key_file.chmod(0o444)
    profile = {'brand': {'display_name': 'MOED', 'native_name': '', 'mail_name': 'MOED',
                        'totp_issuer': 'MOED', 'calendar_name': account['name'] + ' Events'},
               'defaults': {'speaker': 'Unassigned Speaker', 'timezone': 'America/Chicago'}}
    if not (directory/'application.yaml').exists():
        private_file(directory/'application.yaml', json.dumps(profile))
    (directory/'application.yaml').chmod(0o444)
    for kind, container in [('APP', args.primary_web), ('INGRESS', args.primary_ingress), ('DATABASE', args.primary_db)]:
        values['DNR_' + kind + '_IMAGE'] = json.loads(run(['docker', 'inspect', container], capture_output=True).stdout)[0]['Config']['Image']
    private_file(directory/'account.env', ''.join(k + '=' + v + '\n' for k, v in values.items()))
    override = {'services': {}, 'networks': {}, 'secrets': {'platform_api_key': {'file': str(api_key_file)}}}
    for service in SERVICES:
        override['services'][service] = {'environment': {
            **{k: values[k] for k in ('DNR_ACCOUNTS_ENABLED', 'DNR_ACCOUNT_MAIL_ENABLED', 'DNR_INBOUND_ADDRESS', 'DNR_MAIL_FROM', 'DNR_ACCOUNT_MODE', 'DNR_ACCOUNT_KEY', 'DNR_PRIMARY_PUBLIC_URL', 'DNR_PRIMARY_INTERNAL_URL')},
            'DNR_PLATFORM_API_KEY_FILE': '/run/secrets/platform_api_key'},
            'secrets': ['platform_api_key'],
            'volumes': [str(ROOT/'src') + ':/var/www/html:ro']}
    for service in ('web', 'mail-dispatch'):
        override['services'][service]['environment']['DNR_MAIL_TRANSPORT'] = 'log'
    for service in ('web', 'downloads', 'backup'):
        override['services'][service]['networks'] = {'backend': {}, 'platform': {}}
    override['networks']['platform'] = {'external': True, 'name': platform_network}
    override['services']['ingress'] = {'networks': {'platform': {'aliases': ['moed-member-' + key]}}}
    for name, subnet in state['networks'].items():
        override['networks'][name] = {'ipam': {'config': [{'subnet': subnet}]}}
    if os.environ.get('GITHUB_ACTIONS') == 'true' and os.environ.get('DNR_ACCOUNT_CI_LIMITS') == '1':
        for service in ['db','web','downloads','backup','geocoder','mail-dispatch']:
            override['services'].setdefault(service, {}).update(mem_limit='512m', cpus=1)
    notice = directory/'notice'; notice.mkdir(exist_ok=True)
    for service in ('web', 'ingress'):
        override['services'].setdefault(service, {})['volumes'] = [str(notice) + ':/run/dnr/deployment:ro']
    # Migrations are completed while isolated; no ingress until its identity and
    # seed data have been initialized. Sidecars then use this same private DB.
    private_file(directory/'compose.json', json.dumps(override, indent=2))
    private_file(directory/'gateway-only.yaml', 'services:\n  ingress:\n    ports: !reset []\n')
    compose = ['docker', 'compose', '--env-file', str(directory/'account.env'), '-p', project,
               '-f', str(ROOT/'docker-compose.yaml'), '-f', str(ROOT/'docker-compose.dev.yaml'),
               '-f', str(ROOT/'docker-compose.smtp.yaml'), '-f', str(directory/'compose.json'),
               '-f', str(directory/'gateway-only.yaml')]
    def dc(*command, **kwargs): return run(compose + list(command), cwd=ROOT, env=clean_environment(), **kwargs)
    dc('up', '-d', '--no-build', 'db')
    dc('up', '-d', '--no-build', '--wait', 'db')
    dc('run', '--rm', '--no-deps', 'migrator')
    # Only initialize a fresh database. A retry verifies the existing identity.
    sql = """SET @expected_key = '%s';
SET @existing_key = (SELECT account_key FROM account_profile WHERE id = 1);
SET @fresh = (@existing_key = 'shalom-in-messiah' AND (SELECT COUNT(*) FROM users)=0
    AND (SELECT COUNT(*) FROM organizations)=0 AND (SELECT COUNT(*) FROM engagements)=0);
DELETE FROM speakers WHERE @fresh;
DELETE FROM security_audit_log WHERE @fresh;
UPDATE account_profile SET account_key = @expected_key, name = CONVERT(0x%s USING utf8mb4), settings = JSON_OBJECT()
WHERE id = 1 AND @fresh;
SELECT account_key FROM account_profile WHERE id = 1;
""" % (key, account['name'].encode().hex())
    initialized = dc('exec', '-T', 'db', 'sh', '-c',
        'MYSQL_PWD=$(cat /run/secrets/dnr_mysql_root_password) mysql -uroot dnr --batch --skip-column-names',
        input=sql, capture_output=True).stdout.strip()
    if initialized != key: raise ValueError('Account identity mismatch; refusing to expose this deployment')
    dc('up', '-d', '--no-build', '--wait', 'web', 'downloads', 'backup', 'ingress', 'geocoder', 'mail-dispatch')
    from configure_local_account_gateway import configure
    configure(args.primary_port)
    # Do not show Open Account until the route reaches its application. Apache
    # graceful reloads briefly continue serving the previous route table.
    for attempt in range(10):
        status = run(['curl', '--max-time', '5', '--silent', '--output', '/dev/null',
                      '--write-out', '%{http_code}', account_url + '/dashboard.php'], capture_output=True).stdout
        if status == '302':
            break
        time.sleep(1)
    else:
        raise RuntimeError('Account route is not ready; preparation will retry.')
    control(args.primary_web, 'ready', key, account_url)
    print(f'{account["name"]} ready at {account_url}', flush=True)


def main():
    import fcntl
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('account_key', nargs='?')
    parser.add_argument('--primary-web', default='dnr-web-1')
    parser.add_argument('--primary-ingress', default='dnr-ingress-1')
    parser.add_argument('--primary-db', default='dnr-db-1')
    parser.add_argument('--primary-port', type=int, default=8080)
    parser.add_argument('--platform-network', default='moed-account-control')
    parser.add_argument('--watch', action='store_true')
    args = parser.parse_args()
    if not args.account_key and not args.watch: parser.error('Specify an Account or --watch')
    lock = (ROOT/'var/deployment/account-provisioner.lock').open('a')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    if args.account_key:
        provision(args, args.account_key); return
    while True:
        # Disabled until the shared-mail queue migration and grants are approved.
        try:
            run(['docker', 'exec', '-i', args.primary_web, 'php'],
                input=(ROOT/'scripts/deliver_account_mail.php').read_text(), capture_output=True)
        except subprocess.CalledProcessError:
            print('Account mail delivery needs attention.', flush=True)
        try:
            run(['docker', 'exec', '-i', args.primary_web, 'php'],
                input=(ROOT/'scripts/reconcile_account_directory.php').read_text(), capture_output=True)
        except subprocess.CalledProcessError:
            print('Account directory synchronization pending.', flush=True)
        from account_lifecycle_local import process_lifecycle
        for account in control(args.primary_web, 'lifecycle'):
            try: process_lifecycle(args, account)
            except Exception as error:
                control(args.primary_web, 'lifecycle_failed', account['account_key'])
                print(f'Account {account["account_key"]} action failed: {type(error).__name__}', flush=True)
        for account in control(args.primary_web, 'pending'):
            try: provision(args, account['account_key'])
            except Exception as error:
                # Do not emit captured PHP output: it may contain credentials.
                print(f'Account {account["account_key"]} preparation failed: {type(error).__name__}', flush=True)
        time.sleep(5)


if __name__ == '__main__':
    main()
