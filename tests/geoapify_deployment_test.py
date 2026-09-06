import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent


class GeoapifyDeployment(unittest.TestCase):
    def run_wrapper(self, *, key=False, provider=None, custom=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'scripts').mkdir()
            (root / 'secrets').mkdir()
            shutil.copyfile(ROOT / 'scripts/compose_with_provenance.sh', root / 'scripts/compose_with_provenance.sh')
            docker = root / 'docker'
            docker.write_text('#!/usr/bin/env python3\nimport json,sys\nprint(json.dumps(sys.argv[1:]))\n')
            docker.chmod(0o755)
            env = dict(os.environ, PATH=str(root) + os.pathsep + os.environ['PATH'],
                       DNR_BUILD_COMMIT='a' * 40, DNR_BUILD_TIMESTAMP='2026-09-06T00:00:00Z')
            env.pop('DNR_GEOCODER_PROVIDER', None)
            env.pop('DNR_GEOAPIFY_API_KEY_FILE', None)
            if provider:
                env['DNR_GEOCODER_PROVIDER'] = provider
            key_path = root / ('custom-key' if custom else 'secrets/geoapify_api_key')
            if custom:
                env['DNR_GEOAPIFY_API_KEY_FILE'] = str(key_path)
            if key:
                key_path.write_text('synthetic-test-secret')
            result = subprocess.run(['sh', str(root / 'scripts/compose_with_provenance.sh'),
                                     'production-ubuntu-proton-mattermost', 'config', '--quiet'],
                                    env=env, text=True, capture_output=True)
            self.assertNotIn('synthetic-test-secret', result.stdout + result.stderr)
            return result

    def test_provisioned_key_keeps_geoapify_across_production_deployments(self):
        for custom in (False, True):
            with self.subTest(custom=custom):
                result = self.run_wrapper(key=True, custom=custom)
                self.assertEqual(result.returncode, 0, result.stderr)
                args = json.loads(result.stdout.splitlines()[-1])
                self.assertIn('docker-compose.geoapify.yaml', args)
                self.assertIn('docker-compose.proton-bridge.yaml', args)
                self.assertIn('docker-compose.mattermost.yaml', args)
                self.assertEqual(args[-4:], ['-f', 'docker-compose.geoapify.yaml', 'config', '--quiet'])

    def test_without_key_or_with_explicit_opt_out_existing_provider_is_preserved(self):
        for options in ({}, {'key': True, 'provider': 'nominatim'}):
            with self.subTest(options=options):
                result = self.run_wrapper(**options)
                self.assertEqual(result.returncode, 0, result.stderr)
                self.assertNotIn('docker-compose.geoapify.yaml', result.stdout)

    def test_required_key_missing_blocks_compose(self):
        result = self.run_wrapper(provider='geoapify')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('nonempty Geoapify key file is required', result.stderr)


if __name__ == '__main__':
    unittest.main()
