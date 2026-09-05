"""Create an encrypted, restore-verified native backup before changing an active release."""
import datetime
import gzip
import hashlib
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import time

ROOT_CLIENT = '''if [ -n "${MYSQL_ROOT_PASSWORD_FILE:-}" ]; then
  MYSQL_PWD=$(cat "$MYSQL_ROOT_PASSWORD_FILE")
else MYSQL_PWD=${MYSQL_ROOT_PASSWORD:-}; fi
export MYSQL_PWD
exec "$@"'''

def root_command(container, *args):
    return ['docker', 'exec', '-i', container, 'sh', '-c', ROOT_CLIENT, 'database-backup', *args]

def query(container, sql):
    return subprocess.check_output(root_command(container, 'mysql', '-uroot', '-NBr', '-e', sql), text=True).strip()

def fingerprint(container):
    tables = query(container, "SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA='dnr' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME").splitlines()
    if not tables or any(not name.replace('_', '').isalnum() for name in tables):
        raise ValueError('Unexpected database table inventory')
    counts = {name: int(query(container, 'SELECT COUNT(*) FROM dnr.`' + name + '`')) for name in tables}
    checksums = {name: query(container, 'CHECKSUM TABLE dnr.`' + name + '`').split('\t')[-1] for name in tables}
    if any(value == 'NULL' for value in checksums.values()):
        raise ValueError('A database table could not be verified with CHECKSUM TABLE')
    migrations = query(container, 'SELECT migration_name, checksum, state FROM dnr.schema_migrations ORDER BY migration_name')
    triggers = query(container, "SELECT TRIGGER_NAME FROM information_schema.triggers WHERE TRIGGER_SCHEMA='dnr' ORDER BY TRIGGER_NAME")
    routines = query(container, "SELECT ROUTINE_NAME FROM information_schema.routines WHERE ROUTINE_SCHEMA='dnr' ORDER BY ROUTINE_NAME")
    return dict(row_counts=counts, table_checksums=checksums, migrations=migrations, triggers=triggers, routines=routines)

def sha(path):
    with path.open('rb') as handle: return hashlib.file_digest(handle, 'sha256').hexdigest()

def create_verified_backup(db_container, database_image, app_image, previous_commit, previous_version, password_file, directory):
    """Writers must already be paused. No active image, checkout, or schema is changed here."""
    password_file = Path(password_file).resolve()
    if not password_file.is_file() or len(password_file.read_bytes().rstrip(b'\r\n')) < 16:
        raise ValueError('Configure a private backup password file with at least 16 characters before deployment')
    directory = Path(directory).resolve()
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    directory.chmod(0o700)
    backup_id = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + secrets.token_hex(4)
    working = directory / backup_id
    working.mkdir(mode=0o700)
    archive = working / 'database.sql.gz'
    encrypted = working / 'database.sql.gz.dnrenc'
    verified_plaintext = working / 'verified.sql.gz'
    root_password = working / 'restore-root-password'
    root_password.write_text(secrets.token_hex(32)); root_password.chmod(0o600)
    restore_container = 'dnr-restore-check-' + backup_id.lower()
    try:
        before = fingerprint(db_container)
        with (working / 'dump.log').open('wb') as error, archive.open('xb') as output:
            archive.chmod(0o600)
            dump = subprocess.Popen(root_command(db_container, 'mysqldump', '-uroot', '--single-transaction',
                '--routines', '--triggers', '--events', '--hex-blob', '--set-gtid-purged=OFF', '--no-tablespaces', 'dnr'), stdout=subprocess.PIPE, stderr=error)
            try:
                with gzip.GzipFile(fileobj=output, mode='wb', mtime=0) as compressed:
                    shutil.copyfileobj(dump.stdout, compressed, 1048576)
                if dump.wait() != 0: raise ValueError('Database backup failed; see private dump.log')
            finally:
                dump.stdout.close()
                if dump.poll() is None: dump.terminate()
                dump.wait()
        if before != fingerprint(db_container): raise ValueError('Database changed during the deployment backup')
        # Use the current database image, without network access or host-published ports.
        subprocess.run(['docker','run','-d','--name',restore_container,'--network','none','--memory','2g',
            '--mount','type=bind,src='+str(root_password)+',dst=/run/secrets/root-password,readonly',
            '-e','MYSQL_ROOT_PASSWORD_FILE=/run/secrets/root-password','-e','MYSQL_DATABASE=dnr',
            database_image,'--max-allowed-packet=256M','--skip-log-bin'], check=True, stdout=subprocess.DEVNULL)
        for attempt in range(90):
            ready = subprocess.run(root_command(restore_container,'mysql','-uroot','dnr','-e','SELECT 1'),stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            if ready.returncode == 0: break
            time.sleep(2)
        else: raise ValueError('Disposable backup verification database did not become ready')
        with gzip.open(archive,'rb') as source, (working/'restore.log').open('wb') as log:
            restore = subprocess.Popen(root_command(restore_container,'mysql','-uroot','dnr'),stdin=subprocess.PIPE,stdout=log,stderr=log)
            try:
                shutil.copyfileobj(source,restore.stdin,1048576)
                restore.stdin.close()
                if restore.wait()!=0: raise ValueError('Backup restore verification failed; see private restore.log')
            finally:
                if not restore.stdin.closed: restore.stdin.close()
                if restore.poll() is None: restore.terminate()
                restore.wait()
        if fingerprint(restore_container) != before: raise ValueError('Restored database inventory differs from source')
        def crypto(mode,input_path,output_path):
            subprocess.run(['docker','run','--rm','--network','none','--read-only','--user',str(os.getuid())+':'+str(os.getgid()),
                '--cap-drop','ALL','--security-opt','no-new-privileges','--memory','768m',
                '--mount','type=bind,src='+str(working)+',dst=/backup',
                '--mount','type=bind,src='+str(password_file)+',dst=/run/secrets/backup_password,readonly',
                '-e','TMPDIR=/backup','--entrypoint','php',app_image,'/opt/dnr/bin/native_backup_crypto.php',
                mode,'/backup/'+input_path.name,'/backup/'+output_path.name],check=True)
        crypto('encrypt',archive,encrypted)
        crypto('decrypt',encrypted,verified_plaintext)
        if sha(archive)!=sha(verified_plaintext): raise ValueError('Encrypted backup did not round-trip')
        receipt = dict(backup_path=str(encrypted),backup_sha256=sha(encrypted),format='native-sql-gzip-secretstream-v2',
            verified_at=datetime.datetime.now(datetime.timezone.utc).isoformat(),restore_verified=True,
            previous_commit=previous_commit,previous_version=previous_version,database_image=database_image,
            database_version=query(db_container,'SELECT VERSION()'),database_state=before)
        (working/'receipt.json').write_text(json.dumps(receipt,indent=2)+'\n')
        return receipt
    finally:
        subprocess.run(['docker','rm','-fv',restore_container],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        for path in (archive,verified_plaintext,root_password): path.unlink(missing_ok=True)
