"""Real Traefik route replacement in an isolated network; no app DB or s1 changes."""
import argparse
import concurrent.futures
import json
import os
import socket
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
import time
import urllib.error
import urllib.request
import uuid

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts'))
from account_production import labels as account_labels
from deployment_guard import DeploymentGuard, PROBE_HEADER
from integration_environment import allocate_test_networks


def docker(*args):
    return subprocess.check_output(['docker', *args], text=True, stderr=subprocess.PIPE).strip()


def request(url, headers=None, method='GET'):
    try:
        response = urllib.request.urlopen(urllib.request.Request(url, headers=headers or {}, method=method), timeout=5)
    except urllib.error.HTTPError as error:
        response = error
    with response:
        return response.status, dict(response.headers), response.read().decode()


def eventually(check, description, timeout=60):
    deadline = time.monotonic() + timeout
    while time.monotonic() < deadline:
        try:
            if check():
                return
        except (OSError, ValueError):
            pass
        time.sleep(.2)
    raise AssertionError(description)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--image', required=True)
    parser.add_argument('--traefik-image', default='traefik:v3.7.9@sha256:652929a140a32d7cafafb13c6cdfab5376cfeff800f51397b87b524501ed02a8')
    parser.add_argument('--browsers', action='store_true')
    args = parser.parse_args()
    identifier = uuid.uuid4().hex
    network = 'dnr-test-guard-' + identifier[:10]
    proxy = network + '-traefik'
    names = [network + '-primary', network + '-member']
    existing = json.loads(docker('network', 'inspect', *docker('network', 'ls', '-q').split()))
    subnet = allocate_test_networks(existing)['backend']
    pool = list(subnet.subnets(prefixlen_diff=1))[0]
    proxy_ip, control_ip = str(subnet.network_address + 254), str(subnet.network_address + 253)
    guard = browser = None
    stopped = threading.Event()
    errors = []
    with tempfile.TemporaryDirectory(prefix='dnr-test-guard-') as temporary:
        root = Path(temporary); root.chmod(0o755)
        notice_dir = root / 'var/deployment'; notice_dir.mkdir(parents=True)
        now = time.time()
        notice = dict(id=identifier, phase='deploying', started_at=now-600,
                      countdown_started_at=now-600, not_before=now-300,
                      expected_back_at=now-1, expires_at=now+3600)
        def write_notice():
            path = notice_dir / 'notice.tmp'; path.write_text(json.dumps(notice)); path.replace(notice_dir / 'notice.json')
        write_notice()
        router = root / 'router.php'
        router.write_text('''<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/deployment_status.php') { require '/var/www/html/deployment_status.php'; return; }
if ($path === '/ready.php' || $path === '/health.php') {
    header('Content-Type: application/json'); echo '{"status":"ready","version":"fixture"}'; return;
}
if ($path === '/account_service.php') { echo '{"account_key":"member-account"}'; return; }
echo '<!doctype html><h1>Requested content</h1><input name="username"><input name="password">';
''')
        try:
            docker('network', 'create', '--subnet', str(subnet), '--ip-range', str(pool), network)
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', 0))
                host_port = listener.getsockname()[1]
            docker('run', '-d', '--name', proxy, '--network', network, '--ip', proxy_ip,
                   '-p', f'127.0.0.1:{host_port}:80', '-v', '/var/run/docker.sock:/var/run/docker.sock:ro', args.traefik_image,
                   '--entrypoints.web.address=:80', '--providers.docker=true', '--providers.docker.exposedbydefault=false',
                   '--log.level=DEBUG',
                   '--providers.docker.constraints=Label(`com.moed.fixture`, `' + identifier + '`)',
                   '--providers.providersthrottleduration=200ms')
            port = docker('port', proxy, '80/tcp').rsplit(':', 1)[1]
            origin = 'http://127.0.0.1:' + port
            config = dict(origin=origin, edge_network=network, entrypoint='web', certresolver='fixture',
                          primary_key='primary-account', primary_router='fixture-primary', primary_control_edge_ip=control_ip)
            def start_account(index):
                key = ['primary-account', 'member-account'][index]
                labels = account_labels(config, key, primary=index == 0)
                command = ['run', '-d', '--name', names[index], '--network', network,
                           '--label', 'com.moed.fixture=' + identifier,
                           '-v', str(router) + ':/fixture.php:ro',
                           '-v', str(notice_dir) + ':/run/dnr/deployment:ro']
                for key, value in labels.items():
                    if key.endswith(('.tls', '.tls.certresolver')):
                        continue
                    command += ['--label', key + '=' + value]
                docker(*command, '--entrypoint', 'php', args.image, '-S', '0.0.0.0:80', '/fixture.php')
            for index in range(2):
                start_account(index)
            # Start discovery with both fixtures present; Docker events emitted
            # during Traefik's initial inventory can precede its watch stream.
            docker('restart', proxy)
            eventually(lambda: request(origin + '/login.php')[0] == 200, 'Initial application route unavailable')
            guard = DeploymentGuard(root, config, args.image, identifier, ['primary-account', 'member-account'])
            guard.labels['com.moed.fixture'] = identifier
            guard.start()
            assert request(origin + '/a/member-account/account_service.php',
                           {'X-Forwarded-For': control_ip}, method='POST')[0] == 503
            control_probe = '''$context=stream_context_create(['http'=>['method'=>'POST',
                'header'=>'Host: 127.0.0.1', 'timeout'=>5, 'ignore_errors'=>true]]);
                echo file_get_contents('http://''' + proxy_ip + '''/a/member-account/account_service.php',false,$context);'''
            internal = docker('run', '--rm', '--network', network, '--ip', control_ip,
                              '--entrypoint', 'php', args.image, '-r', control_probe)
            assert json.loads(internal)['account_key'] == 'member-account', 'Reserved relay identity probe must still work'
            paths = ['/login.php', '/calendar.php?token=synthetic', '/surls/1234567890abcdef/speaker-notes.pdf',
                     '/a/primary-account/audience_collect_public.php?key=synthetic',
                     '/a/member-account/engagement.php?id=12', '/a/member-account/files/report.pdf',
                     '/any/content/path']
            samples = []
            def sample():
                deadline = time.monotonic() + 120
                while not stopped.is_set() and time.monotonic() < deadline:
                    for path in paths:
                        code, headers, body = request(origin + path)
                        samples.append(code)
                        if code != 503 or 'Temporarily unavailable' not in body:
                            errors.append((path, code))
                    time.sleep(.02)
            with concurrent.futures.ThreadPoolExecutor(max_workers=1) as executor:
                monitor = executor.submit(sample)
                for path in paths:
                    code, headers, body = request(origin + path)
                    assert code == 503 and 'data-maintenance-page' in body, (path, code)
                    assert 'no-store' in headers.get('Cache-Control', '')
                    assert not headers.get('Set-Cookie')
                    assert 'timer-expired' in body
                if args.browsers:
                    environment = dict(os.environ, DNR_GUARD_FIXTURE=temporary, DNR_GUARD_ORIGIN=origin)
                    browser = subprocess.Popen(['node', str(ROOT / 'tests/browser/maintenance-guard.test.cjs')], env=environment)
                    eventually(lambda: (root / 'browser-ready').exists() or browser.poll() is not None,
                               'Browsers did not load the maintenance page')
                    assert browser.poll() is None, 'Browser fixture failed to start'
                for name in names:
                    docker('rm', '-f', name)
                # Prove the actual failure exists behind the guard: no live router.
                eventually(lambda: request(origin + '/login.php', {PROBE_HEADER: guard.token})[0] == 404,
                           'Fixture did not reproduce the missing-ingress 404')
                (root / 'ingress-removed').touch()
                if browser:
                    eventually(lambda: (root / 'browser-refreshed').exists() or browser.poll() is not None,
                               'Browser refresh did not finish')
                    assert browser.poll() is None, 'Browser failed during ingress replacement'
                for index in range(2):
                    start_account(index)
                eventually(lambda: request(origin + '/login.php', {PROBE_HEADER: guard.token})[0] == 200,
                           'Replacement application route unavailable')
                stopped.set(); monitor.result()
            assert not errors, errors[:10]
            guard.withdraw()
            assert request(origin + '/login.php')[0] == 200
            assert request(origin + '/a/member-account/engagement.php')[0] == 200
            # Countdown may already be expired; only completion releases the tabs.
            notice['phase'] = 'complete'; write_notice()
            if browser:
                assert browser.wait(timeout=45) == 0, 'Browser handoff failed'
            guard.remove(); guard = None
            # Test allocation separately from the no-downtime HTTP exercise.
            docker('stop', proxy)
            docker('run', '-d', '--name', network + '-allocation', '--network', network,
                   '--entrypoint', 'sleep', args.image, '60')
            allocated = json.loads(docker('inspect', network + '-allocation'))[0]['NetworkSettings']['Networks'][network]['IPAddress']
            assert allocated not in [proxy_ip, control_ip]
            docker('start', proxy)
            eventually(lambda: request(origin + '/login.php')[0] == 200, 'Routes did not return after proxy restart')
            print(f'PASS: {len(samples)} protected requests during real ingress removal/replacement; '
                  'root, primary, member, public links and files returned maintenance; graceful handoff succeeded.')
        except Exception:
            for name in [proxy, *names, 'moed-deployment-guard-' + identifier]:
                result = subprocess.run(['docker', 'logs', '--tail', '15', name], capture_output=True, text=True)
                if result.returncode == 0:
                    print(name, result.stdout[-2500:], result.stderr[-2500:], flush=True)
            raise
        finally:
            stopped.set()
            if browser and browser.poll() is None:
                browser.terminate(); browser.wait(timeout=10)
            for name in [*names, 'moed-deployment-guard-' + identifier, network + '-allocation', proxy]:
                subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            subprocess.run(['docker', 'network', 'rm', network], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


if __name__ == '__main__':
    main()
