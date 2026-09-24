"""Own a fresh, labelled Compose project; never infer a test target from cwd/.env."""
import base64
import ipaddress
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import tempfile

ROOT = Path(__file__).resolve().parent.parent
LABEL = 'org.dnr.disposable-test'


def allocate_test_networks(existing):
    """Pin every test bridge; Docker's automatic pools can overlap the real LAN."""
    used = [ipaddress.ip_network(config['Subnet'])
            for network in existing for config in (network.get('IPAM', {}).get('Config') or [])
            if config.get('Subnet')]
    selected = {}
    start = secrets.randbelow(256)
    for offset in range(256):
        candidate = ipaddress.ip_network(f'10.252.{(start + offset) % 256}.0/24')
        if any(candidate.overlaps(network) for network in used):
            continue
        selected[('backend', 'ingress', 'egress', 'default')[len(selected)]] = candidate
        used.append(candidate)
        if len(selected) == 4:
            return selected
    raise ValueError('Unable to allocate four non-overlapping test networks in 10.252.0.0/16')


def verify(project, token):
    if not project.startswith('dnr-test-') or len(token) != 32:
        raise ValueError('A marked disposable test project is required')
    ids = subprocess.check_output(['docker', 'ps', '-aq', '--filter',
                                  'label=com.docker.compose.project=' + project], text=True).split()
    if not ids:
        raise ValueError('Disposable test containers are missing')
    containers = json.loads(subprocess.check_output(['docker', 'inspect', *ids]))
    for container in containers:
        labels = container['Config']['Labels']
        if labels.get(LABEL) != token:
            raise ValueError('Refusing an unmarked container')
        for mount in container['Mounts']:
            if mount['Type'] != 'volume':
                continue
            volume = json.loads(subprocess.check_output(['docker', 'volume', 'inspect', mount['Name']]))[0]
            if volume.get('Labels', {}).get(LABEL) != token:
                raise ValueError('Refusing unmarked test volume ' + mount['Name'] + ' on ' + container['Name'])


def main():
    if sys.argv[1:] == ['verify']:
        verify(os.environ.get('DNR_INTEGRATION_PROJECT', ''), os.environ.get('DNR_ISOLATION_TOKEN', ''))
        return
    if sys.argv[1:] not in ([], ['downloads'], ['coach'], ['uploads'], ['calendar']):
        raise ValueError('Usage: integration_environment.py [downloads|coach|uploads|calendar|verify]')
    calendar_only = sys.argv[1:] == ['calendar']
    downloads_only = sys.argv[1:] == ['downloads']
    coach_only = sys.argv[1:] == ['coach']
    uploads_only = sys.argv[1:] == ['uploads']
    token = secrets.token_hex(16)
    project = 'dnr-test-' + token[:12]
    # Do not inherit real application secrets, addresses, project selection or mail settings.
    env = {k: v for k, v in os.environ.items()
           if not k.startswith(('DNR_', 'MYSQL_', 'COMPOSE_')) and k not in ('PORT', 'DEFAULT_SPEAKER')}
    with tempfile.TemporaryDirectory(prefix='dnr-test-') as temporary:
        folder = Path(temporary)
        values = {'PORT': '0', 'DNR_PUBLIC_BASE_URL': 'http://localhost', 'DNR_REQUIRE_HTTPS': '0'}
        for name in ('ROOT', 'APP', 'BACKUP', 'MAINTENANCE', 'GEOCODER', 'MAIL_INGEST', 'MAIL_DISPATCH'):
            path = folder / ('mysql_' + name.lower())
            path.write_text(secrets.token_hex(32)); path.chmod(0o444)
            values['DNR_MYSQL_' + name + '_PASSWORD_FILE'] = str(path)
        for name in ('DNR_2FA_KEY_FILE', 'DNR_INBOUND_ROUTING_KEY_FILE', 'DNR_BACKUP_PASSWORD_FILE', 'DNR_IMAP_PASSWORD_FILE'):
            path = folder / name.lower()
            path.write_text(base64.b64encode(secrets.token_bytes(32)).decode()); path.chmod(0o444)
            values[name] = str(path)
        networks = subprocess.check_output(['docker', 'network', 'ls', '-q'], text=True).split()
        existing = json.loads(subprocess.check_output(['docker', 'network', 'inspect', *networks])) if networks else []
        test_networks = allocate_test_networks(existing)
        values.update(DNR_BACKEND_SUBNET=str(test_networks['backend']),
                      DNR_INGRESS_PROXY_IP=str(test_networks['backend'].network_address + 254))
        for kind in ('APP', 'INGRESS', 'DATABASE'):
            values['DNR_' + kind + '_IMAGE'] = os.environ.get('DNR_TEST_' + kind + '_IMAGE', project + '-' + kind.lower())
        envfile = folder / 'test.env'
        envfile.write_text(''.join(k + '=' + v + '\n' for k, v in values.items()))
        services = ('web', 'downloads', 'file-monitor', 'file-migrator', 'backup', 'ingress', 'geocoder', 'maintenance', 'db', 'migrator', 'mail-ingest', 'ai-coach-worker')
        override = {'services': {s: {'labels': {LABEL: token}} for s in services},
                    'volumes': {v: {'labels': {LABEL: token}} for v in ('app_sessions', 'uploaded_files', 'db_data', 'db_keyring', 'db_socket')},
                    'networks': {name: {'labels': {LABEL: token}, 'ipam': {'config': [{'subnet': str(subnet)}]}}
                                 for name, subnet in test_networks.items()}}
        # MySQL images declare volumes even when used only as a migration CLI.
        override['services']['migrator']['tmpfs'] = ['/var/lib/mysql', '/var/lib/mysql-keyring']
        override['services']['web']['environment'] = {'DNR_AI_COACH_ENABLED': '1', 'DNR_AI_COACH_URL': 'http://127.0.0.1:9', 'DNR_AI_COACH_ASYNC': '1'}
        override['services']['ai-coach-worker']['environment'] = {'DNR_AI_COACH_URL': 'http://127.0.0.1:9'}
        # Keep maintenance notice files and any test output away from the live bind mount.
        notice = folder / 'notice'; notice.mkdir()
        for service in ('web', 'ingress'):
            override['services'][service]['volumes'] = [str(notice) + ':/run/dnr/deployment:ro']
        overlay = folder / 'test.json'; overlay.write_text(json.dumps(override))
        env.update(values, DNR_INTEGRATION_PROJECT=project, DNR_ISOLATION_TOKEN=token,
                   DNR_INTEGRATION_ENV_FILE=str(envfile), DNR_INTEGRATION_OVERLAY=str(overlay))
        compose = ['docker', 'compose', '--env-file', str(envfile), '-p', project,
                   '-f', str(ROOT/'docker-compose.yaml'), '-f', str(ROOT/'docker-compose.dev.yaml'),
                   '-f', str(ROOT/'docker-compose.mail.yaml'), '-f', str(overlay)]
        try:
            if not all(os.environ.get('DNR_TEST_' + k + '_IMAGE') for k in ('APP', 'INGRESS', 'DATABASE')):
                subprocess.run(compose + ['build', 'web', 'ingress', 'db'], cwd=ROOT, env=env, check=True)
            subprocess.run(compose + ['up', '-d', '--no-build', '--wait', 'web', 'backup', 'ingress'], cwd=ROOT, env=env, check=True)
            verify(project, token)
            print('Verified isolated integration project: ' + project, flush=True)
            if calendar_only:
                subprocess.run(compose + ['exec', '-T', '-u', 'www-data',
                    '-e', 'DNR_INTEGRATION_TEST=1', '-e', 'DNR_INTEGRATION_TARGET=disposable',
                    '-e', 'DNR_TEST_SOURCE_DIR=/var/www/html',
                    'web', 'php', '/opt/dnr/tests/calendar_subscription_content_http_integration_test.php'], cwd=ROOT, env=env, check=True)
                return
            if uploads_only:
                subprocess.run(compose + ['exec', '-T', '-u', 'www-data',
                    '-e', 'DNR_INTEGRATION_TEST=1', '-e', 'DNR_INTEGRATION_TARGET=disposable',
                    '-e', 'DNR_TEST_SOURCE_DIR=/var/www/html', '-e', 'DNR_TEST_BASE_URL=http://ingress',
                    '-e', 'DNR_CHUNK_UPLOAD_ONLY=1',
                    'web', 'php', '/opt/dnr/tests/presentation_slidedeck_http_integration_test.php'], cwd=ROOT, env=env, check=True)
                return
            if coach_only:
                for suite in ('ai_coach_http_integration_test.php', 'ai_coach_queue_integration_test.php', 'ai_coach_feedback_integration_test.php'):
                    subprocess.run(compose + ['exec', '-T', '-u', 'www-data',
                        '-e', 'DNR_INTEGRATION_TEST=1', '-e', 'DNR_INTEGRATION_TARGET=disposable',
                        '-e', 'DNR_TEST_SOURCE_DIR=/var/www/html',
                        'web', 'php', '/opt/dnr/tests/' + suite], cwd=ROOT, env=env, check=True)
                subprocess.run(compose + ['up', '-d', '--no-build', '--no-deps', 'ai-coach-worker'], cwd=ROOT, env=env, check=True)
                subprocess.run(compose + ['exec', '-T', '-u', 'www-data', '-e', 'DNR_INTEGRATION_TEST=1', '-e', 'DNR_INTEGRATION_TARGET=disposable', '-e', 'DNR_TEST_SOURCE_DIR=/var/www/html', 'web','php','/opt/dnr/tests/ai_coach_worker_integration_test.php'], cwd=ROOT, env=env, check=True)
                return
            if not downloads_only:
                subprocess.run(['sh', 'scripts/run_integration_tests.sh', 'disposable'], cwd=ROOT, env=env, check=True)
            fixture = json.loads(subprocess.check_output(compose + ['exec', '-T', '-u', 'www-data',
                '-e', 'DNR_INTEGRATION_TARGET=disposable', 'web', 'php', '/opt/dnr/tests/download_pool_fixture.php'], cwd=ROOT, env=env))
            address = subprocess.check_output(compose + ['port', 'ingress', '80'], cwd=ROOT, env=env, text=True).strip()
            subprocess.run([sys.executable, 'tests/download_pool_capacity_test.py', 'http://' + address, fixture['path']], cwd=ROOT, env=env, check=True)
        except subprocess.CalledProcessError:
            # Preserve startup/test diagnostics before removing the disposable project.
            subprocess.run(compose + ['logs', '--no-color', '--tail', '60', 'migrator', 'file-migrator', 'web', 'ai-coach-worker'], cwd=ROOT, env=env, check=False)
            raise
        finally:
            # Project name is generated here, never supplied by a caller.
            subprocess.run(compose + ['down', '--volumes', '--remove-orphans'], cwd=ROOT, env=env, check=True)


if __name__ == '__main__':
    main()
