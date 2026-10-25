"""Read-only s1 checks before spending time preparing and qualifying a release."""
import ipaddress
import json
import os
from pathlib import Path
import stat
import subprocess
import sys


def command(*args):
    result = subprocess.run(args, text=True, capture_output=True, timeout=30)
    if result.returncode:
        raise ValueError('Cannot run ' + ' '.join(args[:2]) + '; check host access and service health')
    return result.stdout.strip()


def network_issues(networks, containers, routes=()):
    issues, subnets = [], []
    for network in networks:
        configs = network.get('IPAM', {}).get('Config') or []
        for config in configs:
            if not config.get('Subnet'):
                continue
            subnet = ipaddress.ip_network(config['Subnet'])
            for name, other in subnets:
                if subnet.version == other.version and subnet.overlaps(other):
                    issues.append('Overlapping Docker subnets: ' + network['Name'] + ' / ' + name)
            subnets.append((network['Name'], subnet))
        assigned = {}
        for container in containers:  # Includes stopped containers and their static reservations.
            connection = container.get('NetworkSettings', {}).get('Networks', {}).get(network['Name'])
            if not connection:
                continue
            reserved = (connection.get('IPAMConfig') or {}).get('IPv4Address')
            for address in set(filter(None, [connection.get('IPAddress'), reserved])):
                if address in assigned and assigned[address] != container['Name']:
                    issues.append('Duplicate Docker address ' + address + ' on ' + network['Name'])
                assigned[address] = container['Name']
            if reserved:
                address = ipaddress.ip_address(reserved)
                pools = [c for c in configs if c.get('Subnet') and address in ipaddress.ip_network(c['Subnet'])]
                if not pools or any(not c.get('IPRange') or address in ipaddress.ip_network(c['IPRange']) for c in pools):
                    issues.append('Protect static address ' + reserved + ' from dynamic allocation on ' + network['Name'])
                if any(address in (ipaddress.ip_network(c['Subnet']).network_address,
                                   ipaddress.ip_network(c['Subnet']).broadcast_address)
                       or str(address) == c.get('Gateway') for c in pools):
                    issues.append('Static address uses a gateway or reserved subnet address: ' + reserved)
    for route in routes:
        if route.get('dst', 'default') == 'default' or route.get('dev', '').startswith(('docker', 'br-', 'veth')):
            continue
        host_subnet = ipaddress.ip_network(route['dst'], strict=False)
        for name, subnet in subnets:
            if subnet.version == host_subnet.version and subnet.overlaps(host_subnet):
                issues.append('Docker network ' + name + ' overlaps host route ' + str(host_subnet))
    return issues


def capacity_issues(paths):
    issues = []
    for path in dict.fromkeys(map(str, paths)):
        usage = os.statvfs(path)
        if usage.f_bavail * usage.f_frsize < 10 * 1024**3:
            issues.append('Free at least 10 GiB on the filesystem containing ' + path + ' before release preparation')
        if usage.f_files and usage.f_favail < 10000:
            issues.append('Free at least 10,000 inodes on the filesystem containing ' + path)
    return issues


def password_issues(path):
    if not path.is_file() or not 16 <= len(path.read_bytes().rstrip(b'\r\n')) <= 4096:
        return ['Configure the deployment backup password file (16–4096 bytes) before release preparation']
    if stat.S_IMODE(path.stat().st_mode) & 0o077:
        return ['Restrict the deployment backup password file to its owner (chmod 600)']
    return []


def account_issues(containers, projects, expected):
    issues = []
    for project in projects:
        members = [c for c in containers if (c.get('Config', {}).get('Labels') or {}).get('com.docker.compose.project') == project]
        for service in ('web', 'db'):
            replicas = [c for c in members if c['Config']['Labels'].get('com.docker.compose.service') == service]
            if not replicas or any(c['State']['Status'] != 'running' for c in replicas):
                issues.append('Restore running ' + service + ' service for ' + project + ' before releasing')
            for replica in replicas:
                if service == 'web' and replica['Config']['Labels'].get('org.opencontainers.image.revision') != expected:
                    # Compose labels may omit image provenance; the caller populates it from image inspection.
                    issues.append('Reconcile application commit parity for ' + project)
        for member in members:
            if member['State']['Status'] == 'running' and member['State'].get('Health', {}).get('Status', 'healthy') != 'healthy':
                issues.append('Resolve unhealthy service ' + member['Name'].lstrip('/'))
    return issues


def check_host(root, password):
    os.chdir(root)
    if command('git', 'branch', '--show-current') != 'main' or command(
            'git', 'status', '--porcelain', '--untracked-files=normal', '--', '.',
            ':!var/deployment/notice.json', ':!var/deployment/notice.lock', ':!var/deployment/notice.tmp'):
        raise ValueError('Resolve the dirty or non-main s1 checkout before release preparation')
    expected = command('git', 'rev-parse', 'HEAD')
    info = json.loads(command('docker', 'info', '--format', '{{json .}}'))
    issues = password_issues(password) + capacity_issues([root, info['DockerRootDir']])
    networks = json.loads(command('docker', 'network', 'inspect', *command('docker', 'network', 'ls', '-q').split()))
    containers = json.loads(command('docker', 'inspect', *command('docker', 'ps', '-aq').split()))
    issues += network_issues(networks, containers, json.loads(command('ip', '-j', '-4', 'route', 'show')))
    sys.path.insert(0, str(root / 'scripts'))
    from account_release import discover_members
    from deploy_release_host import account_release_configuration
    config_path = root / 'var/deployment/account-production/config.json'
    if not config_path.is_file():
        raise ValueError('Restore the canonical production Account configuration before release preparation')
    config = json.loads(config_path.read_text())
    primary = next(c for c in containers if c['Name'].lstrip('/') == config['primary_web'])
    account_release_configuration(root, primary['Config']['Env'])
    projects = [primary['Config']['Labels']['com.docker.compose.project']]
    members = discover_members(root, config)
    projects += [member['project'] for member in members]
    for member in members:
        if member['metadata']['commit'] != expected:
            issues.append('Reconcile saved application commit parity for ' + member['key'])
    # Container labels alone do not establish which image is actually running.
    revisions = {}
    for container in containers:
        labels = container.get('Config', {}).get('Labels') or {}
        if labels.get('com.docker.compose.project') in projects and labels.get('com.docker.compose.service') == 'web':
            image = container['Image']
            if image not in revisions:
                image_config = json.loads(command('docker', 'image', 'inspect', image))[0]['Config']
                revisions[image] = (image_config.get('Labels') or {}).get('org.opencontainers.image.revision')
            labels['org.opencontainers.image.revision'] = revisions[image]
    issues += account_issues(containers, projects, expected)
    if issues:
        raise ValueError('\n'.join(dict.fromkeys(issues)))
    return {'accounts': len(projects), 'networks': len(networks), 'containers_including_stopped': len(containers),
            'status': 'passed', 'commit': expected}


if __name__ == '__main__':
    try:
        print(json.dumps(check_host(Path(sys.argv[1]).resolve(), Path(sys.argv[2]).resolve()), sort_keys=True))
    except (ValueError, OSError, subprocess.SubprocessError, KeyError, StopIteration) as error:
        print('Deployment preflight blocked: ' + str(error), file=sys.stderr)
        sys.exit(1)
