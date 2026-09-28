import datetime
import json
import shlex
import tempfile
from pathlib import Path
import sys
import unittest
sys.path.insert(0,str(Path(__file__).resolve().parent.parent/'scripts'))
from recovery_retention import retained_names, prune_recorded_replicas
from run_nas_recovery import age_seconds
class RecoveryRetentionTests(unittest.TestCase):
    def test_recent_dense_daily_older_and_two_copy_floor(self):
        now=datetime.datetime(2026,9,28,12,tzinfo=datetime.timezone.utc)
        history=[]
        for day in [0,1,7,8,15,30,31,40]:
            for hour in [0,1]:
                history.append(dict(remote_directory=f'{day}-{hour}',snapshot_started_at=(now-datetime.timedelta(days=day,hours=hour)).isoformat()))
        keep=retained_names(history,now)
        self.assertTrue({'0-0','0-1','1-0','1-1','7-0','8-0','15-0','30-0'}<=keep)
        self.assertNotIn('15-1',keep); self.assertNotIn('31-0',keep)
        self.assertEqual(len(retained_names(history,now+datetime.timedelta(days=100))),2)
    def test_recovery_age_uses_snapshot_not_upload_finish(self):
        now=datetime.datetime.now(datetime.timezone.utc)
        status={'last_success':{'snapshot_started_at':(now-datetime.timedelta(hours=2)).isoformat(),'completed_at':now.isoformat()}}
        self.assertGreater(age_seconds(status),3600)
        self.assertIsNone(age_seconds({}))
    def test_interrupted_deletion_resumes_from_persisted_inventory(self):
        now=datetime.datetime(2026,9,28,12,tzinfo=datetime.timezone.utc)
        history=[]
        for days in (40,1,0):
            stamp=now-datetime.timedelta(days=days)
            source=stamp.strftime('%Y%m%dT%H%M%SZ')+'-1234abcd'
            history.append(dict(source_backup_id=source,remote_directory=source+'-0123456789abcdef',
                snapshot_started_at=stamp.isoformat(),files={'receipt.json':'fixture-hash'}))
        class InterruptedSMB:
            failed=False
            reads=0
            def run(self, command, missing_ok=False):
                parts=shlex.split(command)
                if parts[0]=='get':
                    self.reads+=1
                    Path(parts[2]).write_text(json.dumps(dict(source_backup_id=history[0]['source_backup_id'],
                        files=history[0]['files'],readback_verified=True)))
                elif not self.failed:
                    self.failed=True
                    raise RuntimeError('Simulated connection loss after journal write')
                else:
                    assert missing_ok
        smb=InterruptedSMB(); persisted=[]
        def save(value): persisted.append(json.loads(json.dumps(value)))
        with tempfile.TemporaryDirectory() as directory:
            with self.assertRaises(RuntimeError):
                prune_recorded_replicas(history,smb,Path(directory),save,now)
            self.assertTrue(persisted[-1][0]['deleting'])
            remaining=prune_recorded_replicas(persisted[-1],smb,Path(directory),save,now)
        self.assertEqual(len(remaining),2)
        self.assertEqual(smb.reads,1)
if __name__=='__main__': unittest.main()
