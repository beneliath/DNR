"""Encrypt application source and runtime configuration without staging plaintext."""
import hashlib
import json
from pathlib import Path
import subprocess
import tarfile
from deployment_backup import crypto_command, encrypt_command, sha, stop_process


def host_recovery_files(root, working, app_image, password_file, required_files=()):
    names = ['.env', 'VERSION'] + [p.name for p in root.glob('docker-compose*.yaml')]
    names += ['secrets/' + p.name for p in (root / 'secrets').iterdir() if p.is_file()]
    for required in required_files:
        names.append(str(Path(required).relative_to(root)))
    names = sorted(set(names))
    expected = {}
    for name in names:
        path = root / name
        if path.is_symlink() or not path.is_file():
            raise ValueError('Recovery configuration must consist of regular files')
        expected[name] = sha(path)
    config = working / 'recovery-config.tar.gz.dnrenc'
    encrypt_command(['env', 'COPYFILE_DISABLE=1', 'tar', '-C', str(root), '-czf', '-', '--', *names], config, app_image, password_file)
    with config.open('rb') as ciphertext:
        decryptor = subprocess.Popen(crypto_command(app_image, password_file, 'decrypt'), stdin=ciphertext, stdout=subprocess.PIPE)
        try:
            seen = set()
            with tarfile.open(fileobj=decryptor.stdout, mode='r|gz') as archive:
                for member in archive:
                    if not member.isfile() or member.name not in expected or member.name in seen:
                        raise ValueError('Unexpected recovery configuration member')
                    with archive.extractfile(member) as contents:
                        if hashlib.file_digest(contents, 'sha256').hexdigest() != expected[member.name]:
                            raise ValueError('Recovery configuration changed during capture')
                    seen.add(member.name)
            while decryptor.stdout.read(1048576):
                pass
            if decryptor.wait() != 0 or seen != set(expected):
                raise ValueError('Recovery configuration failed authenticated verification')
        finally:
            decryptor.stdout.close(); stop_process(decryptor)
    if any(sha(root / name) != checksum for name, checksum in expected.items()):
        raise ValueError('Recovery configuration changed during verification')
    source = working / 'application-source.tar.dnrenc'
    encrypt_command(['git', '-C', str(root), 'archive', '--format=tar', 'HEAD'], source, app_image, password_file)
    return {p.name: dict(backup_path=str(p), backup_sha256=sha(p)) for p in (config, source)}
