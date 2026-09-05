import importlib.machinery
import importlib.util
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'scripts'))
import release_manifest
import deploy_release_host
from deployment_notice import DeploymentNotice
from release_timestamp import timestamp_utc
loader = importlib.machinery.SourceFileLoader('prepare', str(ROOT / 'scripts/prepare_release'))
spec = importlib.util.spec_from_loader(loader.name, loader)
prepare = importlib.util.module_from_spec(spec)
loader.exec_module(prepare)

class ReleaseWorkflow(unittest.TestCase):
    def test_commit_timezones_produce_identical_utc_provenance(self):
        import os, subprocess
        expected = '2026-09-05T13:51:40Z'
        for value in ('2026-09-05T15:51:40+02:00', '2026-09-05T08:51:40-05:00',
                      '2026-09-05T19:21:40+05:30', '2026-09-06T03:51:40+14:00',
                      '2026-09-05T13:51:40+00:00', expected):
            with self.subTest(timestamp=value):
                normalized = timestamp_utc(value)
                self.assertEqual(normalized, expected)
                metadata = subprocess.check_output(['sh', str(ROOT/'scripts/compose_with_provenance.sh'), '--print-metadata'],
                    env=dict(os.environ, DNR_BUILD_COMMIT='a'*40, DNR_BUILD_TIMESTAMP=normalized), text=True)
                self.assertIn('DNR_BUILD_TIMESTAMP=' + expected, metadata.splitlines())
        with self.assertRaisesRegex(ValueError, 'timezone'):
            timestamp_utc('2026-09-05T13:51:40')

    def test_project_version_vocabulary(self):
        self.assertEqual(prepare.bump('1.11.6','minor'),'1.11.7')
        self.assertEqual(prepare.bump('1.11.6','major'),'1.12.0')
        self.assertEqual(prepare.bump('1.11.6','super'),'2.0.0')
        with self.assertRaises(ValueError): prepare.version('1.01.6')
    def test_release_manifest_rejects_unqualified_inputs(self):
        m=dict(format=1,commit='a'*40,version=(ROOT/'VERSION').read_text().strip(),
               migrations=release_manifest.migration_manifest(ROOT),
               images={k:'ghcr.io/example/dnr/'+k+'@sha256:'+'b'*64 for k in ('app','ingress','bridge','database')},
               plugin_sha256='c'*64,plugin_version=json.loads((ROOT/'mattermost-plugin/plugin.json').read_text())['version'],
               migration_mode='pause-writers',ci_run_id='1')
        release_manifest.validate(m)
        for field, bad in [('commit','main'),('migrations',{}),('images',{'app':'latest'}),('migration_mode','unknown'),('ci_run_id','')]:
            with self.subTest(field=field), self.assertRaises(ValueError): release_manifest.validate(dict(m,**{field:bad}))
        with self.assertRaises(ValueError): release_manifest.validate(m,expected='d'*40)
    def test_preparation_is_idempotent(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); (root/'mattermost-plugin').mkdir()
            (root/'VERSION').write_text('1.11.6\n'); (root/'mattermost-plugin/plugin.json').write_text('{"version":"0.4.6"}\n')
            with patch.object(prepare,'ROOT',root), patch.object(prepare,'git',return_value='1.11.6'), patch.object(prepare.subprocess,'run'), patch.object(sys,'argv',['prepare_release','minor']):
                prepare.main(); prepare.main()
                self.assertEqual((root/'VERSION').read_text(),'1.11.7\n')
            before=(root/'VERSION').read_bytes()
            with patch.object(prepare,'ROOT',root), patch.object(prepare,'git',return_value='1.11.6'), patch.object(prepare.subprocess,'run'), patch.object(sys,'argv',['prepare_release','check']): prepare.main()
            self.assertEqual((root/'VERSION').read_bytes(),before)
    def test_host_moved_main_fails_before_checkout_or_writer_stop(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); (root/'.git').mkdir()
            manifest=root/'manifest.json'; expected='a'*40
            manifest.write_text(json.dumps(dict(commit=expected,migration_mode='pause-writers',images={k:'ghcr.io/example/dnr/'+k+'@sha256:'+'b'*64 for k in ('app','ingress','bridge','database')})))
            calls=[]
            def run(args,**kwargs):
                calls.append(args)
                if args==['git','branch','--show-current']: return 'main'
                if args[:2]==['git','status']: return ''
                if args==['git','rev-parse','HEAD']: return 'c'*40
                if args==['git','rev-parse','origin/main']: return 'd'*40
                return ''
            previous=Path.cwd()
            try:
                with patch.object(deploy_release_host,'run',side_effect=run), patch.object(sys,'argv',['host',str(root),expected,str(manifest),'/missing','https://example.test','e'*32]), self.assertRaisesRegex(ValueError,'main moved'):
                    deploy_release_host.main()
                self.assertFalse(any('merge' in call or 'stop' in call for call in calls))
            finally:
                import os; os.chdir(previous)

    def test_invalid_plugin_bump_leaves_application_version_untouched(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); (root/'mattermost-plugin').mkdir()
            (root/'VERSION').write_text('1.11.6\n')
            (root/'mattermost-plugin/plugin.json').write_text('{"version":"0.5.0"}\n')
            def git(*args):
                return '{"version":"0.4.6"}' if args[-1].endswith('plugin.json') else '1.11.6'
            with patch.object(prepare,'ROOT',root), patch.object(prepare,'git',side_effect=git), patch.object(sys,'argv',['prepare_release','minor','--plugin-bump','minor']), self.assertRaises(ValueError):
                prepare.main()
            self.assertEqual((root/'VERSION').read_text(),'1.11.6\n')

class DeploymentBackupGate(unittest.TestCase):
    def exercise(self, backup_fails):
        import datetime, hashlib, os
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); (root/'.git').mkdir()
            expected='a'*40; previous='c'*40
            images={k:'ghcr.io/example/dnr/'+k+'@sha256:'+'b'*64 for k in ('app','ingress','bridge','database')}
            manifest=root/'manifest.json'
            manifest.write_text(json.dumps(dict(commit=expected,version='1.11.7',migration_mode='pause-writers',images=images,
                migrations={'001.sql':hashlib.sha256(b'SELECT 1;').hexdigest()})))
            (root/'mirrors.json').write_text('{}')
            events=[]
            clock=[1000.0]
            def sleep(seconds):
                clock[0] += seconds
            notice=DeploymentNotice(root, clock=lambda:clock[0], sleep=sleep)
            notice.start('e'*32)
            clock[0]=1180.0  # Three minutes of release preparation already elapsed.
            def run(args,**kwargs):
                events.append(args)
                if args==['git','branch','--show-current']: return 'main'
                if args[:2]==['git','status']: return ''
                if args==['git','rev-parse','HEAD']: return previous
                if args==['git','rev-parse','origin/main']: return expected
                if args[:2]==['git','show']:
                    if args[-1].endswith(':VERSION'): return '1.11.7' if args[-1].startswith(expected) else '1.11.6'
                    if args[-1].endswith(':migrations/order.txt'): return '001.sql'
                    return '2026-09-05T15:51:40+02:00'
                if args[:2]==['docker','inspect']:
                    return json.dumps([dict(Id='image-id',Image='image-id',Config={'Image':'mysql:8.4@sha256:'+'e'*64,'Labels':{'org.opencontainers.image.revision':expected}},State={'Health':{'Status':'healthy'}})])
                if args[:3]==['sh','scripts/compose_with_provenance.sh','production-ubuntu-proton-mattermost']:
                    self.assertEqual(kwargs['env']['DNR_BUILD_TIMESTAMP'], '2026-09-05T13:51:40Z')
                    if args[3:5]==['ps','-aq']: return args[-1]
                    if args[3]=='stop': self.assertGreaterEqual(clock[0],1300.0)
                    if args[3:5]==['run','--rm']: raise ValueError('synthetic migration failure')
                    return ''
                return ''
            def backup(*args):
                events.append(['backup'])
                if backup_fails: raise ValueError('synthetic backup failure')
                return {'backup_path':'private.sql.gz.dnrenc','restore_verified':True}
            cwd=Path.cwd()
            try:
                with patch.object(deploy_release_host,'DeploymentNotice',return_value=notice), patch.object(deploy_release_host,'run',side_effect=run), patch.object(deploy_release_host,'create_verified_backup',side_effect=backup), patch.object(deploy_release_host.subprocess,'run'), patch.object(deploy_release_host.subprocess,'check_output',return_value=b'SELECT 1;'), patch.object(sys,'argv',['host',str(root),expected,str(manifest),'/password','https://example.test','e'*32]), self.assertRaisesRegex(ValueError,'synthetic'):
                    deploy_release_host.main()
                record=json.loads((root/'.git/dnr-deploy'/f'{expected}.json').read_text())
                self.assertEqual(record['outcome'],'failed')
                self.assertEqual(clock[0],1300.0, 'Only the remaining two minutes should be padded')
                self.assertEqual(notice.read()['phase'],'failed')
                merge=[i for i,event in enumerate(events) if event[:2]==['git','merge']]
                backup_index=events.index(['backup'])
                if backup_fails:
                    self.assertFalse(merge)
                    self.assertEqual(record['phase'],'backing-up')
                else:
                    self.assertGreater(merge[0],backup_index)
                    self.assertTrue(record['backup']['restore_verified'])
                    self.assertEqual(record['phase'],'migrating')
                self.assertIn('stop',events[-1], 'Failure must leave writers paused')
            finally: os.chdir(cwd)
    def test_backup_failure_prevents_checkout_and_database_upgrade(self): self.exercise(True)
    def test_migration_failure_retains_verified_recovery_record(self): self.exercise(False)

if __name__ == '__main__': unittest.main()
