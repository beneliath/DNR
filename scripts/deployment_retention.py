#!/usr/bin/env python3
"""Archive MOED deploy backups over encrypted SMB before retiring the local copies."""

import argparse
from concurrent.futures import ThreadPoolExecutor
from contextlib import ExitStack, contextmanager
from datetime import datetime, timedelta, timezone
import fcntl
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import tempfile
import time

from deployment_retention_storage import (
    ArchiveSMB, DatabaseArchive, IMAGE_ID, PRIVATE_PACKAGE, checked_path,
    file_metadata, image_receipt_metadata, json_bytes, metadata,
)

LOCAL_DAYS = 14
NAS_ROOT = 'deployment-retention-v1'
SET_ID = re.compile(r'[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}')
SNAPSHOT = re.compile(r'[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}-[a-f0-9]{16}')
COMMIT = re.compile(r'[a-f0-9]{40}')
ACCOUNT = re.compile(r'[a-z0-9][a-z0-9-]*')
METADATA_SUFFIX = {'.json', '.yaml', '.yml', '.env', '.txt', '.conf', '.sh', '.ini', '.md'}
EXPECTED_ERRORS = (OSError, ValueError, KeyError, TypeError, RuntimeError, subprocess.SubprocessError)


def stamp(value):
    parsed = datetime.fromisoformat(value)
    if parsed.tzinfo is None:
        raise ValueError('Expected a timezone-aware backup receipt')
    return parsed


def state_write(path, value):
    with tempfile.NamedTemporaryFile(dir=path.parent, delete=False) as handle:
        pending = Path(handle.name)
        try:
            handle.write(json_bytes(value))
            handle.flush()
            os.fsync(handle.fileno())
            os.replace(pending, path)
        finally:
            pending.unlink(missing_ok=True)
    directory = os.open(path.parent, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(directory)
    finally:
        os.close(directory)


def docker_output(*args):
    return subprocess.check_output(['docker', *args], text=True)


def docker_rows(kind, ids):
    return json.loads(docker_output(kind, 'inspect', *sorted(ids))) if ids else []


def roots(project):
    deploy = project / '.git/dnr-deploy'
    result = {'primary': deploy / 'backups', 'pre-2.5.0': deploy / 'pre-2.5.0-backups'}
    accounts = deploy / 'accounts'
    if accounts.is_dir():
        for account in accounts.iterdir():
            if ACCOUNT.fullmatch(account.name) and account.is_dir() and not account.is_symlink():
                result['account-' + account.name] = account / 'backups'
    for root in result.values():
        if root.resolve() != root:
            raise ValueError(f'Symlink in deployment backup root: {root}')
    return result


def releases(deploy):
    found = []
    for path in deploy.glob('*.json'):
        if COMMIT.fullmatch(path.stem):
            found.append((path, json.loads(path.read_bytes())))
    return sorted(found, key=lambda item: item[0].stat().st_mtime_ns, reverse=True)


def strings(value):
    if isinstance(value, str):
        yield value
    elif isinstance(value, list):
        for child in value:
            yield from strings(child)
    elif isinstance(value, dict):
        for child in value.values():
            yield from strings(child)


def rollback_local_sets(deploy, records, directories):
    open_records = [record for _, record in records
                    if record.get('outcome') != 'success' and record.get('phase') not in
                    ('preflight', 'awaiting-save-window', 'protecting-public-routes')]
    recovery = deploy / 'recovery'
    open_records.extend(json.loads(path.read_bytes()) for path in recovery.glob('*.json')
                        if not path.is_symlink())
    protected = set()
    for record in open_records:
        for token in strings(record):
            if not token.startswith('/'):
                continue
            path = Path(token)
            for directory in directories.values():
                if path.is_relative_to(directory):
                    relative = path.relative_to(directory).parts
                    if relative and SET_ID.fullmatch(relative[0]):
                        protected.add(directory / relative[0])
    return protected


def capture_set(path):
    """An exact, regular-file-only source inventory for a verified backup directory."""
    objects = {}
    for directory, children, leaves in os.walk(path, followlinks=False):
        for name in ('.', *children, *leaves):
            candidate = Path(directory) / name
            relative = candidate.relative_to(path).as_posix()
            if relative != '.':
                checked_path(relative)
            info = candidate.lstat()
            if not (stat.S_ISDIR(info.st_mode) or stat.S_ISREG(info.st_mode)):
                raise ValueError(f'Unexpected file type in deployment backup: {candidate}')
            objects[relative] = (info.st_mode, info.st_dev, info.st_ino, info.st_size,
                                 info.st_mtime_ns, info.st_ctime_ns)
    return objects


def inspect_backup(group, directory, records):
    before = capture_set(directory)
    receipt = json.loads((directory / 'receipt.json').read_bytes())
    image = receipt['database_image']
    if (receipt.get('format') != 'native-sql-gzip-secretstream-v2' or
            receipt.get('restore_verified') is not True or not IMAGE_ID.fullmatch(image)):
        raise ValueError(f'Deployment receipt is not a restore-verified native set: {directory}')
    checksums = {}

    def add(item):
        file = Path(item['backup_path'])
        if not file.is_relative_to(directory):
            # This checkpoint retains signed artifacts from its original named directory.
            if group != 'pre-2.5.0' or file.parent.name != directory.name:
                raise ValueError('The backup receipt refers to another directory')
            file = directory / file.name
        checksums[file.relative_to(directory).as_posix()] = item['backup_sha256']

    add(receipt)
    for item in (receipt.get('persistent_files'), receipt.get('release_image_archive'),
                 *(receipt.get('recovery_files') or {}).values()):
        if item:
            add(item)
    if 'database.sql.gz.dnrenc' not in checksums:
        raise ValueError('Backup lacks a verified database dump')
    archive_manifest = directory / 'recovery-manifest.json'
    embedded = None
    if archive_manifest.is_file():
        extra = json.loads(archive_manifest.read_bytes())
        if extra.get('database_image_archive'):
            if extra.get('database_image') != image or extra['database_image_archive'] != 'database-image.tar.gz':
                raise ValueError('Embedded database image does not match receipt')
            checksums['database-image.tar.gz'] = extra['database_image_archive_sha256']
            embedded = directory / 'database-image.tar.gz'

    encryption = directory / 'encryption/verification.json'
    if encryption.is_file():
        for key in ('historical_binlogs', 'keyring'):
            entry = json.loads(encryption.read_bytes())[key]
            checksums['encryption/' + Path(entry['path']).name] = entry['sha256']

    refs = set()
    for record_path, record in records:
        if record_path.stem == receipt.get('previous_commit'):
            ref = (record.get('manifest') or {}).get('images', {}).get('database')
            if isinstance(ref, str):
                refs.add(ref)
        primary = record.get('backup') or {}
        related = [primary, *(entry['backup'] for entry in record.get('account_backups') or []
                              if entry.get('backup'))]
        for candidate in related:
            if candidate.get('backup_path') == receipt['backup_path']:
                ref = (record.get('previous_images') or {}).get('db')
                if isinstance(ref, str):
                    refs.add(ref)
                if candidate.get('configuration'):
                    add(candidate['configuration'])
    files = {rel: file_metadata(directory / rel) for rel, info in before.items() if stat.S_ISREG(info[0])}
    if any(files.get(name, {}).get('sha256') != checksum for name, checksum in checksums.items()):
        raise ValueError(f'Source file differs from its deployment receipt: {directory}')
    if before != capture_set(directory):
        raise RuntimeError(f'Source changed while hashing: {directory}')
    return receipt, before, files, refs, embedded


def local_candidates(directories, cutoff, blocked):
    for group, root in sorted(directories.items()):
        for directory in sorted(root.iterdir()) if root.is_dir() else ():
            if not SET_ID.fullmatch(directory.name) or directory in blocked:
                continue
            if directory.is_symlink() or not directory.is_dir():
                raise ValueError(f'Unexpected deployment backup path: {directory}')
            path = directory / 'receipt.json'
            if not path.is_file() or path.is_symlink():
                # Incomplete deployment backup sets are left for the deployment workflow.
                continue
            if stamp(json.loads(path.read_bytes())['verified_at']) < cutoff:
                yield group, directory


def archive_set(nas, root, group, directory, files, image, archived_image):
    destination = f'{root}/backups/{group}/{directory.name}'
    pending = destination + '.pending'
    marker = {'backup_set': f'{group}/{directory.name}', 'files': files, 'image_id': image,
              'database_image': archived_image['marker'],
              'database_image_receipt': image_receipt_metadata(archived_image)}
    raw = json_bytes(marker)
    final_marker = destination + '/archive-record.json'
    if not nas.exists(destination):
        nas.mkdir(pending + '/files')
        for relative, expected in sorted(files.items()):
            nas.put_file(directory / relative, pending + '/files/' + relative, expected)
        nas.put_bytes(raw, pending + '/archive-record.json')
        nas.publish_directory(pending, destination)
    if nas.read(final_marker) != raw:
        raise ValueError(f'Published archive does not describe the exact backup: {directory}')
    for relative, expected in files.items():
        nas.verify(destination + '/files/' + relative, expected)
    return {'destination': destination, 'marker': marker}


@contextmanager
def production_locks(project, recovery, directories, timeout=900):
    paths = [recovery / 'job.lock', project / '.git/dnr-deploy/deploy.lock']
    paths.extend(root / '.backup.lock' for root in directories.values() if (root / '.backup.lock').exists())
    deadline = time.monotonic() + timeout
    while True:
        with ExitStack() as stack:
            for path in paths:
                handle = stack.enter_context(path.open('rb'))
                try:
                    fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
                except BlockingIOError:
                    break
            else:
                yield
                return
        if time.monotonic() >= deadline:
            raise RuntimeError('Deployment or recovery backup lock remains busy')
        time.sleep(5)


def retire_local(directory, entry, nas, archived_image, image_archive, snapshot=None):
    destination = entry['destination']
    marker = entry['marker']
    if nas.read(destination + '/archive-record.json') != json_bytes(marker):
        raise ValueError('NAS backup receipt changed before local retirement')
    if (marker['database_image'] != archived_image['marker'] or
            marker['database_image_receipt'] != image_receipt_metadata(archived_image)):
        raise ValueError('Backup journal refers to a different database archive')
    image_archive.verify(archived_image)
    for relative, expected in marker['files'].items():
        nas.verify(destination + '/files/' + relative, expected)
    retiring = directory.with_name('.' + directory.name + '.retiring')
    if directory.is_symlink() or retiring.is_symlink() or (directory.exists() and retiring.exists()):
        raise ValueError('Unexpected local backup retirement state')
    renamed = False
    if directory.exists():
        now = capture_set(directory)
        if snapshot is not None:
            if now != snapshot:
                raise ValueError('Local backup changed after archive verification')
        else:
            files = {name: file_metadata(directory / name) for name, info in now.items()
                     if stat.S_ISREG(info[0])}
            if files != marker['files'] or now != capture_set(directory):
                raise ValueError('Recovered retirement journal does not match the local backup')
        directory.rename(retiring)
        renamed = True
    if retiring.exists():
        # A retry after an interrupted rmtree can only finish removing the previously journaled files.
        if not renamed:
            for name, info in capture_set(retiring).items():
                if stat.S_ISREG(info[0]) and file_metadata(retiring / name) != marker['files'].get(name):
                    raise ValueError(f'Unrecognized file in interrupted retirement: {name}')
        shutil.rmtree(retiring)


def image_use(project, recovery, nas, image_rows, containers):
    """Protect stopped containers, retained receipts, and current or recoverable releases."""
    deploy = project / '.git/dnr-deploy'
    tokens = {}
    for image in image_rows:
        for identifier in [image['Id'], image['Id'][7:19],
                           *(image.get('RepoDigests') or []), *(image.get('RepoTags') or [])]:
            tokens.setdefault(identifier.encode(), set()).add(image['Id'])
    if not tokens:
        return set()
    payload = b'|'.join(re.escape(token) for token in sorted(tokens, key=len, reverse=True))
    pattern = re.compile(rb'(?<![A-Za-z0-9])(?:' + payload + rb')(?![A-Za-z0-9])')
    live = {row['Image'] for row in containers}

    def mark(data):
        for token in set(pattern.findall(data)):
            live.update(tokens[token])

    for row in containers:
        mark((row.get('Config', {}).get('Image') or '').encode())

    history = releases(deploy)
    completed = [path for path, row in history if row.get('outcome') == 'success']
    historic = set(completed[3:])
    incoming = {deploy / 'incoming' / path.stem for path in historic}
    for root in (deploy, project / 'var/backups', project / 'var/accounts', recovery, project.parent / 'backups'):
        for path, directories, files in os.walk(root, followlinks=False):
            base = Path(path)
            directories[:] = [d for d in directories if base / d not in incoming
                              and d not in {'.git', 'node_modules', 'vendor', 'secrets'}]
            for name in files:
                file = base / name
                if file in historic or file.is_symlink():
                    continue
                if file.suffix in METADATA_SUFFIX or name.startswith('compose'):
                    with file.open('rb') as source:
                        tail = b''
                        while block := source.read(1024 * 1024):
                            data = tail + block
                            mark(data)
                            tail = data[-(max(map(len, tokens)) + 1):]

    inventory = json.loads((recovery / 'replicas.json').read_bytes())
    anchors = {entry['remote_directory']: entry['files']['receipt.json']['sha256']
               for entry in inventory if not entry.get('deleting')}
    snapshots = [name for name, (attrs, _) in nas.list().items() if 'D' in attrs and SNAPSHOT.fullmatch(name)]
    if set(anchors) - set(snapshots):
        raise RuntimeError('A tracked retained NAS backup is missing')
    def snapshot(name):
        data = nas.read(name + '/receipt.json')
        if name in anchors and metadata(data)['sha256'] != anchors[name]:
            raise ValueError(f'Retained NAS backup receipt failed verification: {name}')
        if not IMAGE_ID.fullmatch(json.loads(data)['database_image']):
            raise ValueError(f'Retained NAS backup has an invalid image ID: {name}')
        return data

    with ThreadPoolExecutor(max_workers=4) as workers:
        for raw in workers.map(snapshot, snapshots):
            mark(raw)
    return live


def eligible_images(images, protected):
    for image in images:
        refs = image.get('RepoDigests') or []
        if (image['Id'] not in protected and not image.get('RepoTags') and len(refs) == 1
                and PRIVATE_PACKAGE.fullmatch(refs[0])):
            yield image['Id'], refs[0]


def prune_images(project, recovery, state, nas, image_archive, state_path):
    pending = {entry['marker']['image_id'] for entry in state['backups'].values()}
    candidates = set(state['images']) - pending
    if not candidates:
        return 0, 0
    local = candidates & set(docker_output('image', 'ls', '-aq', '--no-trunc').splitlines())
    for image in candidates - local:
        state['images'].pop(image)
    if not local:
        state_write(state_path, state)
        return 0, 0
    rows = docker_rows('image', local)
    containers = docker_rows('container', docker_output('ps', '-a', '-q', '--no-trunc').splitlines())
    protected = image_use(project, recovery, nas, rows, containers)
    removed = 0
    for image, reference in eligible_images(rows, protected):
        image_archive.verify(state['images'][image])
        subprocess.run(['docker', 'image', 'rm', '--no-prune', reference],
                       check=True, stdout=subprocess.DEVNULL)
        if image in set(docker_output('image', 'ls', '-aq', '--no-trunc').splitlines()):
            raise RuntimeError(f'Docker retained the database image: {image}')
        state['images'].pop(image)
        state_write(state_path, state)
        removed += 1
    return removed, len(local) - removed


def run(project, state_dir, recovery, nas, *, apply, archive_root=NAS_ROOT):
    deploy = project / '.git/dnr-deploy'
    cutoff = datetime.now(timezone.utc) - timedelta(days=LOCAL_DAYS)
    directories = roots(project)
    nas.list()
    with production_locks(project, recovery, directories):
        records = releases(deploy)
        blocked = rollback_local_sets(deploy, records, directories)
        candidates = list(local_candidates(directories, cutoff, blocked))
    print(f'Expired deployment backups eligible for archive: {len(candidates)}', flush=True)
    if not apply:
        return 0
    archive = DatabaseArchive(nas, archive_root)
    state_path = state_dir / 'journal.json'
    state = json.loads(state_path.read_bytes()) if state_path.exists() else {'backups': {}, 'images': {}}
    failures = 0
    finished = 0

    def finish(group, directory, entry, source_snapshot=None):
        with production_locks(project, recovery, directories):
            if directory in rollback_local_sets(deploy, releases(deploy), directories):
                raise RuntimeError(f'An active rollback still uses {directory}')
            proof = state['images'][entry['marker']['image_id']]
            retire_local(directory, entry, nas, proof, archive, snapshot=source_snapshot)
            state['backups'].pop(f'{group}/{directory.name}')
            state_write(state_path, state)

    resumed = set(state['backups'])
    for key, entry in list(state['backups'].items()):
        group, name = key.split('/')
        if group not in directories or not SET_ID.fullmatch(name) or entry['marker']['backup_set'] != key:
            raise ValueError('Unexpected deployment retention journal entry')
        try:
            finish(group, directories[group] / name, entry)
            finished += 1
        except EXPECTED_ERRORS as exc:
            failures += 1
            print(f'Pending retirement {key}: {exc}', flush=True)
    for group, directory in candidates:
        key = f'{group}/{directory.name}'
        if key in resumed:
            continue
        try:
            receipt, source, files, refs, embedded = inspect_backup(group, directory, records)
            if stamp(receipt['verified_at']) >= cutoff:
                raise ValueError('Backup is still inside the local retention window')
            image = receipt['database_image']
            local = set(docker_output('image', 'ls', '-aq', '--no-trunc').splitlines())
            if image in local:
                refs.update(docker_rows('image', [image])[0].get('RepoDigests') or [])
            proof = archive.archive(image, refs, embedded)
            if source != capture_set(directory):
                raise ValueError('Local backup changed before upload')
            entry = archive_set(nas, archive_root, group, directory, files, image, proof)
            state['images'][image] = proof
            state['backups'][key] = entry
            state_write(state_path, state)
            finish(group, directory, entry, source_snapshot=source)
            finished += 1
            print(f'Verified on the NAS and retired locally: {key}', flush=True)
        except EXPECTED_ERRORS as exc:
            failures += 1
            print(f'Backup {key}: {exc}', flush=True)

    removed = kept = 0
    try:
        with production_locks(project, recovery, directories):
            removed, kept = prune_images(project, recovery, state, nas, archive, state_path)
    except EXPECTED_ERRORS as exc:
        failures += 1
        print(f'Image pruning stopped: {exc}', flush=True)
    print(f'Retired {finished} backup sets, pruned {removed} database images, '
          f'kept {kept} matching local images, {failures} errors', flush=True)
    return int(bool(failures))


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check', action='store_true', help='Show eligible backups without changes')
    mode.add_argument('--apply', action='store_true', help='Apply the verified archival and retention policy')
    args = parser.parse_args()
    os.umask(0o077)
    home = Path.home()
    state = home / '.local/state/moed-deployment-retention'
    nas = ArchiveSMB('//192.168.1.18/MOED_Backups', home / '.config/moed-backup/nas.credentials')
    if args.apply:
        state.mkdir(mode=0o700, parents=True, exist_ok=True)
        with (state / 'job.lock').open('a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            return run(home / 'moed', state, home / '.local/state/moed-backup', nas, apply=True)
    return run(home / 'moed', state, home / '.local/state/moed-backup', nas, apply=False)


if __name__ == '__main__':
    raise SystemExit(main())
