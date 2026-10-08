#!/usr/bin/env python3
"""Expose prepared local Accounts behind the existing localhost:8080 ingress.

This only configures the development preview. It never contacts production,
changes its DNS, or publishes a member Account port.
"""
import argparse
import json
from pathlib import Path
import re
import subprocess
from account_ingress_security import configuration as ingress_security_configuration

ROOT = Path(__file__).resolve().parent.parent


def run(args, **kwargs):
    return subprocess.run(args, check=True, text=True, **kwargs)


def inspect(name):
    return json.loads(run(['docker', 'inspect', name], capture_output=True).stdout)[0]


def private(path, value):
    path.write_text(value)
    path.chmod(0o600)


def configure(primary_port):
    primary = inspect('dnr-web-1')
    ingress = inspect('dnr-ingress-1')
    ports = ingress['NetworkSettings']['Ports'].get('80/tcp') or []
    if not any(p['HostIp'] == '127.0.0.1' and p['HostPort'] == str(primary_port) for p in ports):
        raise ValueError('The primary preview must be bound to the requested loopback port.')
    directory = ROOT/'var/deployment/account-gateway'
    directory.mkdir(parents=True, exist_ok=True)
    primary_key = next((v.split('=', 1)[1] for v in primary['Config']['Env'] if v.startswith('DNR_ACCOUNT_KEY=')), '')
    if not re.fullmatch(r'[a-z][a-z0-9-]{2,63}', primary_key):
        raise ValueError('Invalid primary Account key.')
    primary_prefix = '/a/' + primary_key + '/'
    primary_url = f'http://localhost:{primary_port}' + primary_prefix.rstrip('/')
    routes = [
        # Only shared entry points live at the root. No Account is the default.
        r'RedirectMatch 303 "^/(?!a/|assets/|login\.php$|recover_password\.php$|platform_api\.php$|deployment_status\.php$).*" "/login.php"',
        f'RedirectMatch 302 "^/a/{primary_key}$" "{primary_prefix}"',
        f'ProxyPass "{primary_prefix}database_maintenance.php" "http://dnr-web-1:80/database_maintenance.php" connectiontimeout=5 timeout=300 retry=0 disablereuse=On',
        rf'ProxyPassMatch "^{primary_prefix}(surls/[a-f0-9]{{16}}/(?:speaker-notes\.pdf|ppt-slidedeck)|presentation_asset\.php)$" "http://dnr-downloads-1:80/$1" connectiontimeout=3 timeout=120 retry=0 disablereuse=On',
        f'ProxyPass "{primary_prefix}" "http://dnr-web-1:80/" connectiontimeout=5 timeout=120 retry=0 disablereuse=On',
        f'ProxyPassReverse "{primary_prefix}" "http://dnr-web-1:80/"',
    ]
    members = []
    from provision_local_account import control
    account_states = {a['account_key']: a['state'] for a in control('dnr-web-1', 'directory')}
    for state_path in sorted((ROOT/'var/accounts').glob('*/deployment.json')):
        key = state_path.parent.name
        if not re.fullmatch(r'[a-z][a-z0-9-]{2,63}', key):
            raise ValueError('Invalid Account key.')
        state = json.loads(state_path.read_text())
        if state['project'] != 'moed-account-' + key:
            raise ValueError('Unexpected Account project.')
        if account_states.get(key) not in ('ready', 'pending', 'provisioning', 'restoring'):
            routes.append(f'RedirectMatch 404 "^/a/{key}(?:/|$)"')
            continue
        members.append((key, state, state_path.parent))
        target = 'http://moed-member-' + key + '/'
        prefix = '/a/' + key + '/'
        routes += [f'RedirectMatch 302 "^/a/{key}$" "{prefix}"',
                   f'ProxyPass "{prefix}database_maintenance.php" "{target}database_maintenance.php" connectiontimeout=5 timeout=300 retry=0 disablereuse=On',
                   f'ProxyPass "{prefix}" "{target}" connectiontimeout=5 timeout=120 retry=0 disablereuse=On',
                   f'ProxyPassReverse "{prefix}" "{target}"']
    # Reserve existing usernames globally before enabling the shared sign-in.
    # No password hashes, MFA secrets, or business records leave their database.
    for key, container in [(primary_key, 'dnr-web-1')] + [(key, state['project'] + '-web-1') for key, state, _ in members]:
        users = run(['docker', 'exec', '-i', container, 'php'], capture_output=True,
                    input="<?php require '/var/www/html/bootstrap.php'; echo json_encode($conn->query('SELECT id, username FROM users WHERE platform_identity_id IS NULL')->fetch_all(MYSQLI_ASSOC));").stdout
        import base64
        payload = base64.b64encode(json.dumps({'key': key, 'users': json.loads(users)}).encode()).decode()
        register = "<?php require '/var/www/html/bootstrap.php'; $data=json_decode(base64_decode('" + payload + "'), true); $conn->begin_transaction(); foreach ($data['users'] as $user) { platformRegisterLogin($conn, $data['key'], (int)$user['id'], $user['username']); } $conn->commit();"
        run(['docker', 'exec', '-i', 'dnr-web-1', 'php'], input=register, capture_output=True)
    private(directory/'routes.conf', '\n'.join(routes) + '\n')
    include = directory/'include.conf'
    private(include, 'IncludeOptional /run/dnr/account-gateway/routes.conf\n')
    primary_security=directory/'ingress-security.conf'
    private(primary_security,ingress_security_configuration(primary_key,primary_url,True,False))
    overlay = ROOT/'var/deployment/accounts-local.compose.json'
    config = json.loads(overlay.read_text())
    config['services']['web'].setdefault('networks', {})['account_control'] = {}
    config['services']['ingress'].setdefault('volumes', [])
    config['services']['ingress']['volumes'] = [
        mount for mount in config['services']['ingress']['volumes']
        if '/account-gateway' not in mount and '/account-security' not in mount and '/account-assets' not in mount]
    config['services']['ingress']['volumes'] += [
        f'{directory}:/run/dnr/account-gateway:ro',
        f'{ROOT}/src/assets:/opt/dnr/account-assets:ro',
        f'{primary_security}:/etc/apache2/conf-enabled/zx-dnr-account-security.conf:ro',
        f'{include}:/etc/apache2/conf-enabled/zy-dnr-account-gateway.conf:ro']
    backend_template = (ROOT/'docker/apache-ingress.conf').read_text()
    primary_backend = directory/'primary-ingress.conf'
    private(primary_backend, backend_template.replace('http://web:80/', 'http://dnr-web-1:80/').replace('http://downloads:80/', 'http://dnr-downloads-1:80/'))
    config['services']['ingress']['volumes'].append(f'{primary_backend}:/etc/apache2/conf-enabled/zz-dnr-ingress.conf:ro')
    for name, service in config['services'].items():
        if name != 'ingress':
            service.setdefault('environment', {}).update({
                'DNR_ACCOUNT_GATEWAY_ENABLED': '1', 'DNR_GATEWAY_INTERNAL_URL': 'http://moed-primary',
                'DNR_PUBLIC_BASE_URL': primary_url, 'DNR_PRIMARY_PUBLIC_URL': primary_url})
    private(overlay, json.dumps(config, indent=2))

    # Network links are read from our prepared Account configurations; there is
    # no path-to-host interpolation from an HTTP request.
    from provision_local_account import clean_environment
    for key, state, member in members:
        member_config = json.loads((member/'compose.json').read_text())
        member_config['services'].setdefault('ingress', {}).setdefault('networks', {})['platform'] = {
            'aliases': ['moed-member-' + key]}
        backend = member/'ingress.conf'
        private(backend, backend_template.replace('http://web:80/', 'http://' + state['project'] + '-web-1:80/').replace('http://downloads:80/', 'http://' + state['project'] + '-downloads-1:80/'))
        mounts = member_config['services']['ingress'].setdefault('volumes', [])
        mounts[:] = [v for v in mounts if '/etc/apache2/conf-enabled/zz-dnr-ingress.conf' not in v]
        mounts.append(f'{backend}:/etc/apache2/conf-enabled/zz-dnr-ingress.conf:ro')
        security=member/'ingress-security.conf'
        private(security,ingress_security_configuration(key,f'http://localhost:{primary_port}/a/{key}'))
        mounts[:]=[v for v in mounts if '/account-security' not in v and '/account-assets' not in v]
        mounts += [f'{security}:/etc/apache2/conf-enabled/zy-dnr-account-security.conf:ro',
                   f'{ROOT}/src/assets:/opt/dnr/account-assets:ro']
        for name, service in member_config['services'].items():
            if name != 'ingress':
                service.setdefault('environment', {}).update({
                    'DNR_ACCOUNT_GATEWAY_ENABLED': '1',
                    'DNR_PUBLIC_BASE_URL': f'http://localhost:{primary_port}/a/{key}',
                    'DNR_PRIMARY_PUBLIC_URL': primary_url})
        private(member/'compose.json', json.dumps(member_config, indent=2))
        private(member/'gateway-only.yaml', 'services:\n  ingress:\n    ports: !reset []\n')
        compose = ['docker', 'compose', '--env-file', str(member/'account.env'), '-p', state['project'],
                   '-f', str(ROOT/'docker-compose.yaml'), '-f', str(ROOT/'docker-compose.dev.yaml'),
                   '-f', str(ROOT/'docker-compose.smtp.yaml'), '-f', str(member/'compose.json'),
                   '-f', str(member/'gateway-only.yaml')]
        run(compose + ['up', '-d', '--no-deps', '--no-build', 'web', 'downloads', 'backup', 'ingress', 'geocoder', 'mail-dispatch'], env=clean_environment())

    files = primary['Config']['Labels']['com.docker.compose.project.config_files'].split(',')
    compose = ['docker', 'compose', '-p', 'dnr']
    for file in files:
        compose += ['-f', file]
    run(compose + ['up', '-d', '--no-deps', '--no-build', 'web', 'downloads', 'backup', 'ingress'])
    run(['docker', 'exec', 'dnr-ingress-1', 'apache2ctl', '-t'])
    run(['docker', 'exec', 'dnr-ingress-1', 'apache2ctl', '-k', 'graceful'])
    for key in [primary_key] + [item[0] for item in members]:
        php = "<?php require '/var/www/html/bootstrap.php'; $conn->execute_query(\"UPDATE platform_accounts SET public_url = ? WHERE account_key = ? AND state = 'ready'\", "
        php += "['http://localhost:" + str(primary_port) + '/a/' + key + "', '" + key + "']);"
        run(['docker', 'exec', '-i', 'dnr-web-1', 'php'], input=php, capture_output=True)
    print(f'Account paths are served through http://localhost:{primary_port}; member ports are private.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--primary-port', type=int, default=8080)
    args = parser.parse_args()
    configure(args.primary_port)
