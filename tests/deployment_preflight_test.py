"""Regression cases for conditions that must block a release before preparation."""
import copy
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / 'scripts'))
from deployment_preflight import account_issues, capacity_issues, network_issues, password_issues


class DeploymentPreflightTests(unittest.TestCase):
    def setUp(self):
        self.networks = [{'Name': 'edge', 'IPAM': {'Config': [
            {'Subnet': '172.29.255.0/24', 'IPRange': '172.29.255.128/25', 'Gateway': '172.29.255.1'}]}}]
        self.proxy = {'Name': '/traefik', 'State': {'Status': 'exited'}, 'NetworkSettings': {'Networks': {
            'edge': {'IPAddress': '', 'IPAMConfig': {'IPv4Address': '172.29.255.2'}}}}}

    def test_stopped_proxy_reservation_and_multiple_account_addresses(self):
        containers = [self.proxy]
        for number in (3, 4):
            member = copy.deepcopy(self.proxy)
            member['Name'] = '/account-' + str(number)
            member['NetworkSettings']['Networks']['edge']['IPAMConfig']['IPv4Address'] = '172.29.255.' + str(number)
            containers.append(member)
        self.assertEqual(network_issues(self.networks, containers), [])
        containers[-1]['NetworkSettings']['Networks']['edge']['IPAddress'] = '172.29.255.2'
        self.assertTrue(any('Duplicate' in issue for issue in network_issues(self.networks, containers)))

    def test_dynamic_pool_and_host_or_docker_subnet_overlap_block_release(self):
        unprotected = copy.deepcopy(self.networks)
        unprotected[0]['IPAM']['Config'][0].pop('IPRange')
        self.assertTrue(any('Protect static' in issue for issue in network_issues(unprotected, [self.proxy])))
        other = {'Name': 'another', 'IPAM': {'Config': [{'Subnet': '172.29.0.0/16'}]}}
        self.assertTrue(any('Overlapping' in issue for issue in network_issues(self.networks + [other], [])))
        route = {'dst': '172.29.0.0/16', 'dev': 'tun0'}
        self.assertTrue(any('host route' in issue for issue in network_issues(self.networks, [], [route])))
        route['dev'] = 'br-edge'
        self.assertEqual(network_issues(self.networks, [], [route]), [])

    def test_capacity_checks_block_low_disk_and_inodes(self):
        usage = SimpleNamespace(f_bavail=20 * 1024**3, f_frsize=1, f_files=100000, f_favail=20000)
        with patch('deployment_preflight.os.statvfs', return_value=usage):
            self.assertEqual(capacity_issues(['/source', '/docker']), [])
            usage.f_bavail = 1024
            self.assertIn('10 GiB', capacity_issues(['/source'])[0])
            usage.f_favail = 1
            self.assertTrue(any('inodes' in issue for issue in capacity_issues(['/source'])))

    def test_backup_password_rejects_missing_short_and_shared_files(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'password'
            self.assertTrue(password_issues(path))
            path.write_text('short'); path.chmod(0o600)
            self.assertTrue(password_issues(path))
            path.write_text('synthetic-backup-password'); path.chmod(0o644)
            self.assertTrue(password_issues(path))
            path.chmod(0o600)
            self.assertEqual(password_issues(path), [])

    def test_every_account_must_be_running_healthy_and_at_the_deployed_commit(self):
        containers = []
        for project in ('primary', 'test-account'):
            for service in ('web', 'db'):
                containers.append({'Name': '/' + project + '-' + service, 'Config': {'Labels': {
                    'com.docker.compose.project': project, 'com.docker.compose.service': service,
                    'org.opencontainers.image.revision': 'current'}},
                    'State': {'Status': 'running', 'Health': {'Status': 'healthy'}}})
        self.assertEqual(account_issues(containers, ['primary', 'test-account'], 'current'), [])
        containers[2]['Config']['Labels']['org.opencontainers.image.revision'] = 'old'
        self.assertTrue(any('parity' in issue for issue in account_issues(containers, ['primary', 'test-account'], 'current')))
        containers[3]['State']['Health']['Status'] = 'unhealthy'
        self.assertTrue(any('unhealthy' in issue for issue in account_issues(containers, ['test-account'], 'current')))
        containers[3]['State']['Status'] = 'exited'
        self.assertTrue(any('running db' in issue for issue in account_issues(containers, ['test-account'], 'current')))


if __name__ == '__main__':
    unittest.main()
