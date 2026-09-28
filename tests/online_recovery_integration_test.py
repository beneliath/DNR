"""Online snapshot rehearsal against the runner's isolated, labelled project only."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
import time
import unittest
sys.path.insert(0, str(Path(__file__).resolve().parent.parent / 'scripts'))
from online_recovery_backup import create_online_backup, inspect
from deployment_backup import query
from recovery_host_files import host_recovery_files
from recovery_key_setup import protect_key
from replicate_verified_backup_test import FakeSMB


@unittest.skipUnless(os.getenv('DNR_INTEGRATION_TARGET') == 'disposable', 'isolated recovery fixture required')
class OnlineRecoveryTests(unittest.TestCase):
    def test_snapshot_while_writes_continue_and_files_restore(self):
        db, web = os.environ['DNR_RECOVERY_TEST_DB'], os.environ['DNR_RECOVERY_TEST_WEB']
        for container in (db, web):
            self.assertEqual(inspect(container)['Config']['Labels']['org.dnr.disposable-test'], os.environ['DNR_ISOLATION_TOKEN'])
        query(db, 'CREATE TABLE dnr.recovery_test_counter (id INT PRIMARY KEY, counter INT NOT NULL) ENGINE=InnoDB')
        query(db, 'INSERT INTO dnr.recovery_test_counter VALUES (1,0)')
        subprocess.run(['docker', 'exec', '-i', '-u', 'www-data', web, 'php'], input='''<?php
require '/var/www/html/bootstrap.php';
require '/var/www/html/persistent_file_helpers.php';
storePersistentFile($conn, str_repeat('recovery fixture', 100), 'recovery-test.txt', 'text/plain');
''', text=True, check=True)
        stopped = threading.Event()
        errors = []
        def writer():
            try:
                while not stopped.is_set():
                    query(db, 'UPDATE dnr.recovery_test_counter SET counter=counter+1 WHERE id=1')
                    stopped.wait(.15)
            except Exception as error:
                errors.append(error)
        thread = threading.Thread(target=writer)
        thread.start()
        try:
            with tempfile.TemporaryDirectory(prefix='dnr-online-rehearsal-') as folder:
                started = time.monotonic()
                receipt = create_online_backup(db, web, os.environ['DNR_RECOVERY_TEST_PASSWORD'], folder, 'test', 'test')
                fixture = Path(folder) / 'host-fixture'
                fixture.mkdir(); (fixture / 'secrets').mkdir()
                (fixture / '.env').write_text('TEST_CONFIGURATION=true\n')
                (fixture / 'VERSION').write_text('test')
                (fixture / 'secrets/test_key').write_text('synthetic recovery secret')
                subprocess.run(['git', 'init', '-q', str(fixture)], check=True)
                subprocess.run(['git', '-C', str(fixture), 'add', 'VERSION'], check=True)
                subprocess.run(['git', '-C', str(fixture), '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '-qm', 'fixture'], check=True)
                work = Path(receipt['backup_path']).parent
                recovery = host_recovery_files(fixture, work, inspect(web)['Image'], Path(os.environ['DNR_RECOVERY_TEST_PASSWORD']))
                self.assertEqual(len(recovery), 2)
                phrase = Path(folder) / 'test-passphrase'
                phrase.write_text('synthetic test recovery passphrase only')
                keyfolder = Path(folder) / 'key-fixture'; keyfolder.mkdir()
                self.assertEqual(len(protect_key(Path(os.environ['DNR_RECOVERY_TEST_PASSWORD']), phrase,
                    inspect(web)['Image'], FakeSMB(), keyfolder, 'recovery-key-fixture.dnrenc')), 64)
                self.assertTrue(receipt['restore_verified'])
                self.assertGreaterEqual(receipt['persistent_files']['file_count'], 1)
                self.assertIn('recovery_test_counter', receipt['database_state']['table_checksums'])
                self.assertGreater(int(query(db, 'SELECT counter FROM dnr.recovery_test_counter WHERE id=1')), 3)
                self.assertEqual(errors, [])
                self.assertTrue(inspect(web)['State']['Running'])
                directory = Path(receipt['backup_path']).parent
                self.assertEqual({p.name for p in directory.iterdir()}, {'receipt.json', 'database.sql.gz.dnrenc', 'uploaded-files.tar.gz.dnrenc', 'recovery-config.tar.gz.dnrenc', 'application-source.tar.dnrenc'})
                self.assertEqual(json.loads((directory / 'receipt.json').read_text()), receipt)
                print('Online snapshot, continued writes and file verification passed in %.1fs' % (time.monotonic() - started))
        finally:
            stopped.set(); thread.join(timeout=10)
            query(db, 'DROP TABLE dnr.recovery_test_counter')


if __name__ == '__main__':
    unittest.main()
