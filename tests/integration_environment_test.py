import json
import ipaddress
from datetime import datetime, timezone
from pathlib import Path
import sys
import unittest
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / 'scripts'))
from integration_environment import verify, allocate_test_networks, LABEL
from local_docker_storage import eligible_images, old_open_webui_images, remove_run_images
from storage_encryption_preflight import contains_encryption, require_encrypted_upload_storage

class IsolationTests(unittest.TestCase):
    def test_all_bridges_avoid_lan_and_existing_larger_subnets(self):
        existing = [{'IPAM': {'Config': [{'Subnet': '10.252.0.0/23'},
                                         {'Subnet': '10.252.2.128/25'},
                                         {'Subnet': '192.168.0.0/20'},
                                         {'Subnet': 'fd00::/64'}]}}, {'IPAM': {'Config': None}}]
        with patch('integration_environment.secrets.randbelow', return_value=0):
            networks = allocate_test_networks(existing)
        self.assertEqual(set(networks), {'backend', 'ingress', 'egress', 'default', 'scanning'})
        self.assertEqual(str(networks['backend']), '10.252.3.0/24')
        for name, network in networks.items():
            self.assertTrue(network.subnet_of(ipaddress.ip_network('10.252.0.0/16')))
            self.assertFalse(network.overlaps(ipaddress.ip_network('192.168.1.0/24')))
            self.assertTrue(all(not network.overlaps(other) for key, other in networks.items() if key != name))

    def test_exhausted_test_pool_fails_without_automatic_docker_fallback(self):
        with self.assertRaisesRegex(ValueError, 'five non-overlapping'):
            allocate_test_networks([{'IPAM': {'Config': [{'Subnet': '10.252.0.0/16'}]}}])

    def test_normal_project_is_refused_before_docker_access(self):
        with patch('subprocess.check_output') as command:
            with self.assertRaises(ValueError): verify('dnr', 'a' * 32)
            command.assert_not_called()

    def test_database_volumes_must_match_container_token(self):
        container = {'Name': 'test-db', 'Config': {'Labels': {LABEL: 'a' * 32}},
                     'Mounts': [{'Type': 'volume', 'Name': 'test-db-volume'}]}
        for labels in [{}, {LABEL: 'b' * 32}, {LABEL: 'a' * 32}]:
            with patch('subprocess.check_output', side_effect=['id\n', json.dumps([container]), json.dumps([{'Labels': labels}])]):
                if labels.get(LABEL) == 'a' * 32: verify('dnr-test-123', 'a' * 32)
                else:
                    with self.assertRaises(ValueError): verify('dnr-test-123', 'a' * 32)

    def test_lvm_does_not_count_as_encryption(self):
        self.assertFalse(contains_encryption([{'type': 'lvm', 'children': [{'type': 'part'}]}]))
        self.assertTrue(contains_encryption([{'type': 'lvm', 'children': [{'type': 'crypt', 'children': [{'type': 'part'}]}]}]))

class DisposableImageTests(unittest.TestCase):
    def test_pressure_cleanup_protects_named_images_recent_builds_and_stopped_projects(self):
        now = datetime(2026, 11, 8, tzinfo=timezone.utc)
        old = 1
        names = [f'dnr-test-{number:012x}-app:latest' for number in range(5)]
        images = [(ref, str(i), old) for i, ref in enumerate(names[:4])]
        images += [(names[4], '4', now.timestamp()), ('dnr-php-rollback:saved', '6', old),
                   ('dnr-app:local', '7', old), (names[0].replace(':latest', ':keep'), '8', old)]
        containers = [('1', 'alias:local', ''), ('other', names[2], ''), ('db', 'shared:local', names[3][:-11])]
        self.assertEqual(eligible_images(images, containers, now), [names[0]])

    def test_open_webui_retains_current_tag_references_and_two_unused_rollbacks(self):
        labels = {'org.opencontainers.image.source': 'https://github.com/open-webui/open-webui',
                  'org.opencontainers.image.title': 'open-webui'}
        images = [dict(Id=str(i), Created=i, Labels=labels) for i in range(8)]
        images[5]['RepoTags'] = ['ghcr.io/open-webui/open-webui:main']
        images[1]['RepoDigests'] = ['retained@sha256:example']
        images[0]['Labels'] = {}
        self.assertEqual(old_open_webui_images(images, [('3', 'retired', '')]), ['2', '4'])

    def test_teardown_removes_only_generated_tags_that_were_built(self):
        project = 'dnr-test-0123456789ab'
        app = project + '-app:latest'
        database = project + '-database:latest'
        with patch('local_docker_storage.docker', return_value=app + '\n' + database), patch('subprocess.run') as remove:
            remove_run_images(project, [app, project + '-ingress:latest'])
            remove.assert_called_once_with(['docker', 'image', 'rm', '--no-prune', app], check=True)


class StoragePreflightTests(unittest.TestCase):
    def test_bound_volume_uses_actual_device_and_requires_crypto(self):
        state = {'Mounts': [{'Destination': '/var/lib/dnr/files', 'Type': 'volume', 'Name': 'test_files'}]}
        volume = {'Mountpoint': '/var/lib/docker/volumes/test_files/_data', 'Options': {'type': 'none', 'device': '/srv/encrypted/files'}}
        topology = {'filesystems': [{'target': '/', 'source': '/dev/root'}, {'target': '/srv/encrypted', 'source': '/dev/mapper/files'}]}
        for kind in ['lvm', 'crypt']:
            output = [json.dumps([state]), json.dumps([volume]), json.dumps(topology), json.dumps({'blockdevices': [{'type': kind}]})]
            with patch('subprocess.check_output', side_effect=output):
                if kind == 'crypt': self.assertEqual(require_encrypted_upload_storage('web')['source'], '/dev/mapper/files')
                else:
                    with self.assertRaises(ValueError): require_encrypted_upload_storage('web')

    def test_first_filesystem_deployment_requires_preprovisioned_volume(self):
        state = {'Mounts': [], 'Config': {'Labels': {'com.docker.compose.project': 'moed'}}}
        with patch('subprocess.check_output', side_effect=[json.dumps([state]), RuntimeError('volume absent')]) as command:
            with self.assertRaises(RuntimeError): require_encrypted_upload_storage('web')
            self.assertEqual(command.call_args.args[0][-1], 'moed_uploaded_files')

if __name__ == '__main__': unittest.main()
