#!/usr/bin/env python3
"""Copy one completed native backup to SMB; publish only after read-back verification."""
import argparse
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import stat
import subprocess
import tempfile


def sha(path):
    with path.open('rb') as handle:
        return hashlib.file_digest(handle, 'sha256').hexdigest()


def inventory(directory):
    if directory.is_symlink() or not re.fullmatch(r'[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}', directory.name):
        raise ValueError('Expected a native backup directory')
    receipt_path = directory / 'receipt.json'
    if receipt_path.is_symlink() or receipt_path.stat().st_size > 8 * 1024 * 1024:
        raise ValueError('Unsafe backup receipt')
    receipt = json.loads(receipt_path.read_text())
    if receipt.get('restore_verified') is not True or receipt.get('format') != 'native-sql-gzip-secretstream-v2':
        raise ValueError('Only restore-verified native backups can be replicated')
    items = [('database.sql.gz.dnrenc', receipt)]
    if receipt.get('persistent_files'):
        items.append(('uploaded-files.tar.gz.dnrenc', receipt['persistent_files']))
    recovery = receipt.get('recovery_files', {})
    if set(recovery) - {'recovery-config.tar.gz.dnrenc', 'application-source.tar.dnrenc'}:
        raise ValueError('Unknown recovery archive')
    items.extend(recovery.items())
    result = {}
    for name, metadata in items:
        path = directory / name
        if path.is_symlink() or not path.is_file() or Path(metadata['backup_path']).resolve() != path.resolve():
            raise ValueError('Backup archive is outside its receipt directory')
        checksum = sha(path)
        if checksum != metadata['backup_sha256']:
            raise ValueError('Backup archive does not match its verified receipt')
        result[name] = dict(sha256=checksum, bytes=path.stat().st_size)
    result['receipt.json'] = dict(sha256=sha(receipt_path), bytes=receipt_path.stat().st_size)
    return result


class SMB:
    def __init__(self, share, credentials):
        if not re.fullmatch(r'//[a-zA-Z0-9.-]+/[a-zA-Z0-9_-]+', share):
            raise ValueError('Expected an SMB server/share without command characters')
        info = credentials.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or stat.S_IMODE(info.st_mode) != 0o600:
            raise ValueError('NAS credentials must be an owned regular file with permissions 600')
        self.command = ['smbclient', share, '--authentication-file=' + str(credentials),
                        '--client-protection=encrypt', '--option=client min protocol=SMB3']

    def run(self, command, missing_ok=False):
        # Credentials stay in their private file, never argv or logs. Paths below
        # come only from generated names and a private temporary directory.
        result = subprocess.run(self.command + ['-c', command],
                                stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=300)
        if result.returncode:
            if missing_ok and any(code in result.stdout for code in
                (b'NT_STATUS_OBJECT_NAME_NOT_FOUND', b'NT_STATUS_OBJECT_PATH_NOT_FOUND', b'NT_STATUS_NO_SUCH_FILE')):
                return False
            raise RuntimeError('Encrypted NAS operation failed')
        return True


def replicate(directory, smb):
    files = inventory(directory)
    transfer_id = secrets.token_hex(8)
    staging = '.pending-' + directory.name + '-' + transfer_id
    final = directory.name + '-' + transfer_id
    # Each attempt has its own destination: never replace an earlier good copy.
    smb.run('mkdir ' + staging)
    with tempfile.TemporaryDirectory(prefix='moed-replica-', dir='/tmp') as temporary:
        temporary = Path(temporary)
        for name, expected in files.items():
            local = temporary / name
            # Stage the verified bytes to guard against concurrent local pruning.
            with (directory / name).open('rb') as source, local.open('xb') as output:
                import shutil
                shutil.copyfileobj(source, output, 1048576)
            if sha(local) != expected['sha256']:
                raise ValueError('Source backup changed during replication')
            smb.run(f'put {local} {staging}/{name}')
            local.unlink()
            smb.run(f'get {staging}/{name} {local}')
            if local.stat().st_size != expected['bytes'] or sha(local) != expected['sha256']:
                raise ValueError('NAS backup read-back verification failed')
            local.unlink()
        completion = dict(source_backup_id=directory.name, files=files,
                          copied_at=datetime.datetime.now(datetime.timezone.utc).isoformat(),
                          transport='SMB3 encrypted', readback_verified=True)
        marker = temporary / 'replica.json'
        marker.write_text(json.dumps(completion, indent=2) + '\n')
        expected_marker = sha(marker)
        smb.run(f'put {marker} {staging}/replica.json')
        marker.unlink()
        smb.run(f'get {staging}/replica.json {marker}')
        if sha(marker) != expected_marker:
            raise ValueError('NAS completion receipt verification failed')
        smb.run(f'rename {staging} {final}')
        marker.unlink()
        smb.run(f'get {final}/replica.json {marker}')
        if sha(marker) != expected_marker:
            raise ValueError('NAS backup publication verification failed')
    return final


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    parser.add_argument('--share', default='//192.168.1.18/MOED_Backups')
    parser.add_argument('--credentials', type=Path, default=Path.home() / '.config/moed-backup/nas.credentials')
    args = parser.parse_args()
    os.umask(0o077)
    print('Verified NAS recovery copy: ' + replicate(args.directory, SMB(args.share, args.credentials)))


if __name__ == '__main__':
    main()
