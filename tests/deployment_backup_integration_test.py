"""Rehearse the deployment backup only in an explicitly disposable CI database."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'scripts'))
from deployment_backup import create_verified_backup


@unittest.skipUnless(os.getenv('DNR_INTEGRATION_TARGET') == 'disposable'
                     and os.getenv('DNR_DEPLOYMENT_BACKUP_CONTAINER'),
                     'requires the isolated deployment-backup fixture')
class DeploymentBackupIntegration(unittest.TestCase):
    def test_restore_and_encryption_verification(self):
        container = os.environ['DNR_DEPLOYMENT_BACKUP_CONTAINER']
        image = subprocess.check_output(['docker', 'inspect', '--format', '{{.Image}}', container], text=True).strip()
        commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
        with tempfile.TemporaryDirectory(prefix='dnr-backup-rehearsal-') as directory:
            receipt = create_verified_backup(container, image, os.environ['DNR_DEPLOYMENT_BACKUP_APP_IMAGE'],
                commit, (ROOT / 'VERSION').read_text().strip(),
                os.environ['DNR_DEPLOYMENT_BACKUP_PASSWORD_FILE'], directory)
            self.assertTrue(receipt['restore_verified'])
            self.assertTrue(receipt['database_state']['table_checksums'])
            archive = Path(receipt['backup_path'])
            self.assertTrue(archive.is_file())
            self.assertEqual(json.loads(archive.with_name('receipt.json').read_text()), receipt)
            self.assertFalse(archive.with_name('database.sql.gz').exists())
            self.assertFalse(archive.with_name('verified.sql.gz').exists())


if __name__ == '__main__': unittest.main()
