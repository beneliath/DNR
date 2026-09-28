#!/usr/bin/env python3
"""Save a separately encrypted recovery copy of the existing backup key on the NAS."""
import argparse
import getpass
import hashlib
import hmac
import os
from pathlib import Path
import secrets
import subprocess
import tempfile
from deployment_backup import crypto_command, encrypt_command, sha
from replicate_verified_backup import SMB
from online_recovery_backup import inspect


def key_fingerprint(password_file):
    # A context-bound identifier for a strong existing backup key, never the key.
    return hmac.new(password_file.read_bytes().rstrip(b'\r\n'), b'MOED recovery-key coverage v1', hashlib.sha256).hexdigest()


def protect_key(password_file, phrase_file, image, smb, directory, remote):
    archive = directory / 'recovery-key.dnrenc'
    encrypt_command(['cat', str(password_file)], archive, image, phrase_file)
    smb.run(f'put {archive} {remote}')
    expected_hash = sha(archive)
    archive.unlink()
    smb.run(f'get {remote} {archive}')
    if sha(archive) != expected_hash:
        raise RuntimeError('Recovery-key copy failed NAS read-back verification')
    with archive.open('rb') as encrypted:
        recovered = subprocess.check_output(crypto_command(image, phrase_file, 'decrypt'), stdin=encrypted)
    if not hmac.compare_digest(recovered, password_file.read_bytes()):
        raise RuntimeError('Recovery-key decryption did not match')
    del recovered
    return expected_hash


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', type=Path, default=Path('/home/dgilmore/moed'))
    args = parser.parse_args()
    if not os.isatty(0):
        raise SystemExit('Run in an interactive terminal. Do not pipe a password.')
    os.umask(0o077)
    config = Path.home() / '.config/moed-backup'
    password_file = args.project / 'secrets/deployment_backup_password'
    passphrase = getpass.getpass('New recovery passphrase (at least 20 characters; save in your password manager): ')
    confirmation = getpass.getpass('Enter the recovery passphrase again: ')
    if len(passphrase) < 20 or passphrase != confirmation or any(c in passphrase for c in '\r\n\0'):
        raise SystemExit('Passphrases did not match or were too short. Nothing changed.')
    image = inspect('moed-web-1')['Image']
    smb = SMB('//192.168.1.18/MOED_Backups', config / 'nas.credentials')
    remote = 'recovery-key-' + secrets.token_hex(12) + '.dnrenc'
    # /dev/shm keeps the temporary passphrase in memory, not the host filesystem.
    with tempfile.TemporaryDirectory(prefix='moed-recovery-key-', dir='/dev/shm') as temporary:
        directory = Path(temporary)
        phrase_file = directory / 'passphrase'
        phrase_file.write_text(passphrase); phrase_file.chmod(0o600)
        del passphrase, confirmation
        expected_hash = protect_key(password_file, phrase_file, image, smb, directory, remote)
        marker = config / 'recovery-key-coverage.json'
        import json
        data = dict(key_fingerprint=key_fingerprint(password_file), remote_path=remote,
                    sha256=expected_hash, decrypted_and_verified=True)
        temporary_marker = config / ('.coverage-' + secrets.token_hex(8))
        temporary_marker.write_text(json.dumps(data, indent=2) + '\n')
        os.replace(temporary_marker, marker)
    print('Recovery key encrypted, copied to the NAS, read back and successfully decrypted.')
    print('Save the recovery passphrase outside s1 and the NAS. It is not saved by this helper.')


if __name__ == '__main__':
    main()
