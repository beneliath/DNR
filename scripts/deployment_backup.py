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
import tarfile
import re
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

def crypto_command(app_image, password_file, mode):
    return ['docker', 'run', '--rm', '-i', '--network', 'none', '--read-only',
            '--user', str(os.getuid()) + ':' + str(os.getgid()), '--cap-drop', 'ALL',
            '--security-opt', 'no-new-privileges', '--memory', '768m',
            '--mount', 'type=bind,src=' + str(password_file) + ',dst=/run/secrets/backup_password,readonly',
            '--entrypoint', 'php', app_image, '/opt/dnr/bin/native_backup_crypto.php', mode, '-', '-']


def stop_process(process):
    if process.poll() is None:
        process.terminate()
    try:
        process.wait(timeout=15)
    except subprocess.TimeoutExpired:
        process.kill(); process.wait()


def encrypt_command(command, archive, app_image, password_file, compress=False):
    # Only authenticated ciphertext touches host storage, including partial outputs.
    with archive.open('xb') as output:
        archive.chmod(0o600)
        encryptor = subprocess.Popen(crypto_command(app_image, password_file, 'encrypt'), stdin=subprocess.PIPE, stdout=output)
        producer = None
        try:
            producer = subprocess.Popen(command, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
            if compress:
                with gzip.GzipFile(fileobj=encryptor.stdin, mode='wb', mtime=0) as compressed:
                    shutil.copyfileobj(producer.stdout, compressed, 1048576)
            else:
                shutil.copyfileobj(producer.stdout, encryptor.stdin, 1048576)
            encryptor.stdin.close()
            if producer.wait() != 0 or encryptor.wait() != 0:
                raise ValueError('Native backup encryption failed')
        finally:
            if producer is not None:
                producer.stdout.close(); stop_process(producer)
            if not encryptor.stdin.closed: encryptor.stdin.close()
            stop_process(encryptor)


def verify_file_stream(stream, expected):
    seen = set()
    with tarfile.open(fileobj=stream, mode='r|gz') as archive:
        for member in archive:
            if member.name == '.' and member.isdir(): continue
            key = member.name.removeprefix('./')
            if not member.isfile() or not re.fullmatch('[0-9a-f]{64}', key) or key in seen:
                raise ValueError('Unsafe or duplicate persistent file in native backup')
            seen.add(key)
            if key in expected:
                size, checksum = expected[key]
                with archive.extractfile(member) as contents:
                    actual = hashlib.file_digest(contents, 'sha256').hexdigest()
                if member.size != size or actual != checksum:
                    raise ValueError('Persistent file checksum mismatch in deployment backup')
    if set(expected) - seen: raise ValueError('Deployment backup is missing persistent files')
    # Consume the remaining encrypted stream so its FINAL tag and trailing bytes are checked.
    while stream.read(1048576): pass


def backup_persistent_files(db_container, app_image, archive, password_file):
    """Preserve files with writers paused, and authenticate/validate via a pipe."""
    if query(db_container, "SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA='dnr' AND TABLE_NAME='stored_files'") == '0': return None
    expected = {}
    for line in query(db_container, 'SELECT storage_key, size, checksum FROM dnr.stored_files ORDER BY storage_key').splitlines():
        key, size, checksum = line.split('\t')
        if not re.fullmatch('[0-9a-f]{64}', key): raise ValueError('Invalid persistent file key')
        expected[key] = (int(size), checksum)
    project = json.loads(subprocess.check_output(['docker', 'inspect', db_container]))[0]['Config']['Labels']['com.docker.compose.project']
    ids = subprocess.check_output(['docker', 'ps', '-aq', '--filter', 'label=com.docker.compose.project=' + project,
        '--filter', 'label=com.docker.compose.service=web', '--filter', 'label=com.docker.compose.oneoff=False'], text=True).split()
    if len(ids) != 1: raise ValueError('Cannot identify persistent file volume')
    mounts = json.loads(subprocess.check_output(['docker', 'inspect', ids[0]]))[0]['Mounts']
    volume = next((m for m in mounts if m['Destination'] == '/var/lib/dnr/files'), None)
    if volume is None or volume['Type'] != 'volume': raise ValueError('A named uploaded_files volume is required')
    command = ['docker', 'run', '--rm', '--network', 'none', '--read-only', '--user', 'www-data',
               '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
               '--mount', 'type=volume,src=' + volume['Name'] + ',dst=/files,readonly',
               '--entrypoint', 'sh', app_image, '-c',
               'if [ -f /files/.lifecycle.lock ]; then exec flock -s /files/.lifecycle.lock tar -czf - --exclude=./.* -C /files .; '
               'else exec tar -czf - --exclude=./.* -C /files .; fi']
    encrypt_command(command, archive, app_image, password_file)
    with archive.open('rb') as ciphertext:
        decryptor = subprocess.Popen(crypto_command(app_image, password_file, 'decrypt'), stdin=ciphertext, stdout=subprocess.PIPE)
        try:
            verify_file_stream(decryptor.stdout, expected)
            if decryptor.wait() != 0: raise ValueError('File backup authentication failed')
        finally:
            decryptor.stdout.close(); stop_process(decryptor)
    return dict(volume=volume['Name'], file_count=len(expected), backup_path=str(archive),
                backup_sha256=sha(archive), format='tar-gzip-secretstream-v2')


def create_verified_backup(db_container, database_image, app_image, previous_commit, previous_version, password_file, directory):
    """Writers are paused; lock also coordinates interrupted-run recovery cleanup."""
    import fcntl
    directory = Path(directory).resolve()
    directory.mkdir(mode=0o700, parents=True, exist_ok=True); directory.chmod(0o700)
    with (directory / '.backup.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        owner = hashlib.sha256(str(directory).encode()).hexdigest()
        stale = subprocess.check_output(['docker', 'ps', '-aq', '--filter', 'label=org.dnr.backup-owner=' + owner], text=True).split()
        if stale: subprocess.run(['docker', 'rm', '-fv', *stale], check=True, stdout=subprocess.DEVNULL)
        # Remove only known plaintext staging names from earlier versions, never recovery archives.
        for run in directory.iterdir():
            if not run.is_symlink() and run.is_dir() and re.fullmatch(r'[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}', run.name):
                for name in ('database.sql.gz', 'verified.sql.gz', 'uploaded-files.tar.gz', 'verified-files.tar.gz', 'restore-root-password'):
                    (run / name).unlink(missing_ok=True)
        return _create_verified_backup(db_container, database_image, app_image, previous_commit,
                                       previous_version, password_file, directory, owner)


def _create_verified_backup(db_container, database_image, app_image, previous_commit, previous_version, password_file, directory, owner):
    password_file = Path(password_file).resolve()
    if not password_file.is_file() or len(password_file.read_bytes().rstrip(b'\r\n')) < 16:
        raise ValueError('Configure a private backup password file with at least 16 characters')
    backup_id = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + secrets.token_hex(4)
    working = directory / backup_id; working.mkdir(mode=0o700)
    encrypted = working / 'database.sql.gz.dnrenc'
    root_password = working / 'restore-root-password'
    root_password.write_text(secrets.token_hex(32)); root_password.chmod(0o600)
    restore_container = 'dnr-restore-check-' + backup_id.lower()
    try:
        before = fingerprint(db_container)
        file_receipt = backup_persistent_files(db_container, app_image, working / 'uploaded-files.tar.gz.dnrenc', password_file)
        encrypt_command(root_command(db_container, 'mysqldump', '-uroot', '--single-transaction',
            '--routines', '--triggers', '--events', '--hex-blob', '--set-gtid-purged=OFF', '--no-tablespaces', 'dnr'),
            encrypted, app_image, password_file, compress=True)
        if before != fingerprint(db_container): raise ValueError('Database changed during backup')
        subprocess.run(['docker', 'run', '-d', '--name', restore_container, '--network', 'none', '--memory', '2g',
            '--label', 'org.dnr.backup-owner=' + owner,
            '--mount', 'type=bind,src=' + str(root_password) + ',dst=/run/secrets/root-password,readonly',
            '-e', 'MYSQL_ROOT_PASSWORD_FILE=/run/secrets/root-password', '-e', 'MYSQL_DATABASE=dnr',
            database_image, '--max-allowed-packet=256M', '--skip-log-bin'], check=True, stdout=subprocess.DEVNULL)
        for attempt in range(90):
            if subprocess.run(root_command(restore_container, 'mysql', '-uroot', 'dnr', '-e', 'SELECT 1'),
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0: break
            time.sleep(2)
        else: raise ValueError('Backup verification database did not become ready')
        with encrypted.open('rb') as ciphertext:
            decryptor = subprocess.Popen(crypto_command(app_image, password_file, 'decrypt'), stdin=ciphertext, stdout=subprocess.PIPE)
            restore = subprocess.Popen(root_command(restore_container, 'mysql', '-uroot', 'dnr'),
                                       stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                with gzip.GzipFile(fileobj=decryptor.stdout, mode='rb') as source:
                    shutil.copyfileobj(source, restore.stdin, 1048576)
                restore.stdin.close()
                if decryptor.wait() != 0 or restore.wait() != 0: raise ValueError('Encrypted backup restore verification failed')
            finally:
                decryptor.stdout.close()
                if not restore.stdin.closed: restore.stdin.close()
                stop_process(decryptor); stop_process(restore)
        if fingerprint(restore_container) != before: raise ValueError('Restored database differs from source')
        receipt = dict(persistent_files=file_receipt, backup_path=str(encrypted), backup_sha256=sha(encrypted),
            format='native-sql-gzip-secretstream-v2', verified_at=datetime.datetime.now(datetime.timezone.utc).isoformat(),
            restore_verified=True, previous_commit=previous_commit, previous_version=previous_version,
            database_image=database_image, database_version=query(db_container, 'SELECT VERSION()'), database_state=before)
        (working / 'receipt.json').write_text(json.dumps(receipt, indent=2) + '\n')
        return receipt
    finally:
        subprocess.run(['docker', 'rm', '-fv', restore_container], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        root_password.unlink(missing_ok=True)
