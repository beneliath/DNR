#!/usr/bin/env python3
"""Interactive installation of an existing NAS account credential, never printed."""
import getpass
import os
from pathlib import Path
import stat
import subprocess
import tempfile


def main():
    if not os.isatty(0):
        raise SystemExit('Run this command in an interactive terminal; do not pipe the password.')
    directory = Path.home() / '.config' / 'moed-backup'
    directory.mkdir(parents=True, mode=0o700, exist_ok=True)
    info = directory.lstat()
    if not stat.S_ISDIR(info.st_mode) or info.st_uid != os.getuid():
        raise SystemExit('Credential directory must be a real directory owned by the current user.')
    directory.chmod(0o700)
    destination = directory / 'nas.credentials'
    if destination.exists() or destination.is_symlink():
        raise SystemExit('Credentials already exist. No existing credential was changed.')
    password = getpass.getpass('Saved NAS password for moed_backup (hidden): ')
    confirmation = getpass.getpass('Enter it again (hidden): ')
    if not password or password != confirmation or '\n' in password or '\r' in password or '\x00' in password:
        raise SystemExit('Passwords did not match or contained unsupported characters. Nothing saved.')
    descriptor, temporary = tempfile.mkstemp(prefix='.nas-', dir=directory)
    try:
        with os.fdopen(descriptor, 'w') as handle:
            handle.write('username = moed_backup\npassword = ' + password + '\n')
            handle.flush()
            os.fsync(handle.fileno())
        # Validate without exposing the password in process arguments or output.
        command = ['smbclient', '//192.168.1.18/MOED_Backups',
                   '--authentication-file=' + temporary, '--client-protection=encrypt',
                   '--option=client min protocol=SMB3', '-c', 'ls']
        result = subprocess.run(command, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                                stderr=subprocess.DEVNULL, timeout=30)
        if result.returncode != 0:
            raise SystemExit('Encrypted NAS connection failed. Nothing saved; check the password and SMB service.')
        # An atomic exclusive link prevents accidentally replacing another credential.
        os.link(temporary, destination)
        print('Encrypted NAS connection verified. Credentials saved privately on s1.')
    finally:
        os.unlink(temporary)


if __name__ == '__main__':
    main()
