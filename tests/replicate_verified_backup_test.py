import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / 'scripts'))
from replicate_verified_backup import inventory, replicate, SMB


class FakeSMB:
    def __init__(self, corrupt=False):
        self.files = {}
        self.published = False
        self.corrupt = corrupt

    def run(self, command):
        parts = command.split()
        if parts[0] == 'put':
            self.files[parts[2]] = Path(parts[1]).read_bytes()
        elif parts[0] == 'get':
            data = self.files[parts[1]]
            Path(parts[2]).write_bytes(b'corrupt' if self.corrupt else data)
        elif parts[0] == 'rename':
            self.published = True
            self.files = {name.replace(parts[1] + '/', parts[2] + '/', 1): data
                          for name, data in self.files.items()}


class ReplicationTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.directory = Path(self.temporary.name) / '20260927T220139Z-6d5adb73'
        self.directory.mkdir()
        self.archive = self.directory / 'database.sql.gz.dnrenc'
        self.archive.write_bytes(b'encrypted test fixture')
        self.receipt = dict(restore_verified=True, format='native-sql-gzip-secretstream-v2',
                            backup_path=str(self.archive), backup_sha256=hashlib.sha256(self.archive.read_bytes()).hexdigest())
        self.write_receipt()

    def write_receipt(self):
        (self.directory / 'receipt.json').write_text(json.dumps(self.receipt))

    def test_success_publishes_only_verified_copy_and_receipt(self):
        smb = FakeSMB()
        name = replicate(self.directory, smb)
        self.assertTrue(smb.published)
        self.assertEqual(smb.files[name + '/' + self.archive.name], self.archive.read_bytes())
        marker = json.loads(smb.files[name + '/replica.json'])
        self.assertTrue(marker['readback_verified'])
        self.assertEqual(marker['source_backup_id'], self.directory.name)

    def test_corrupt_remote_never_publishes(self):
        smb = FakeSMB(corrupt=True)
        with self.assertRaises(ValueError):
            replicate(self.directory, smb)
        self.assertFalse(smb.published)

    def test_corrupt_source_or_unverified_receipt_rejected(self):
        self.archive.write_bytes(b'bad')
        with self.assertRaises(ValueError):
            inventory(self.directory)
        self.receipt['restore_verified'] = False
        self.write_receipt()
        with self.assertRaises(ValueError):
            inventory(self.directory)

    def test_external_archive_rejected(self):
        self.receipt['backup_path'] = '/tmp/outside-backup.dnrenc'
        self.write_receipt()
        with self.assertRaises(ValueError):
            inventory(self.directory)

    def test_credentials_must_be_private_regular_file(self):
        credentials = Path(self.temporary.name) / 'credentials'
        credentials.write_text('fixture')
        credentials.chmod(0o644)
        with self.assertRaises(ValueError):
            SMB('//192.168.1.18/MOED_Backups', credentials)
        credentials.chmod(0o600)
        client = SMB('//192.168.1.18/MOED_Backups', credentials)
        self.assertIn('--client-protection=encrypt', client.command)
        self.assertNotIn('fixture', ' '.join(client.command))


if __name__ == '__main__':
    unittest.main()
