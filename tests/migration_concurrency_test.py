"""Real MySQL concurrency checks; opt in against a disposable Docker fixture only."""
import os
from pathlib import Path
import subprocess
import tempfile
import time
import unittest

@unittest.skipUnless(os.getenv('DNR_MIGRATION_TEST_CONTAINER'), 'requires disposable Docker MySQL fixture')
class MigrationConcurrency(unittest.TestCase):
    def setUp(self):
        self.container = os.environ['DNR_MIGRATION_TEST_CONTAINER']
        self.prefix = ['docker', 'exec', '-e', 'MYSQL_PWD=' + os.environ['DNR_MIGRATION_TEST_PASSWORD'], self.container]
        self.db = 'migration_test_' + str(os.getpid())
        self.sql('CREATE DATABASE `' + self.db + '`')
        self.runtime = '/tmp/' + self.db
        subprocess.run(['docker', 'exec', self.container, 'mkdir', '-p', self.runtime], check=True)
        self.write('order.txt', '001.sql\n')
        self.write('001.sql', "CREATE TABLE marker (id INT);\nDO SLEEP(3);\nINSERT INTO marker VALUES (1);\n")
        # Grants also use the owning connection; this fixture writes a harmless statement.
        self.write('grants.sh', '#!/bin/sh\nprintf "SELECT 1;\\n" > "$DNR_PRIVILEGE_SQL_FILE"\n')
        self.command = ['docker','exec','-e','MYSQL_ROOT_PASSWORD='+os.environ['DNR_MIGRATION_TEST_PASSWORD'],
            '-e','MYSQL_DATABASE='+self.db,'-e','MYSQL_USER=dnruser',
            '-e','DNR_MIGRATION_DIRECTORY='+self.runtime,'-e','DNR_PRIVILEGE_SCRIPT='+self.runtime+'/grants.sh',
            self.container,'sh',os.getenv('DNR_MIGRATION_TEST_SCRIPT','/opt/dnr/bin/migrate')]
    def sql(self, value):
        return subprocess.check_output(self.prefix + ['mysql','-uroot','-Nse',value], text=True).strip()
    def write(self, name, value):
        subprocess.run(['docker','exec','-i',self.container,'sh','-c','cat > "$1"','fixture',self.runtime+'/'+name], input=value, text=True, check=True)
    def waitApplying(self, process):
        for _ in range(60):
            if process.poll() is not None:
                self.fail('Migrator exited before entering its body: ' + process.communicate()[0])
            count=self.sql("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='"+self.db+"' AND table_name='marker'")
            if count == '1': return
            time.sleep(.1)
        self.fail('First migration did not enter body')
    def test_second_migrator_waits_and_does_not_repeat_ddl(self):
        first = subprocess.Popen(self.command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        self.waitApplying(first)
        second = subprocess.Popen(self.command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        time.sleep(.3)
        self.assertIsNone(second.poll(), 'Second migrator bypassed lock')
        self.assertEqual(first.wait(timeout=15),0,first.communicate()[0])
        self.assertEqual(second.wait(timeout=15),0,second.communicate()[0])
        self.assertEqual(self.sql('SELECT COUNT(*) FROM '+self.db+'.marker'),'1')
    def test_timeout_refuses_body(self):
        first = subprocess.Popen(self.command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        self.waitApplying(first)
        timed = self.command.copy(); timed[2:2] = ['-e','DNR_MIGRATION_LOCK_TIMEOUT=0']
        second = subprocess.run(timed, capture_output=True, text=True)
        self.assertNotEqual(second.returncode,0)
        self.assertIn('Could not obtain',second.stderr)
        self.assertEqual(first.wait(timeout=15),0,first.communicate()[0])
    def test_connection_loss_stops_and_blocks_retry(self):
        first = subprocess.Popen(self.command, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, text=True)
        self.waitApplying(first)
        holder = self.sql("SELECT IS_USED_LOCK('dnr_"+self.db+"_migrations')")
        self.sql('KILL '+holder)
        first.communicate(timeout=15)
        self.assertNotEqual(first.returncode,0)
        self.assertEqual(self.sql('SELECT COUNT(*) FROM '+self.db+'.marker'),'0')
        retry = subprocess.run(self.command, capture_output=True, text=True)
        self.assertNotEqual(retry.returncode,0)
        self.assertIn('operator review',retry.stderr)
    def tearDown(self):
        self.sql('DROP DATABASE IF EXISTS `'+self.db+'`')
        subprocess.run(['docker','exec',self.container,'rm','-rf',self.runtime],check=True)

if __name__ == '__main__': unittest.main()
