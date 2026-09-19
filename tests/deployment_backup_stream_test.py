import hashlib
import io
import json
from pathlib import Path
import sys
import tarfile
import tempfile
import unittest
from unittest.mock import patch
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / 'scripts'))
from deployment_backup import verify_file_stream, create_verified_backup

class NativeFileStreamTests(unittest.TestCase):
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
