import copy
import json
from pathlib import Path
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
import account_production as production
import account_map_configuration as maps
import provision_local_account as local
import refresh_account_map as refresh


class AccountMapConfigurationTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.key = self.root / 'map-key'
        self.key.write_text('v1.public.synthetic-browser-key')
        self.settings = {'provider': 'amazon', 'region': 'us-east-2', 'style': 'Standard',
                         'maximum_zoom': 19, 'key_file': '/run/secrets/amazon-key'}
        self.primary = {'Mounts': [{'Destination': self.settings['key_file'], 'Source': str(self.key),
                                  'Type': 'bind', 'RW': False}]}
        self.config = {'primary_web': 'primary-web', 'primary_key': 'primary', 'project_prefix': 'member'}

    def read_settings(self):
        with patch.object(maps, 'run', return_value=SimpleNamespace(stdout=json.dumps(self.settings))), \
             patch.object(maps, 'inspect', return_value=self.primary):
            return production.primary_amazon_map(self.config)

    def test_inherits_effective_amazon_settings_without_exposing_key(self):
        settings = self.read_settings()
        self.assertEqual(settings['environment']['DNR_MAP_PROVIDER'], 'amazon')
        self.assertEqual(settings['environment']['DNR_MAP_MAXIMUM_ZOOM'], '19')
        self.assertEqual(settings['secret_file'], str(self.key))
        self.assertNotIn(self.key.read_text(), json.dumps(settings))

    def test_openstreetmap_does_not_require_or_share_a_key(self):
        self.settings['provider'] = 'openstreetmap'
        self.primary['Mounts'] = []
        self.assertIsNone(self.read_settings())

    def test_amazon_never_silently_falls_back_with_invalid_configuration(self):
        for problem in ['missing_mount', 'writable_mount', 'private_key', 'missing_file', 'invalid_region', 'invalid_style']:
            with self.subTest(problem=problem):
                original = copy.deepcopy((self.settings, self.primary))
                self.key.write_text('v1.public.synthetic-browser-key')
                if problem == 'missing_mount': self.primary['Mounts'] = []
                if problem == 'writable_mount': self.primary['Mounts'][0]['RW'] = True
                if problem == 'private_key': self.key.write_text('private-server-credential')
                if problem == 'missing_file': self.key.unlink()
                if problem == 'invalid_region': self.settings['region'] = '../region'
                if problem == 'invalid_style': self.settings['style'] = 'unknown'
                with self.assertRaises(ValueError): self.read_settings()
                self.settings, self.primary = original

    def test_map_configuration_changes_only_web_and_one_secret(self):
        original = {'services': {'web': {'image': 'qualified-web', 'environment': {'DNR_ACCOUNT_KEY': 'test-account'},
                    'secrets': ['platform_api_key']}, 'db': {'image': 'qualified-db'}, 'geocoder': {'environment': {}}},
                    'secrets': {'platform_api_key': {'file': '/private/member-key'}}}
        mapped = production.with_amazon_map(original, self.read_settings())
        self.assertEqual(mapped['services']['db'], original['services']['db'])
        self.assertEqual(mapped['services']['geocoder'], original['services']['geocoder'])
        self.assertEqual(mapped['services']['web']['image'], 'qualified-web')
        self.assertEqual(mapped['services']['web']['environment']['DNR_ACCOUNT_KEY'], 'test-account')
        self.assertEqual(mapped['secrets']['platform_api_key'], original['secrets']['platform_api_key'])
        self.assertNotIn('DNR_MAP_PROVIDER', original['services']['web']['environment'])
        self.assertEqual(mapped, production.with_amazon_map(mapped, self.read_settings()))

    def prepare_refresh(self):
        directory = self.root / 'var/accounts/test-account'
        directory.mkdir(parents=True)
        document = {'services': {'web': {'image': 'qualified-web', 'environment': {
            'DNR_ACCOUNT_KEY': 'test-account', 'DNR_PUBLIC_BASE_URL': 'https://example.com/a/test-account'}},
            'db': {'image': 'qualified-db'}, 'ingress': {'image': 'qualified-ingress'}},
            'x-moed-active-services': ['web', 'ingress'], 'x-moed-frozen-configuration': {}}
        runtime = directory / 'runtime.compose.json'
        runtime.write_text(json.dumps(document))
        metadata = {'mode': 'production', 'account_key': 'test-account', 'project': 'member-test-account',
                    'commit': 'a' * 40, 'runtime_sha256': production.sha(runtime)}
        (directory / 'deployment.json').write_text(json.dumps(metadata))
        return directory, document, metadata

    def run_refresh(self, apply=False, fail_probe=False, state='ready'):
        with patch.object(production, 'ROOT', self.root), patch.object(production, 'assert_primary'), \
             patch.object(production, 'control', return_value=[{'account_key': 'test-account', 'state': state}]), \
             patch.object(production, 'verify_images'), \
             patch.object(production, 'primary_amazon_map', return_value=self.read_settings()), \
             patch.object(production, 'run') as command, \
             patch.object(production, 'ready_probe', side_effect=RuntimeError('probe failed') if fail_probe else None):
            if fail_probe:
                with self.assertRaisesRegex(RuntimeError, 'probe failed'):
                    refresh.refresh(self.config, 'test-account', apply)
                result = None
            else:
                result = refresh.refresh(self.config, 'test-account', apply)
            return result, command.call_args_list

    def test_refresh_default_plans_without_writing_or_starting_services(self):
        directory, document, metadata = self.prepare_refresh()
        plan, calls = self.run_refresh()
        self.assertTrue(plan['changed'])
        self.assertFalse(plan['applied'])
        self.assertEqual(calls, [])
        self.assertEqual(json.loads((directory / 'runtime.compose.json').read_text()), document)
        self.assertEqual(json.loads((directory / 'deployment.json').read_text()), metadata)
        self.assertEqual(len(list(directory.iterdir())), 2)

    def test_refresh_retains_old_release_and_starts_only_web_without_dependencies(self):
        directory, document, metadata = self.prepare_refresh()
        plan, calls = self.run_refresh(apply=True)
        self.assertTrue(plan['applied'])
        revised = json.loads((directory / 'runtime.compose.json').read_text())
        self.assertEqual(revised['services']['db'], document['services']['db'])
        self.assertEqual(revised['services']['web']['image'], 'qualified-web')
        self.assertEqual(json.loads((directory / 'deployment.json').read_text())['commit'], metadata['commit'])
        commands = [c.args[0] for c in calls]
        self.assertEqual(len(commands), 2)
        self.assertEqual(commands[0][-2:], ['config', '--quiet'])
        self.assertEqual(commands[1][-8:], ['up', '-d', '--no-build', '--no-deps', '--wait', '--wait-timeout', '120', 'web'])
        recovery = Path(plan['recovery'])
        self.assertEqual(json.loads((recovery / 'runtime.compose.json').read_text()), document)
        self.assertEqual(json.loads((recovery / 'deployment.json').read_text()), metadata)

    def test_failed_health_restores_previous_runtime_and_checksum(self):
        directory, document, metadata = self.prepare_refresh()
        _, calls = self.run_refresh(apply=True, fail_probe=True)
        self.assertEqual(json.loads((directory / 'runtime.compose.json').read_text()), document)
        self.assertEqual(json.loads((directory / 'deployment.json').read_text()), metadata)
        self.assertEqual(len(calls), 3)

    def test_changed_saved_runtime_is_rejected_before_refresh(self):
        directory, _, _ = self.prepare_refresh()
        (directory / 'runtime.compose.json').write_text('{}')
        with self.assertRaisesRegex(ValueError, 'missing or changed'):
            self.run_refresh(apply=True)

    def test_archived_account_receives_settings_without_starting_any_service(self):
        directory, _, _ = self.prepare_refresh()
        plan, calls = self.run_refresh(apply=True, state='disabled')
        self.assertTrue(plan['applied'])
        self.assertFalse(plan['restart_web'])
        self.assertEqual(len(calls), 1)
        self.assertEqual(calls[0].args[0][-2:], ['config', '--quiet'])
        self.assertEqual(json.loads((directory / 'runtime.compose.json').read_text())['services']['web']['environment']['DNR_MAP_PROVIDER'], 'amazon')

    def test_completed_refresh_is_a_no_op(self):
        self.prepare_refresh()
        self.run_refresh(apply=True)
        plan, calls = self.run_refresh(apply=True)
        self.assertFalse(plan['changed'])
        self.assertEqual(calls, [])

    def test_all_targets_ready_and_archived_members_only(self):
        directory = self.root / '.git/dnr-deploy'
        directory.mkdir(parents=True)
        config_file = self.root / 'config.json'
        config_file.write_text(json.dumps(self.config))
        accounts = [{'account_key': key, 'state': state} for key, state in
                    [('primary', 'ready'), ('test-account', 'ready'), ('archived-account', 'disabled'),
                     ('new-account', 'provisioning'), ('removed-account', 'deleted')]]
        with patch.object(sys, 'argv', ['refresh_account_map.py', str(config_file), '--all']), \
             patch.object(production, 'ROOT', self.root), patch.object(production, 'validate_config', side_effect=lambda c: c), \
             patch.object(production, 'control', return_value=accounts), \
             patch.object(refresh, 'refresh', return_value={}) as update, patch('builtins.print'):
            refresh.main()
        self.assertEqual([c.args[1:] for c in update.call_args_list], [('test-account', False), ('archived-account', False)])

    def test_local_refresh_changes_only_map_and_starts_web_without_migrations(self):
        directory=self.root/'var/accounts/test-account';directory.mkdir(parents=True)
        (directory/'deployment.json').write_text(json.dumps({'project':'moed-account-test-account'}))
        original={'services':{'web':{'environment':{'DNR_ACCOUNT_KEY':'test-account'}},'db':{'image':'existing-db'}}}
        (directory/'compose.json').write_text(json.dumps(original))
        member={'Config':{'Labels':{'com.docker.compose.project':'moed-account-test-account'}},'State':{'Status':'running'}}
        settings=self.read_settings()
        with patch.object(local,'ROOT',self.root),patch.object(local,'local_primary'), \
             patch.object(maps,'primary_amazon_map',return_value=settings), \
             patch.object(local,'run',return_value=SimpleNamespace(stdout=json.dumps([member]))) as command,patch('builtins.print'):
            local.refresh_local_map(SimpleNamespace(primary_web='primary-web'),'test-account')
        revised=json.loads((directory/'compose.json').read_text())
        self.assertEqual(revised['services']['db'],original['services']['db'])
        self.assertEqual(revised['services']['web']['environment']['DNR_MAP_PROVIDER'],'amazon')
        self.assertEqual(command.call_args_list[-1].args[0][-8:],['up','-d','--no-build','--no-deps','--wait','--wait-timeout','120','web'])
        self.assertEqual(len(command.call_args_list),3)

    def test_local_refresh_rejects_production_runtime_before_modifying_it(self):
        directory,document,_=self.prepare_refresh()
        with patch.object(local,'ROOT',self.root),patch.object(local,'local_primary'):
            with self.assertRaisesRegex(ValueError,'non-local'):
                local.refresh_local_map(SimpleNamespace(primary_web='primary-web'),'test-account')
        self.assertEqual(json.loads((directory/'runtime.compose.json').read_text()),document)


if __name__ == '__main__':
    unittest.main()
