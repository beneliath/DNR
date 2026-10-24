import contextlib
import datetime
import hashlib
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'scripts'))
import run_nas_recovery as runner


class MultiAccountRunTests(unittest.TestCase):
    def run_fixture(self, fail_second):
        with tempfile.TemporaryDirectory() as folder, contextlib.ExitStack() as stack:
            home=Path(folder); root=home/'project'
            (root/'.git/dnr-deploy').mkdir(parents=True); (root/'VERSION').write_text('2.7.6')
            config=home/'.config/moed-backup'; config.mkdir(parents=True)
            config.joinpath('recovery-key-coverage.json').write_text(json.dumps(dict(decrypted_and_verified=True,
                key_fingerprint='fixture',remote_path='recovery-key-'+'a'*24+'.dnrenc',sha256=hashlib.sha256(b'fixture').hexdigest())))
            state=home/'.local/state/moed-backup'; state.mkdir(parents=True)
            previous={'snapshot_started_at':'2020-01-01T00:00:00+00:00'}
            (state/'status.json').write_text(json.dumps({'last_success':previous}))
            accounts=[dict(key=k,primary=k=='primary',project=k,db=k,web=k,image='fixture',required_files=[],runtime_files={}) for k in ('primary','test-account')]
            def create(db,web,password,snapshots,commit,version):
                if db=='test-account' and fail_second: raise ValueError('Synthetic second-account failure')
                directory=snapshots/('20261024T120000Z-'+('a'*8 if db=='primary' else 'b'*8))
                directory.mkdir(parents=True)
                return dict(backup_path=str(directory/'database.sql.gz.dnrenc'),snapshot_started_at=datetime.datetime.now(datetime.timezone.utc).isoformat())
            def remote(command): Path(command.split()[-1]).write_bytes(b'fixture')
            def output(command,**kwargs):
                if command[0]=='git': return 'fixture\n'
                if command[1]=='ps': return 'fixture\n'
                return json.dumps([{'Config':{'Labels':{'com.docker.compose.service':'web'},'Image':'fixture'}}])
            patches=[patch.object(Path,'home',return_value=home),patch.object(sys,'argv',['run','--project',str(root)]),
                patch.object(runner,'account_directory',return_value=accounts),patch.object(runner,'resolve_account',side_effect=lambda a,*_:a),
                patch.object(runner,'key_fingerprint',return_value='fixture'),patch.object(runner,'query',return_value='0'),
                patch.object(runner,'create_online_backup',side_effect=create),patch.object(runner,'host_recovery_files',return_value={}),
                patch.object(runner,'replicate',side_effect=lambda directory,smb:directory.name+'-'+'c'*16),
                patch.object(runner,'inventory',return_value={'receipt.json':{'sha256':'fixture','bytes':1}}),
                patch.object(runner,'prune_recorded_replicas',side_effect=lambda history,*_:history),
                patch.object(runner.subprocess,'check_output',side_effect=output),patch.object(runner,'SMB'),
                patch.object(runner.signal,'signal'),patch.object(runner.signal,'alarm'),
                patch.object(runner.shutil,'disk_usage',return_value=type('Disk',(),{'free':10**12})())]
            mocks=[stack.enter_context(p) for p in patches]
            runner.SMB.return_value.run.side_effect=remote
            code=runner.main()
            status=json.loads((state/'status.json').read_text()); history=json.loads((state/'replicas.json').read_text())
            return code,status,history,previous

    def test_success_requires_and_records_both_accounts(self):
        code,status,history,_=self.run_fixture(False)
        self.assertEqual(code,0)
        self.assertEqual(set(status['last_success']['accounts']),{'primary','test-account'})
        self.assertEqual({e['account_key'] for e in history},{'primary','test-account'})

    def test_partial_failure_keeps_previous_success_and_published_copy_ledger(self):
        code,status,history,previous=self.run_fixture(True)
        self.assertEqual(code,1)
        self.assertEqual(status['last_success'],previous)
        self.assertEqual(status['last_error']['type'],'ValueError')
        self.assertEqual(status['current_account'],'test-account')
        self.assertEqual([e['account_key'] for e in history],['primary'])


if __name__=='__main__': unittest.main()
