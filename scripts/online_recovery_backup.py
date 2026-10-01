#!/usr/bin/env python3
"""Online native snapshots: pin immutable files, dump InnoDB, restore, then archive referenced files."""
import contextlib
import datetime
import fcntl
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import selectors
import shutil
import subprocess
import time

from deployment_backup import (crypto_command, encrypt_command, fingerprint, query,
                               root_command, sha, stop_process, verify_file_stream)


def utc_now():
    return datetime.datetime.now(datetime.timezone.utc).isoformat()


def inspect(identifier):
    return json.loads(subprocess.check_output(['docker', 'inspect', identifier]))[0]


def upload_volume(web):
    mounts = [m for m in inspect(web)['Mounts'] if m['Destination'] == '/var/lib/dnr/files']
    if len(mounts) != 1 or mounts[0]['Type'] != 'volume':
        raise ValueError('Expected one named persistent upload volume')
    return mounts[0]['Name']


@contextlib.contextmanager
def pin_files(image, volume, name, owner):
    # Shared with uploads/readers; excludes only garbage collection. stdin EOF
    # releases the lock even when the parent job terminates unexpectedly.
    command = ['docker', 'run', '--rm', '-i', '--name', name,
               '--label', 'org.dnr.recovery-owner=' + owner,
               '--network', 'none', '--read-only', '--user', 'www-data',
               '--memory', '32m', '--pids-limit', '16', '--cap-drop', 'ALL',
               '--security-opt', 'no-new-privileges',
               '--mount', 'type=volume,src=' + volume + ',dst=/files,readonly',
               '--entrypoint', 'sh', image, '-c',
               'test -f /files/.lifecycle.lock && test ! -L /files/.lifecycle.lock && '
               'exec flock -s /files/.lifecycle.lock sh -c \'echo pinned; cat >/dev/null\'']
    process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
    try:
        with selectors.DefaultSelector() as selector:
            selector.register(process.stdout, selectors.EVENT_READ)
            if not selector.select(30) or process.stdout.readline() != b'pinned\n':
                raise ValueError('Could not pin persistent files before snapshot')
        yield process
        if process.poll() is not None:
            raise ValueError('File lifecycle lock was lost during backup')
    finally:
        process.stdin.close()
        stop_process(process)
        process.stdout.close()
        subprocess.run(['docker', 'rm', '-f', name], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)


@contextlib.contextmanager
def restored_database(archive, database_image, app_image, password_file, working, owner, network='none'):
    network_options = []
    if network != 'none':
        info = json.loads(subprocess.check_output(['docker','network','inspect',network]))[0]
        if not info.get('Internal') or info.get('Labels',{}).get('org.dnr.restore-drill') != owner:
            raise ValueError('Only an owned internal restore-drill network is allowed')
        network_options = ['--network-alias','db']
    root_password = working / 'restore-root-password'
    root_password.write_text(secrets.token_hex(32)); root_password.chmod(0o600)
    container = 'dnr-online-restore-' + working.name.lower()
    try:
        subprocess.run(['docker', 'run', '-d', '--name', container, '--network', network, *network_options,
            '--memory', '2g', '--cpus', '1', '--pids-limit', '256',
            '--label', 'org.dnr.recovery-owner=' + owner,
            '--mount', 'type=bind,src=' + str(root_password) + ',dst=/run/secrets/root-password,readonly',
            '--tmpfs', '/var/lib/mysql:rw,nosuid,size=1g',
            '--tmpfs', '/var/lib/mysql-keyring:rw,nosuid,size=1m',
            '-e', 'MYSQL_ROOT_PASSWORD_FILE=/run/secrets/root-password', '-e', 'MYSQL_DATABASE=dnr',
            database_image, '--max-allowed-packet=256M', '--skip-log-bin'], check=True, stdout=subprocess.DEVNULL)
        for _ in range(90):
            if subprocess.run(root_command(container, 'mysql', '-uroot', 'dnr', '-e', 'SELECT 1'),
                              stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
                break
            time.sleep(2)
        else:
            raise ValueError('Recovery verification database did not become ready')
        with archive.open('rb') as ciphertext:
            decryptor = subprocess.Popen(crypto_command(app_image, password_file, 'decrypt'), stdin=ciphertext, stdout=subprocess.PIPE)
            restore = subprocess.Popen(root_command(container, 'mysql', '-uroot', 'dnr'),
                                       stdin=subprocess.PIPE, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            try:
                with gzip.GzipFile(fileobj=decryptor.stdout, mode='rb') as source:
                    shutil.copyfileobj(source, restore.stdin, 1048576)
                restore.stdin.close()
                if decryptor.wait() != 0 or restore.wait() != 0:
                    raise ValueError('Online snapshot failed authenticated restore')
            finally:
                decryptor.stdout.close()
                if not restore.stdin.closed:
                    restore.stdin.close()
                stop_process(decryptor); stop_process(restore)
        yield container
    finally:
        subprocess.run(['docker', 'rm', '-fv', container], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        root_password.unlink(missing_ok=True)


def archive_snapshot_files(restored, app_image, volume, password_file, working):
    expected = {}
    for line in query(restored, 'SELECT storage_key, size, checksum FROM dnr.stored_files ORDER BY storage_key').splitlines():
        key, size, checksum = line.split('\t')
        if not re.fullmatch('[a-f0-9]{64}', key) or not re.fullmatch('[a-f0-9]{64}', checksum):
            raise ValueError('Invalid persistent file metadata in restored snapshot')
        expected[key] = (int(size), checksum)
    inventory = working / 'file-inventory'
    inventory.write_bytes(b''.join(key.encode() + b'\0' for key in expected)); inventory.chmod(0o444)
    archive = working / 'uploaded-files.tar.gz.dnrenc'
    try:
        # Exact immutable names from the restored snapshot avoid including new
        # uploads and avoid tar's directory-changed race while users upload.
        command = ['docker', 'run', '--rm', '--network', 'none', '--read-only',
                   '--user', 'www-data', '--memory', '128m', '--cpus', '1', '--cap-drop', 'ALL',
                   '--security-opt', 'no-new-privileges',
                   '--mount', 'type=volume,src=' + volume + ',dst=/files,readonly',
                   '--mount', 'type=bind,src=' + str(inventory) + ',dst=/run/file-list,readonly',
                   '--entrypoint', 'tar', app_image, '-C', '/files', '-czf', '-', '--null', '-T', '/run/file-list']
        encrypt_command(command, archive, app_image, password_file)
        with archive.open('rb') as ciphertext:
            decryptor = subprocess.Popen(crypto_command(app_image, password_file, 'decrypt'), stdin=ciphertext, stdout=subprocess.PIPE)
            try:
                verify_file_stream(decryptor.stdout, expected)
                if decryptor.wait() != 0:
                    raise ValueError('Uploaded-file archive failed authentication')
            finally:
                decryptor.stdout.close(); stop_process(decryptor)
        return dict(volume=volume, file_count=len(expected), backup_path=str(archive),
                    backup_sha256=sha(archive), format='tar-gzip-secretstream-v2')
    finally:
        inventory.unlink(missing_ok=True)


def create_online_backup(db, web, password_file, directory, commit, version):
    """Caller must hold the deployment lock throughout to exclude DDL/releases."""
    directory = Path(directory).resolve()
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    directory.chmod(0o700)
    password_file = Path(password_file).resolve()
    if not password_file.is_file() or len(password_file.read_bytes().rstrip(b'\r\n')) < 16:
        raise ValueError('A backup decryption password of at least 16 bytes is required')
    with (directory / '.backup.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        owner = hashlib.sha256(str(directory).encode()).hexdigest()
        stale = subprocess.check_output(['docker', 'ps', '-aq', '--filter', 'label=org.dnr.recovery-owner=' + owner], text=True).split()
        if stale:
            subprocess.run(['docker', 'rm', '-fv', *stale], check=True, stdout=subprocess.DEVNULL)
        if query(db, "SELECT COUNT(*) FROM information_schema.tables WHERE TABLE_SCHEMA='dnr' AND TABLE_TYPE='BASE TABLE' AND ENGINE<>'InnoDB'") != '0':
            raise ValueError('Online recovery requires every table to use InnoDB')
        app_image = inspect(web)['Image']; database_image = inspect(db)['Image']
        volume = upload_volume(web)
        backup_id = datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '-' + secrets.token_hex(4)
        working = directory / backup_id; working.mkdir(mode=0o700)
        encrypted = working / 'database.sql.gz.dnrenc'
        with pin_files(app_image, volume, 'dnr-online-pin-' + backup_id.lower(), owner):
            snapshot_started = utc_now()
            encrypt_command(root_command(db, 'mysqldump', '-uroot', '--single-transaction', '--quick',
                '--routines', '--triggers', '--events', '--hex-blob', '--set-gtid-purged=OFF', '--no-tablespaces', 'dnr'),
                encrypted, app_image, password_file, compress=True)
            with restored_database(encrypted, database_image, app_image, password_file, working, owner) as restored:
                state = fingerprint(restored)
                files = archive_snapshot_files(restored, app_image, volume, password_file, working)
        receipt = dict(format='native-sql-gzip-secretstream-v2', backup_path=str(encrypted),
            backup_sha256=sha(encrypted), persistent_files=files, restore_verified=True,
            snapshot_started_at=snapshot_started, verified_at=utc_now(),
            snapshot_method='InnoDB consistent snapshot with immutable-file lifecycle pin',
            previous_commit=commit, previous_version=version, app_image=app_image,
            database_image=database_image, database_version=query(db, 'SELECT VERSION()'), database_state=state)
        (working / 'receipt.json').write_text(json.dumps(receipt, indent=2) + '\n')
        return receipt
