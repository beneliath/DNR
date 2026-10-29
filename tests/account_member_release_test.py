import copy
import importlib
import json
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
import account_release as release
import account_production as production
import provision_local_account as provisioning
from deploy_release_host import reload_account_operators, active_account_worker


class MemberReleaseTest(unittest.TestCase):
    def test_worker_reload_targets_only_an_active_matching_checkout_service(self):
        root = Path('/qualified/moed')
        for invocation, allowed in [('/qualified/moed', True), ('/another/moed', False)]:
            result = SimpleNamespace(returncode=0, stdout='ActiveState=active\nType=simple\nExecStart={ argv[]=/usr/bin/python3 '
                + invocation + '/scripts/account_production.py ' + invocation + '/var/deployment/account-production/config.json --apply --watch ; }\n')
            with patch('deploy_release_host.subprocess.run', return_value=result):
                if allowed:
                    self.assertEqual(active_account_worker(root), ['systemctl','--user','restart','moed-account-worker.service'])
                else:
                    with self.assertRaisesRegex(ValueError, 'does not match'): active_account_worker(root)
        with patch('deploy_release_host.subprocess.run', return_value=SimpleNamespace(returncode=0, stdout='ActiveState=inactive\n')):
            self.assertIsNone(active_account_worker(root))

    def test_release_reloads_previous_checkout_control_adapter_after_fast_forward(self):
        names = ['provision_local_account', 'integration_environment', 'account_lifecycle_local',
            'account_ingress_security', 'account_map_configuration', 'account_production']
        with tempfile.TemporaryDirectory() as folder, patch.dict(sys.modules), patch.object(sys, 'path', list(sys.path)):
            root = Path(folder); scripts = root / 'scripts'; scripts.mkdir()
            for name in names:
                sys.modules.pop(name, None)
                source = 'from provision_local_account import control\n' if name in ['account_lifecycle_local', 'account_production'] else 'SOURCE = "previous"\n'
                (scripts / (name + '.py')).write_text(source)
            adapter = scripts / 'provision_local_account.py'
            adapter.write_text('def control(container, action, key="", value=""):\n    return "previous"\n')
            sys.path.insert(0, str(scripts))
            previous = importlib.import_module('account_production')
            lifecycle = importlib.import_module('account_lifecycle_local')
            with self.assertRaises(TypeError): previous.control('web', 'probe', 'member', 'url', 'commit')
            adapter.write_text('def control(container, action, key="", value="", expected_commit=""):\n    return "qualified:" + expected_commit\n')
            reload_account_operators(root)
            self.assertEqual(previous.control('web', 'probe', 'member', 'url', 'commit'), 'qualified:commit')
            self.assertEqual(lifecycle.control('web', 'probe', 'member', 'url', 'commit'), 'qualified:commit')

    def fixture(self, root):
        directory = root / 'var/accounts/member-account'
        frozen = directory / 'runtime-config'
        frozen.mkdir(parents=True)
        for name in ['migrate.sh', 'configure_database_privileges.sh']:
            path = frozen / name
            path.write_text('old ' + name)
            path.chmod(0o444)
            (root / 'scripts').mkdir(exist_ok=True)
            (root / 'scripts' / name).write_text('new ' + name)
        (frozen / 'migrations').mkdir()
        (frozen / 'migrations/old.sql').write_text('original schema')
        (root / 'migrations').mkdir()
        (root / 'migrations/old.sql').write_text('original schema')
        (root / 'migrations/new.sql').write_text('qualified new schema')
        document = {'services': {'web': {'image': 'old-app', 'environment': {'DNR_ACCOUNT_KEY': 'member-account',
            'DNR_PUBLIC_BASE_URL': 'https://example.test/a/member-account', 'SETTING': 'private value'}},
            'db': {'image': 'old-db'}, 'ingress': {'image': 'old-ingress'},
            'migrator': {'image': 'old-db', 'volumes': [
                {'type': 'bind', 'source': str(frozen / 'migrations'), 'target': '/opt/dnr/migrations', 'read_only': True},
                {'type': 'bind', 'source': str(frozen / 'migrate.sh'), 'target': '/opt/dnr/bin/migrate', 'read_only': True},
                {'type': 'bind', 'source': str(frozen / 'configure_database_privileges.sh'), 'target': '/opt/dnr/bin/configure_database_privileges', 'read_only': True}]},
            'file-migrator': {'image': 'old-app'}, 'scanner': {'image': 'unchanged-third-party'}},
            'networks': {'edge': {'external': True, 'name': 'protected-edge'}},
            'secrets': {'private-key': {'file': str(directory / 'private-key')}},
            'x-moed-active-services': ['web', 'ingress'], 'x-moed-live-binds': [],
            'x-moed-frozen-configuration': {str(p.relative_to(directory)): production.configuration_digest(p)
                for p in [frozen / 'migrations', frozen / 'migrate.sh', frozen / 'configure_database_privileges.sh']}}
        metadata = {'commit': '1' * 40, 'mode': 'production', 'project': 'member-project', 'networks': {'backend': '10.252.4.0/24'}}
        qualified = {'commit': 'a' * 40, 'version': '2.5.11', 'images': {'app': 'qualified-app', 'database': 'qualified-db', 'ingress': 'qualified-ingress'}}
        member = dict(key='member-account', project='member-project', directory=directory, document=document,
            metadata=metadata, active=['web', 'ingress'], command=['compose'], protected_identity='unchanged',
            backup={'restore_verified': True})
        return member, qualified

    def test_staging_preserves_settings_and_topology_and_original_recovery_snapshot(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); member, qualified = self.fixture(root)
            original = copy.deepcopy(member['document'])
            staged = release.stage_runtime(root, member['directory'], original, qualified, 'release-time')
            self.assertEqual(original, member['document'])
            self.assertEqual(staged['networks'], original['networks'])
            self.assertEqual(staged['secrets'], original['secrets'])
            self.assertEqual(staged['x-moed-active-services'], original['x-moed-active-services'])
            self.assertEqual(staged['services']['web']['environment']['SETTING'], 'private value')
            self.assertEqual(staged['services']['web']['image'], 'qualified-app')
            self.assertEqual(staged['services']['db']['image'], 'qualified-db')
            self.assertEqual(staged['services']['scanner']['image'], 'unchanged-third-party')
            production.verify_frozen_configuration(member['directory'], staged)
            self.assertEqual((member['directory'] / 'runtime-config/migrate.sh').read_text(), 'old migrate.sh')
            migration = Path(staged['services']['migrator']['volumes'][0]['source'])
            self.assertTrue((migration / 'new.sql').is_file())
            grants = Path(staged['services']['migrator']['volumes'][2]['source'])
            self.assertEqual(grants.read_text(), 'new configure_database_privileges.sh')
            self.assertEqual(release.stage_runtime(root, member['directory'], original, qualified, 'release-time'), staged)
            grants.write_text('tampered staging')
            with self.assertRaisesRegex(ValueError, 'differs from the qualified'):
                release.stage_runtime(root, member['directory'], original, qualified, 'release-time')

    def test_upgrade_requires_verified_backup_before_any_changes(self):
        member = {'backup': {'restore_verified': False}}
        with patch.object(release, 'stage_runtime') as staged, patch.object(release, 'compose') as compose:
            with self.assertRaisesRegex(ValueError, 'restore-verified'):
                release.apply_members(Path('/unused'), [member], {}, '')
            staged.assert_not_called(); compose.assert_not_called()

    def test_upgrade_applies_qualified_runtime_and_ordered_migrations_with_writers_paused(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); member, qualified = self.fixture(root)
            with patch.object(production, 'qualify_runtime') as qualify, patch.object(release, 'compose') as compose, \
                 patch.object(release, 'protected_identity', return_value='unchanged'):
                release.apply_members(root, [member], qualified, 'release-time')
            qualify.assert_called_once()
            commands = [call.args[1:] for call in compose.call_args_list]
            self.assertEqual(commands, [('up', '-d', '--no-build', '--no-deps', '--wait', 'db'),
                ('run', '--rm', '--no-deps', 'migrator'), ('run', '--rm', '--no-deps', 'file-migrator')])
            metadata = json.loads((member['directory'] / 'deployment.json').read_text())
            self.assertEqual(metadata['commit'], qualified['commit'])
            self.assertEqual(metadata['runtime_sha256'], production.sha(member['directory'] / 'runtime.compose.json'))
            self.assertEqual(metadata['networks'], {'backend': '10.252.4.0/24'})

    def test_public_version_mismatch_cannot_complete_member_release(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); member, qualified = self.fixture(root)
            member['document']['services']['web']['image'] = 'qualified-app'
            member['document']['services']['db']['image'] = 'qualified-db'
            member['document']['services']['ingress']['image'] = 'qualified-ingress'
            def inspect(identifier):
                if identifier.startswith('qualified-'): return {'Id': identifier + '-id'}
                return {'Image': identifier + '-id', 'State': {'Health': {'Status': 'healthy'}}}
            def compose(item, *args):
                if args[:2] == ('ps', '-aq'): return item['document']['services'][args[2]]['image']
                return ''
            # Container names are distinct from their corresponding image refs.
            def state(identifier):
                if identifier.startswith('qualified-'): return {'Id': identifier + '-id',
                    'Image': identifier + '-id', 'State': {'Health': {'Status': 'healthy'}}}
                return inspect(identifier)
            import account_lifecycle_local
            for version in ['2.5.10', '2.5.11']:
                def public_response(command, **_):
                    if command[-1].endswith('/login.php'):
                        return '<input name="username"><input name="password">'
                    return json.dumps({'status': 'ready', 'version': version})
                with self.subTest(version=version), patch.object(release, 'compose', side_effect=compose), \
                     patch.object(account_lifecycle_local, 'inspect', side_effect=state), \
                     patch.object(provisioning, 'run', return_value=SimpleNamespace(stdout='{"ok":true}')) as control, \
                     patch.object(release.subprocess, 'check_output', side_effect=public_response):
                    if version == '2.5.10':
                        with self.assertRaisesRegex(ValueError, 'readiness/version'):
                            release.start_and_verify_members([member], {'primary_web': 'primary'}, qualified)
                    else:
                        result = release.start_and_verify_members([member], {'primary_web': 'primary'}, qualified)
                        self.assertEqual(result[0]['commit'], qualified['commit'])
                        self.assertIsInstance(result[0]['public_ready_at'], float)
                    self.assertEqual(control.call_args.args[0], ['docker', 'exec', '-i', 'primary',
                        'php', '/dev/stdin', 'probe', member['key'],
                        member['document']['services']['web']['environment']['DNR_PUBLIC_BASE_URL'], qualified['commit']])
                    self.assertEqual(control.call_args.kwargs['input'],
                        (provisioning.ROOT / 'scripts/account_control.php').read_text())

    def test_control_keeps_legacy_arguments_without_a_release_commit(self):
        with patch.object(provisioning, 'run', return_value=SimpleNamespace(stdout='{"ok":true}')) as run:
            self.assertEqual(provisioning.control('primary', 'directory'), {'ok': True})
            self.assertEqual(run.call_args.args[0], ['docker', 'exec', '-i', 'primary',
                'php', '/dev/stdin', 'directory', '', ''])
            provisioning.control('primary', 'ready', 'member-account', 'https://example.test/a/member-account')
            self.assertEqual(run.call_args.args[0], ['docker', 'exec', '-i', 'primary',
                'php', '/dev/stdin', 'ready', 'member-account', 'https://example.test/a/member-account'])

    def test_authentication_or_settings_change_stops_upgrade(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder); member, qualified = self.fixture(root)
            with patch.object(production, 'qualify_runtime'), patch.object(release, 'compose'), \
                 patch.object(release, 'protected_identity', return_value='changed'):
                with self.assertRaisesRegex(ValueError, 'authentication identities changed'):
                    release.apply_members(root, [member], qualified, 'release-time')

    def test_wrong_running_image_stops_before_public_probe(self):
        member = {'active': ['web'], 'document': {'services': {'db': {'image': 'qualified-db'}, 'web': {'image': 'qualified-app'}}}}
        import account_lifecycle_local
        def inspect(identifier):
            return {'Id': 'expected-image'} if identifier.startswith('qualified-') else {'Image': 'old-image', 'State': {'Health': {'Status': 'healthy'}}}
        with patch.object(release, 'compose', return_value='container'), \
             patch.object(account_lifecycle_local, 'inspect', side_effect=inspect), patch.object(production, 'control') as probe:
            with self.assertRaisesRegex(ValueError, 'image verification'):
                release.start_and_verify_members([member], {}, {})
            probe.assert_not_called()


if __name__ == '__main__': unittest.main()
