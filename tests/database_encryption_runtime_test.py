"""Exercise encryption conversion/restart in an explicitly disposable Compose database."""
import json
import os
from pathlib import Path
import subprocess
import sys
import time

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from deployment_backup import query, fingerprint

def main():
    project = os.environ.get('DNR_ENCRYPTION_TEST_PROJECT', '')
    if os.environ.get('DNR_INTEGRATION_TARGET') != 'disposable' or not project:
        print('Database encryption runtime tests skipped (explicit disposable project required).')
        return
    container = project + '-db-1'
    compose = ['docker', 'compose', '-p', project, '-f', 'docker-compose.yaml', '-f', 'docker-compose.dev.yaml']

    def verify():
        assert query(container, 'SELECT @@default_table_encryption AND @@innodb_redo_log_encrypt AND @@innodb_undo_log_encrypt AND @@binlog_encryption AND @@table_encryption_privilege_check') == '1'
        assert query(container, "SELECT DEFAULT_ENCRYPTION FROM information_schema.schemata WHERE SCHEMA_NAME='dnr'") == 'YES'
        assert query(container, "SELECT COUNT(*) FROM information_schema.innodb_tablespaces WHERE (NAME LIKE 'dnr/%' OR NAME='mysql') AND ENCRYPTION <> 'Y'") == '0'
        assert all(row.split('\t')[-1] == 'Yes' for row in query(container, 'SHOW BINARY LOGS').splitlines())
        assert query(container, 'SELECT @@innodb_temp_tablespaces_dir').startswith('/tmp/')

    query(container, "CREATE TABLE dnr.encryption_fixture_parent (id INT PRIMARY KEY, secret TEXT, FULLTEXT(secret)) ENGINE=InnoDB ENCRYPTION='N'; CREATE TABLE dnr.encryption_fixture_child (id INT PRIMARY KEY, parent_id INT, FOREIGN KEY(parent_id) REFERENCES dnr.encryption_fixture_parent(id)) ENGINE=InnoDB ENCRYPTION='N'; INSERT INTO dnr.encryption_fixture_parent VALUES(1,'fictional private location and people'); INSERT INTO dnr.encryption_fixture_child VALUES(1,1)")
    try:
        before = fingerprint(container)
        subprocess.run(compose + ['run', '--rm', '--no-deps', 'migrator'], check=True)
        verify()
        assert fingerprint(container) == before, 'Encryption conversion changed application rows'
        subprocess.run(compose + ['restart', 'db'], check=True)
        for _ in range(60):
            if subprocess.run(['docker', 'exec', container, 'mysqladmin', 'ping', '--silent'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
                break
            time.sleep(1)
        verify()
        assert fingerprint(container) == before, 'Restart changed application rows'
        query(container, "CREATE TABLE dnr.encryption_fixture_new (id INT PRIMARY KEY); INSERT INTO dnr.encryption_fixture_new VALUES(1)")
        verify()
        print('Encryption conversion, full-text/foreign-key tables, default encryption, encrypted logs, and restart tests passed.')
    finally:
        query(container, 'DROP TABLE IF EXISTS dnr.encryption_fixture_new; DROP TABLE dnr.encryption_fixture_child; DROP TABLE dnr.encryption_fixture_parent')

if __name__ == '__main__':
    main()
