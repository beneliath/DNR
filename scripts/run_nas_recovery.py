#!/usr/bin/env python3
"""Create and verify an online recovery set, then publish a read-back-verified NAS replica."""
import argparse
import datetime
import fcntl
import json
import os
from pathlib import Path
import signal
import re
import shutil
import tempfile
import subprocess
import sys
import time
from online_recovery_backup import create_online_backup, inspect, utc_now
from deployment_backup import query, sha
from recovery_host_files import host_recovery_files
from recovery_key_setup import key_fingerprint
from replicate_verified_backup import SMB, replicate, inventory
from recovery_retention import prune_recorded_replicas


def write_status(path, status):
    temporary = path.with_suffix('.tmp')
    with temporary.open('w') as handle:
        handle.write(json.dumps(status, indent=2) + '\n')
        handle.flush(); os.fsync(handle.fileno())
    os.replace(temporary, path)


def age_seconds(status):
    timestamp = status.get('last_success', {}).get('snapshot_started_at')
    if not timestamp:
        return None
    then = datetime.datetime.fromisoformat(timestamp)
    return max(0, (datetime.datetime.now(datetime.timezone.utc) - then).total_seconds())


def resolve_service(project, service):
    ids = subprocess.check_output(['docker', 'ps', '-q', '--filter', 'label=com.docker.compose.project=' + project,
        '--filter', 'label=com.docker.compose.service=' + service, '--filter', 'label=com.docker.compose.oneoff=False'], text=True).split()
    if len(ids) != 1:
        raise ValueError('Expected exactly one running ' + service + ' service')
    return ids[0]


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', type=Path, default=Path('/home/dgilmore/moed'))
    parser.add_argument('--compose-project', default='moed')
    parser.add_argument('--check', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    state = Path.home() / '.local/state/moed-backup'
    state.mkdir(parents=True, mode=0o700, exist_ok=True)
    status_path = state / 'status.json'
    status = json.loads(status_path.read_text()) if status_path.exists() else {}
    if args.check:
        age = age_seconds(status)
        if age is None or age > 3600 or status.get('last_error'):
            print('RECOVERY ALERT: no fresh verified NAS snapshot within one hour, or the latest attempt failed.', file=sys.stderr)
            return 1
        print('Verified NAS snapshot age: %d minutes.' % (age / 60))
        return 0
    with (state / 'job.lock').open('a') as job_lock:
        try:
            fcntl.flock(job_lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return 0  # the existing job owns status and its deadline
        # Re-read only after taking the job lock.
        status = json.loads(status_path.read_text()) if status_path.exists() else {}
        status['last_attempt_at'] = utc_now()
        write_status(status_path, status)
        started = time.monotonic()
        def deadline(_signum, _frame):
            raise TimeoutError('Recovery job exceeded its 20-minute deadline or was interrupted')
        signal.signal(signal.SIGALRM, deadline)
        signal.signal(signal.SIGTERM, deadline)
        signal.alarm(1200)
        try:
            config = Path.home() / '.config/moed-backup'
            password = args.project / 'secrets/deployment_backup_password'
            coverage = json.loads((config / 'recovery-key-coverage.json').read_text())
            if not coverage.get('decrypted_and_verified') or coverage.get('key_fingerprint') != key_fingerprint(password):
                raise ValueError('A separately protected recovery copy of the current backup key is required')
            smb = SMB('//192.168.1.18/MOED_Backups', config / 'nas.credentials')
            remote_key = coverage.get('remote_path', '')
            if not re.fullmatch(r'recovery-key-[a-f0-9]{24}\.dnrenc', remote_key):
                raise ValueError('Invalid recovery-key receipt')
            with tempfile.TemporaryDirectory(prefix='moed-key-check-', dir='/tmp') as temporary:
                copied_key = Path(temporary) / 'key.dnrenc'
                smb.run(f'get {remote_key} {copied_key}')
                if sha(copied_key) != coverage.get('sha256'):
                    raise ValueError('NAS recovery-key copy is missing or changed')
            # Same lock as release deployments: exclude schema changes while the
            # source snapshot, configuration and release identity are captured.
            with (args.project / '.git/dnr-deploy/deploy.lock').open('a') as deploy_lock:
                fcntl.flock(deploy_lock, fcntl.LOCK_EX)
                commit = subprocess.check_output(['git', '-C', str(args.project), 'rev-parse', 'HEAD'], text=True).strip()
                version = (args.project / 'VERSION').read_text().strip()
                db = resolve_service(args.compose_project, 'db')
                web = resolve_service(args.compose_project, 'web')
                file_bytes = int(query(db, 'SELECT COALESCE(SUM(size),0) FROM dnr.stored_files'))
                database_bytes = int(query(db, "SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) FROM information_schema.tables WHERE TABLE_SCHEMA='dnr'"))
                if shutil.disk_usage(state).free < 2 * (file_bytes + database_bytes) + 4 * 1024**3:
                    raise ValueError('Insufficient spare host space for recovery; keeping application reserve')
                receipt = create_online_backup(db, web, password, state / 'snapshots', commit, version)
                directory = Path(receipt['backup_path']).parent
                required_files = [mount['Source'] for mount in inspect(web)['Mounts']
                    if mount['Type'] == 'bind' and (mount['Destination'].startswith('/run/secrets/') or mount['Destination'].startswith('/run/dnr/config/'))]
                receipt['recovery_files'] = host_recovery_files(args.project, directory, inspect(web)['Image'], password, required_files)
                receipt['recovery_key'] = coverage
                receipt['release_images'] = {
                    container['Config']['Labels'].get('com.docker.compose.service'): container['Config']['Image']
                    for container in json.loads(subprocess.check_output(['docker', 'inspect', *subprocess.check_output(
                        ['docker', 'ps', '-q', '--filter', 'label=com.docker.compose.project=' + args.compose_project], text=True).split()]))}
                (directory / 'receipt.json').write_text(json.dumps(receipt, indent=2) + '\n')
            remote = replicate(directory, smb)
            status['last_success'] = dict(snapshot_started_at=receipt['snapshot_started_at'],
                completed_at=utc_now(), remote_directory=remote, local_directory=str(directory),
                elapsed_seconds=round(time.monotonic() - started, 1))
            status.pop('last_error', None)
            write_status(status_path, status)
            history_path = state / 'replicas.json'
            history = json.loads(history_path.read_text()) if history_path.exists() else []
            history.append(dict(remote_directory=remote, source_backup_id=directory.name,
                snapshot_started_at=receipt['snapshot_started_at'], files=inventory(directory)))
            write_status(history_path, history)
            history = prune_recorded_replicas(history, smb, state / 'snapshots', lambda value: write_status(history_path, value))
            write_status(history_path, history)
            print('Verified NAS snapshot: ' + remote, flush=True)
            return 0 if age_seconds(status) <= 3600 else 1
        except Exception as error:
            # No command output or secret-bearing exception details in status.
            status['last_error'] = {'at': utc_now(), 'type': type(error).__name__}
            write_status(status_path, status)
            print('RECOVERY FAILED (' + type(error).__name__ + '). Previous successful copies retained.', file=sys.stderr)
            return 1
        finally:
            signal.alarm(0)


if __name__ == '__main__':
    sys.exit(main())
