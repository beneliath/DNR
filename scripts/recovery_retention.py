"""Prune only this scheduler's recorded replicas after a new verified copy succeeds."""
import datetime
import json
from pathlib import Path
import re
import shutil
import tempfile


def retained_names(history, now):
    keep=set(); daily={}; groups={}
    for entry in sorted(history,key=lambda item:item['snapshot_started_at'],reverse=True):
        account=entry.get('account_key', 'primary')
        groups.setdefault(account, []).append(entry)
        stamp=datetime.datetime.fromisoformat(entry['snapshot_started_at'])
        age=(now-stamp).total_seconds()
        if age<=7*86400:
            keep.add(entry['remote_directory'])
        elif age<=30*86400 and (account,stamp.date()) not in daily:
            daily[account,stamp.date()]=True; keep.add(entry['remote_directory'])
    # The two-copy floor belongs to each account, including an inactive account.
    for entries in groups.values():
        keep.update(entry['remote_directory'] for entry in entries[:2])
    return keep


def prune_recorded_replicas(history, smb, snapshots, save_history, now=None):
    now=now or datetime.datetime.now(datetime.timezone.utc)
    keep=retained_names(history,now); remaining=[]
    with tempfile.TemporaryDirectory(prefix='moed-retention-',dir='/tmp') as temporary:
        marker=Path(temporary)/'replica.json'
        for entry in history:
            remote=entry['remote_directory']
            if remote in keep:
                remaining.append(entry); continue
            if not re.fullmatch(r'[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}-[a-f0-9]{16}',remote):
                raise ValueError('Unsafe recorded replica name')
            if not entry.get('deleting'):
                smb.run(f'get {remote}/replica.json {marker}')
                receipt=json.loads(marker.read_text()); marker.unlink()
                if receipt.get('source_backup_id')!=remote[:-17] or receipt.get('files')!=entry['files'] or receipt.get('readback_verified') is not True:
                    raise ValueError('Retention receipt differs from the scheduler inventory')
                # Journal before removal so an interrupted deletion can resume.
                entry['deleting']=True
                save_history(history)
            allowed={'database.sql.gz.dnrenc','uploaded-files.tar.gz.dnrenc','recovery-config.tar.gz.dnrenc','application-source.tar.dnrenc','receipt.json'}
            if set(entry['files'])-allowed:
                raise ValueError('Unknown replica file; refusing retention')
            for name in entry['files']:
                smb.run(f'del {remote}/{name}',missing_ok=True)
            smb.run(f'del {remote}/replica.json',missing_ok=True)
            smb.run(f'rmdir {remote}',missing_ok=True)
    # Keep four local snapshots per account, independently of NAS retention.
    groups={}
    for entry in history:
        groups.setdefault(entry.get('account_key','primary'),set()).add(entry['source_backup_id'])
    keep_local={name for names in groups.values() for name in sorted(names)[-4:]}
    verified={e['source_backup_id'] for e in history}
    for name in verified-keep_local:
        if not re.fullmatch(r'[0-9]{8}T[0-9]{6}Z-[a-f0-9]{8}',name):
            raise ValueError('Unsafe local snapshot name')
        path=snapshots/name
        if path.is_dir() and not path.is_symlink():
            shutil.rmtree(path)
    return remaining
