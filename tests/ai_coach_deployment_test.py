import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent


class CoachDeployment(unittest.TestCase):
    def wrapper(self, key=False, hosts=False, custom=False):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'scripts').mkdir()
            (root / 'secrets').mkdir()
            shutil.copyfile(ROOT / 'scripts/compose_with_provenance.sh', root / 'scripts/compose_with_provenance.sh')
            docker = root / 'docker'
            docker.write_text('#!/usr/bin/env python3\nimport json,sys\nfrom pathlib import Path\nif "--environment" in sys.argv:\n print(Path(".env").read_text() if Path(".env").exists() else "")\nelse:\n print(json.dumps(sys.argv[1:]))\n')
            docker.chmod(0o755)
            key_path = root / ('custom-key' if custom else 'secrets/ai_coach_ssh_key')
            hosts_path = root / ('custom-hosts' if custom else 'secrets/ai_coach_known_hosts')
            if key:
                key_path.write_text('synthetic-private-key')
            if hosts:
                hosts_path.write_text('synthetic-host-key')
            if custom:
                (root / '.env').write_text('DNR_AI_COACH_SSH_KEY_FILE=./custom-key\nDNR_AI_COACH_KNOWN_HOSTS_FILE=./custom-hosts\n')
            env = {k: v for k, v in os.environ.items() if not k.startswith(('DNR_AI_COACH_', 'DNR_GEOAPIFY_', 'DNR_CLOUDFLARE_'))}
            env.update(PATH=str(root) + os.pathsep + os.environ['PATH'],
                       DNR_BUILD_COMMIT='a' * 40, DNR_BUILD_TIMESTAMP='2026-09-22T00:00:00Z')
            result = subprocess.run(['sh', str(root / 'scripts/compose_with_provenance.sh'),
                                     'production-ubuntu-proton-mattermost', 'config', '--quiet'],
                                    env=env, text=True, capture_output=True)
            self.assertNotIn('synthetic-private-key', result.stdout + result.stderr)
            return result

    def test_provisioned_identity_enables_workers_and_tunnel_on_every_release(self):
        result = self.wrapper(key=True, hosts=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        args = json.loads(result.stdout.splitlines()[-1])
        self.assertIn('docker-compose.ai-coach.yaml', args)
        self.assertEqual(args[args.index('--profile') + 1], 'coach')
        self.assertIn('docker-compose.proton-bridge.yaml', args)
        self.assertEqual(args[-2:], ['config', '--quiet'])

    def test_no_identity_preserves_disabled_feature(self):
        result = self.wrapper()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn('docker-compose.ai-coach.yaml', result.stdout)

    def test_custom_dotenv_paths_enable_coach_without_default_files(self):
        result = self.wrapper(key=True, hosts=True, custom=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('docker-compose.ai-coach.yaml', result.stdout)

    def test_unpinned_host_blocks_startup(self):
        result = self.wrapper(key=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('pinned SSH known hosts', result.stderr)


if __name__ == '__main__':
    unittest.main()
