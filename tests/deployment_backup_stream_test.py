import hashlib
import io
import json
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / 'scripts'))
from deployment_backup import verify_file_stream, create_verified_backup, wait_for_restore_database

class NativeFileStreamTests(unittest.TestCase):
    def test_restore_waits_through_temporary_mysql_and_its_shutdown(self):
        states = [SimpleNamespace(returncode=0, stdout='1\n'),
                  SimpleNamespace(returncode=1, stdout=''),
                  SimpleNamespace(returncode=0, stdout='0\n')]
        with patch('deployment_backup.subprocess.run', side_effect=states) as run, \
                patch('deployment_backup.time.sleep') as sleep:
            wait_for_restore_database('isolated-restore')
        self.assertEqual(sleep.call_count, 2)
        self.assertTrue(all('SELECT @@GLOBAL.skip_networking' in call.args[0] for call in run.call_args_list))

    def test_restore_refuses_to_start_when_only_temporary_mysql_is_available(self):
        with patch('deployment_backup.subprocess.run', return_value=SimpleNamespace(returncode=0, stdout='1\n')), \
                patch('deployment_backup.time.sleep'), self.assertRaisesRegex(ValueError, 'finish initialization'):
            wait_for_restore_database('isolated-restore')

    def archive(self, name, data):
        stream = io.BytesIO()
        with tarfile.open(fileobj=stream, mode='w:gz') as archive:
            info = tarfile.TarInfo(name); info.size = len(data)
            archive.addfile(info, io.BytesIO(data))
        stream.seek(0)
        return stream

    def test_bounded_file_verification_rejects_missing_corrupt_and_unsafe_files(self):
        key = 'a' * 64; data = b'original file bytes'
        expected = {key: (len(data), hashlib.sha256(data).hexdigest())}
        verify_file_stream(self.archive('./' + key, data), expected)
        for name, contents in [(key, b'corrupted file bytes'), ('../' + key, data), ('b' * 64, data)]:
            with self.assertRaises(ValueError): verify_file_stream(self.archive(name, contents), expected)

    def test_restart_cleanup_is_scoped_and_keeps_encrypted_archives(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary); generated = root / '20260919T000000Z-abcdef12'; generated.mkdir()
            for name in ['database.sql.gz', 'verified.sql.gz', 'uploaded-files.tar.gz', 'verified-files.tar.gz', 'restore-root-password', 'database.sql.gz.dnrenc']:
                (generated / name).write_bytes(b'fixture')
            outside = root / 'user-folder'; outside.mkdir(); (outside / 'database.sql.gz').write_bytes(b'leave alone')
            with patch('deployment_backup.subprocess.check_output', return_value=''), patch('deployment_backup._create_verified_backup', return_value={'ok': True}):
                self.assertEqual(create_verified_backup('test', 'db', 'app', 'commit', 'version', 'password', root), {'ok': True})
            self.assertEqual([p.name for p in generated.iterdir()], ['database.sql.gz.dnrenc'])
            self.assertTrue((outside / 'database.sql.gz').is_file())

if __name__ == '__main__': unittest.main()
