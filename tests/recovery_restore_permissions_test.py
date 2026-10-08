from pathlib import Path
import stat
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from recovery_restore_drill import make_source_readable


class RecoverySourcePermissions(unittest.TestCase):
    def test_private_extraction_becomes_readable_without_exposing_secrets(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'source'
            (source / 'src').mkdir(parents=True, mode=0o700)
            page = source / 'src/bootstrap.php'
            page.write_text('<?php')
            page.chmod(0o600)
            secret = root / 'secret'
            secret.write_text('test-only')
            secret.chmod(0o600)
            make_source_readable(source)
            self.assertEqual(stat.S_IMODE(page.stat().st_mode), 0o644)
            self.assertEqual(stat.S_IMODE(page.parent.stat().st_mode), 0o755)
            self.assertEqual(stat.S_IMODE(root.stat().st_mode), 0o700)
            self.assertEqual(stat.S_IMODE(secret.stat().st_mode), 0o600)


if __name__ == '__main__':
    unittest.main()
