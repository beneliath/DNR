"""Verify edge IP reservation with disposable Docker containers, never a host reboot."""
import argparse
import ipaddress
import json
import shutil
import subprocess
import uuid

LABEL = 'org.dnr.disposable-test'


def run(*args):
    result = subprocess.run(args, text=True, capture_output=True, timeout=45)
    if result.returncode:
        raise RuntimeError(' '.join(args[:4]) + ': ' + result.stderr.strip())
    return result.stdout.strip()


def available_subnet(requested=None):
    ids = run('docker', 'network', 'ls', '-q').split()
    networks = json.loads(run('docker', 'network', 'inspect', *ids)) if ids else []
    used = [ipaddress.ip_network(c['Subnet']) for n in networks
            for c in (n['IPAM'].get('Config') or []) if c.get('Subnet')]
    if shutil.which('ip'):
        for route in json.loads(run('ip', '-j', 'route', 'show', 'table', 'all')):
            if route.get('dst') and route['dst'] != 'default':
                used.append(ipaddress.ip_network(route['dst'], strict=False))
    candidates = [requested] if requested else [f'10.253.{n}.0/24' for n in range(128, 256)]
    for candidate in candidates:
        subnet = ipaddress.ip_network(candidate)
        if subnet.version != 4 or subnet.prefixlen != 24:
            raise ValueError('The disposable test requires an IPv4 /24 subnet')
        if not any(subnet.overlaps(other) for other in used if other.version == 4):
            return subnet
    raise ValueError('No non-overlapping disposable test subnet is available')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--local-docker', action='store_true', required=True)
    parser.add_argument('--image', default='busybox:latest', help='An already-installed BusyBox image')
    parser.add_argument('--subnet', help='Optional unused IPv4 /24 subnet')
    options = parser.parse_args()
    # Fail before allocating resources if the image is absent; never pull an image.
    image = json.loads(run('docker', 'image', 'inspect', options.image))[0]['Id']
    subnet = available_subnet(options.subnet)
    pool = ipaddress.ip_network(f'{subnet.network_address + 128}/25')
    token = uuid.uuid4().hex
    name = 'dnr-test-edge-' + token[:12]
    created = []
    network_id = None

    def address(container):
        info = json.loads(run('docker', 'inspect', container))[0]
        return info['NetworkSettings']['Networks'][name]['IPAddress']

    def create(suffix, static_ip=None):
        args = ['docker', 'create', '--name', name + '-' + suffix,
                '--label', LABEL + '=' + token, '--network', name,
                '--cap-drop', 'ALL', '--memory', '16m', '--pids-limit', '16']
        if static_ip:
            args += ['--ip', str(static_ip)]
        container = run(*args, image, 'sleep', '300')
        created.append(container)
        return container

    try:
        network_id = run('docker', 'network', 'create', '--driver', 'bridge',
                         '--label', LABEL + '=' + token, '--subnet', str(subnet),
                         '--gateway', str(subnet.network_address + 1),
                         '--ip-range', str(pool), name)
        # Match the relative production reservations: Traefik .2, ingress .3, control .4.
        statics = [(create(service, subnet.network_address + octet),
                    str(subnet.network_address + octet))
                   for service, octet in [('traefik', 2), ('primary-ingress', 3), ('primary-control', 4)]]
        dynamics = [create(f'account-{account}-{service}') for account in range(3)
                    for service in ('ingress', 'control')]
        for cycle in range(2):
            for container in dynamics:
                run('docker', 'start', container)
            probe = create(f'new-account-{cycle}')
            run('docker', 'start', probe)
            allocated = [address(c) for c in dynamics + [probe]]
            assert len(set(allocated)) == len(allocated), allocated
            assert all(ipaddress.ip_address(ip) in pool for ip in allocated), allocated
            assert not set(allocated).intersection(ip for c, ip in statics), allocated
            # Traefik and primary services must still start with their reserved IPs.
            for container, expected in statics:
                run('docker', 'start', container)
                assert address(container) == expected, (container, address(container))
            print(f'PASS cycle {cycle + 1}: three Account pairs and a new endpoint started '
                  'before Traefik/primary services; all static IPs remained available', flush=True)
            run('docker', 'stop', '-t', '1', *dynamics, *(c for c, ip in statics))
            run('docker', 'rm', '-f', probe)
            created.remove(probe)
    finally:
        # Only IDs returned by successful test creation are eligible for cleanup.
        if created:
            run('docker', 'rm', '-f', *created)
        if network_id:
            run('docker', 'network', 'rm', network_id)
        print('Disposable test resources removed', flush=True)


if __name__ == '__main__':
    main()
