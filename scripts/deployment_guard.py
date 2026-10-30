"""Keep the entire public origin on maintenance while Compose replaces ingress.

The independent container uses the qualified ingress image and only the public
notice mount. Withdrawal first marks it unhealthy, leaving Apache alive until
Traefik has demonstrably switched back to every restored Account route.
"""
import ipaddress
import json
from pathlib import Path
import re
import secrets
import subprocess
import time
import urllib.error
import urllib.request
from urllib.parse import urlsplit


PROBE_HEADER = 'X-DNR-Deployment-Probe'


def guard_labels(config, identifier, token):
    host = urlsplit(config['origin']).hostname
    if not host or not re.fullmatch(r'[a-z0-9.-]+', host):
        raise ValueError('Invalid maintenance hostname')
    for name in ['edge_network', 'entrypoint', 'certresolver']:
        if not re.fullmatch(r'[a-z][a-z0-9_.-]{2,63}', config[name]):
            raise ValueError('Invalid maintenance ' + name)
    if not re.fullmatch('[a-f0-9]{32}', identifier) or not re.fullmatch('[a-f0-9]{64}', token):
        raise ValueError('Invalid maintenance identity')
    # The already-reserved control relay must retain its IP across replacement.
    # ClientIP uses the TCP peer, never an untrusted X-Forwarded-For header.
    control_ip = str(ipaddress.IPv4Address(config['primary_control_edge_ip']))
    name = 'moed-maintenance-' + identifier
    router = 'traefik.http.routers.' + name
    bypass = f'Header(`{PROBE_HEADER}`, `{token}`)'
    control = f'(ClientIP(`{control_ip}`) && Method(`POST`) && PathRegexp(`^/a/[a-z][a-z0-9-]{{2,63}}/account_service[.]php$`))'
    result = {
        'traefik.enable': 'true', 'traefik.docker.network': config['edge_network'],
        router + '.rule': f'Host(`{host}`) && !{bypass} && !{control}',
        router + '.priority': '10000', router + '.entrypoints': config['entrypoint'],
        router + '.service': name,
        'traefik.http.services.' + name + '.loadbalancer.server.port': '80',
        'com.centurylinklabs.watchtower.enable': 'false',
        'com.moed.deployment-guard': identifier,
    }
    if urlsplit(config['origin']).scheme == 'https':
        result.update({router + '.tls': 'true', router + '.tls.certresolver': config['certresolver']})
    return result


class DeploymentGuard:
    def __init__(self, root, config, image, identifier, account_keys):
        self.directory = Path(root) / 'var/deployment'
        self.config, self.image, self.identifier = config, image, identifier
        self.token = secrets.token_hex(32)
        self.labels = guard_labels(config, identifier, self.token)
        self.name = 'moed-deployment-guard-' + identifier
        self.marker = self.directory / ('guard-' + identifier + '.draining')
        self.origins = [config['origin'].rstrip('/')] + [
            config['origin'].rstrip('/') + '/a/' + key for key in account_keys]
        self.started = False

    @property
    def probe_options(self):
        return ['--header', PROBE_HEADER + ': ' + self.token]

    def start(self):
        self.marker.unlink(missing_ok=True)
        health = ('exit(is_file("/run/dnr/deployment/' + self.marker.name + '") || '
                  '@file_get_contents("http://127.0.0.1/deployment_status.php") === false ? 1 : 0);')
        command = ['docker', 'run', '-d', '--name', self.name, '--restart', 'unless-stopped',
            '--network', self.config['edge_network'], '--read-only', '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges:true', '--memory', '128m', '--pids-limit', '128',
            '--mount', 'type=bind,src=' + str(self.directory.resolve()) + ',dst=/run/dnr/deployment,readonly',
            '-e', 'DNR_MAINTENANCE_GUARD_ID=' + self.identifier,
            '--health-cmd', "php -r '" + health + "'", '--health-interval', '1s',
            '--health-timeout', '2s', '--health-retries', '1']
        for capability in ['CHOWN', 'DAC_OVERRIDE', 'NET_BIND_SERVICE', 'SETGID', 'SETUID']:
            command += ['--cap-add', capability]
        for mount in ['/tmp:rw,noexec,nosuid,size=16m', '/var/run/apache2:rw,noexec,nosuid,size=1m',
                      '/var/lock/apache2:rw,noexec,nosuid,size=1m']:
            command += ['--tmpfs', mount]
        for key, value in self.labels.items():
            command += ['--label', key + '=' + value]
        subprocess.run(command + [self.image, 'apache2-foreground', '-D', 'DNR_MAINTENANCE_GUARD'],
                       check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        self.started = True
        self.wait_for_route(protected=True)

    def wait_for_route(self, protected, timeout=90):
        deadline = time.monotonic() + timeout
        stable = 0
        while time.monotonic() < deadline:
            good = True
            for origin in self.origins:
                try:
                    request = urllib.request.Request(origin + '/deployment_status.php?guard_check=' + secrets.token_hex(6),
                                                     headers={'Cache-Control': 'no-cache'})
                    with urllib.request.urlopen(request, timeout=5) as response:
                        marker = response.headers.get('X-DNR-Maintenance-Guard')
                        status = json.load(response)
                        good &= (marker == self.identifier if protected else marker is None)
                        good &= isinstance(status.get('server_now'), (int, float))
                        good &= (status.get('notice') or {}).get('id') == self.identifier
                except (OSError, ValueError, urllib.error.URLError):
                    good = False
            stable = stable + 1 if good else 0
            if stable >= 3:
                return
            time.sleep(1)
        raise RuntimeError('Public maintenance route did not ' + ('activate' if protected else 'withdraw'))

    def withdraw(self):
        # Do NOT stop the container first: that would create another routing gap.
        self.marker.touch(mode=0o644)
        self.wait_for_route(protected=False)

    def protect(self):
        self.marker.unlink(missing_ok=True)
        self.wait_for_route(protected=True)

    def remove(self):
        subprocess.run(['docker', 'rm', '-f', self.name], check=True,
                       stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)
        self.marker.unlink(missing_ok=True)
