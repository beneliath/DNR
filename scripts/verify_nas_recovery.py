#!/usr/bin/env python3
"""Download and restore every account's latest NAS copy in disposable isolation."""
import argparse
import fcntl
import json
import os
from pathlib import Path
import signal
import tempfile
from recovery_accounts import account_directory
from recovery_restore_drill import drill
from replicate_verified_backup import SMB, retrieve
from run_nas_recovery import coverage_current, write_status
from online_recovery_backup import utc_now


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--project', type=Path, default=Path('/home/dgilmore/moed'))
    parser.add_argument('--compose-project', default='moed')
    parser.add_argument('--report', type=Path, required=True)
    args = parser.parse_args()
    os.umask(0o077)
    state = Path.home() / '.local/state/moed-backup'
    def deadline(_signum, _frame):
        raise TimeoutError('NAS restore verification exceeded its deadline')
    signal.signal(signal.SIGALRM, deadline)
    signal.signal(signal.SIGTERM, deadline)
    with (state / 'job.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        status = json.loads((state / 'status.json').read_text())
        keys = [a['key'] for a in account_directory(args.project, args.compose_project)]
        if not coverage_current(status, keys):
            raise ValueError('Run a fresh complete account backup before the NAS restore drill')
        history = json.loads((state / 'replicas.json').read_text())
        smb = SMB('//192.168.1.18/MOED_Backups', Path.home() / '.config/moed-backup/nas.credentials')
        report = dict(started_at=utc_now(), nas_source='//192.168.1.18/MOED_Backups', accounts={}, complete=False)
        write_status(args.report, report)
        try:
            for key in keys:
                signal.alarm(1200)
                remote = status['last_success']['accounts'][key]['remote_directory']
                entries = [e for e in history if e['remote_directory'] == remote and e.get('account_key') == key]
                if len(entries) != 1:
                    raise ValueError('Account NAS copy is not recorded in the scheduler ledger')
                with tempfile.TemporaryDirectory(prefix='moed-nas-drill-') as temporary:
                    directory = retrieve(remote, smb, Path(temporary) / remote[:-17], entries[0]['files'])
                    receipt = json.loads((directory / 'receipt.json').read_text())
                    if receipt.get('account_key') != key:
                        raise ValueError('Downloaded receipt belongs to a different account')
                    checks = drill(directory, args.project / 'secrets/deployment_backup_password')
                    report['accounts'][key] = dict(**checks, remote_directory=remote, nas_download_verified=True)
                    write_status(args.report, report)
                    print('NAS restore verified for ' + key + '.', flush=True)
            report.update(complete=True, completed_at=utc_now())
            write_status(args.report, report)
        except Exception as error:
            report.update(error_type=type(error).__name__, failed_at=utc_now())
            write_status(args.report, report)
            raise
        finally:
            signal.alarm(0)


if __name__ == '__main__':
    main()
