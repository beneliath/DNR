import importlib.machinery
import importlib.util
import io
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from types import SimpleNamespace
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'scripts'))
import release_manifest
import deploy_release_host
import release_s1
from deployment_notice import DeploymentNotice
from release_timestamp import timestamp_utc
loader = importlib.machinery.SourceFileLoader('prepare', str(ROOT / 'scripts/prepare_release'))
spec = importlib.util.spec_from_loader(loader.name, loader)
prepare = importlib.util.module_from_spec(spec)
loader.exec_module(prepare)


class BoundedReleaseRunner(unittest.TestCase):
    def test_resume_preserves_unpublished_version_and_rejects_published_or_wrong_versions(self):
        for kind, expected, wrong_kind in (
            ('major', '3.0.0', 'minor'),
            ('minor', '2.6.0', 'patch'),
            ('patch', '2.5.10', 'major'),
        ):
            with self.subTest(bump=kind):
                self.check_resume(kind, expected, wrong_kind)

    def check_resume(self, kind, expected, wrong_kind):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'VERSION').write_text(expected + '\n')
            args = SimpleNamespace(resume_version=expected, bump=kind, plugin_bump=None)
            def git(*command):
                return {'origin/main:VERSION': expected, 'deployed:VERSION': '2.5.9'}.get(command[-1], '')
            with patch.object(release_s1, 'ROOT', root), patch.object(release_s1, 'git', side_effect=git), patch.object(release_s1, 'run') as run:
                release_s1.prepare_version(args, 'deployed')
                run.assert_called_once_with(['scripts/prepare_release', 'check', '--base-ref', 'deployed'])
                args.resume_version = '9.9.9'
                with self.assertRaisesRegex(ValueError, 'same prepared version'):
                    release_s1.prepare_version(args, 'deployed')
                args.resume_version = expected
                with patch.object(release_s1, 'git', side_effect=lambda *c: 'published' if c[0] == 'ls-remote' else git(*c)):
                    with self.assertRaisesRegex(ValueError, 'already published'):
                        release_s1.prepare_version(args, 'deployed')
                args.bump = wrong_kind
                with self.assertRaisesRegex(ValueError, 'requested bump'):
                    release_s1.prepare_version(args, 'deployed')

    def test_cli_uses_semver_bumps_for_application_and_plugin(self):
        for kind in ('major', 'minor', 'patch'):
            with self.subTest(bump=kind):
                args = release_s1.parse_args([kind, '--plugin-bump', kind, '--summary', 'SemVer release'])
                self.assertEqual((args.bump, args.plugin_bump), (kind, kind))
        for arguments in (['super'], ['patch', '--plugin-bump', 'super']):
            with self.subTest(arguments=arguments), patch('sys.stderr', new_callable=io.StringIO), \
                    self.assertRaises(SystemExit) as error:
                release_s1.parse_args([*arguments, '--summary', 'SemVer release'])
            self.assertEqual(error.exception.code, 2)

    def test_qualification_only_retains_release_without_deploying(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'download'
            source.mkdir()
            for name in ('manifest.json', 'mirrors.json'):
                (source / name).write_text('{"commit":"' + 'a' * 40 + '"}')
            with patch.object(release_s1, 'STATE_DIRECTORY', root / 'state'), \
                    patch.object(release_s1, 'run') as run:
                saved = release_s1.finish_qualified_release(source, 'a' * 40, True)
                run.assert_not_called()
                self.assertEqual((saved / 'manifest.json').read_bytes(), (source / 'manifest.json').read_bytes())
                self.assertEqual((saved / 'mirrors.json').read_bytes(), (source / 'mirrors.json').read_bytes())
                release_s1.finish_qualified_release(source, 'a' * 40)
                run.assert_called_once_with(['scripts/deploy_s1.sh', 'a' * 40])

    def test_failed_protected_check_blocks_merge(self):
        checks = json.dumps([{
            'name': 'integration', 'state': 'FAILURE', 'bucket': 'fail',
            'link': 'https://example.test/check',
        }])
        result = subprocess.CompletedProcess([], 1, stdout=checks, stderr='')
        with patch.object(release_s1.subprocess, 'run', return_value=result), \
                self.assertRaisesRegex(ValueError, 'integration'):
            release_s1.wait_for_pr(7, 'a' * 40, 10)

    def test_changed_pr_head_blocks_merge_after_checks(self):
        checks = json.dumps([{
            'name': 'integration', 'state': 'SUCCESS', 'bucket': 'pass',
            'link': 'https://example.test/check',
        }])
        result = subprocess.CompletedProcess([], 0, stdout=checks, stderr='')
        with patch.object(release_s1.subprocess, 'run', return_value=result), \
                patch.object(release_s1, 'run', return_value='b' * 40), \
                self.assertRaisesRegex(ValueError, 'head changed'):
            release_s1.wait_for_pr(7, 'a' * 40, 10)


class ReleaseWorkflow(unittest.TestCase):
    def test_replica_verification_ignores_provenance_banner_and_checks_every_worker(self):
        output = 'DNR build provenance: aaaaaaa at 2026-09-22T00:00:00Z\n' + 'a'*64 + '\n' + 'b'*64 + '\n'
        checked = []
        def inspect(identifier):
            checked.append(identifier)
            return {'Image': 'release-image', 'State': {'Health': {'Status': 'healthy'}}}
        deploy_release_host.verify_app_replicas('ai-coach-worker', output, 'release-image', inspect)
        self.assertEqual(checked, ['a'*64, 'b'*64])
        def unhealthy_second(identifier):
            state = inspect(identifier)
            if identifier == 'b'*64:
                state['State']['Health']['Status'] = 'unhealthy'
            return state
        with self.assertRaisesRegex(ValueError, 'health'):
            deploy_release_host.verify_app_replicas('ai-coach-worker', output, 'release-image', unhealthy_second)
        with self.assertRaisesRegex(ValueError, 'different image'):
            deploy_release_host.verify_app_replicas('ai-coach-worker', output, 'wrong-image', inspect)
        with self.assertRaisesRegex(ValueError, 'no running containers'):
            deploy_release_host.verify_app_replicas('ai-coach-worker', 'DNR build provenance: test', 'release-image', inspect)

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

    def test_semver_increments_reset_lower_components(self):
        self.assertEqual(prepare.bump('1.11.6','major'),'2.0.0')
        self.assertEqual(prepare.bump('1.11.6','minor'),'1.12.0')
        self.assertEqual(prepare.bump('1.11.6','patch'),'1.11.7')
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
        for kind, expected, plugin_expected in (
            ('major', '2.0.0', '1.0.0'),
            ('minor', '1.12.0', '0.5.0'),
            ('patch', '1.11.7', '0.4.7'),
        ):
            with self.subTest(bump=kind):
                self.check_preparation(kind, expected, plugin_expected)

    def check_preparation(self, kind, expected, plugin_expected):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); (root/'mattermost-plugin').mkdir()
            (root/'VERSION').write_text('1.11.6\n'); (root/'mattermost-plugin/plugin.json').write_text('{"version":"0.4.6"}\n')
            def git(*args):
                return '{"version":"0.4.6"}' if args[-1].endswith('plugin.json') else '1.11.6'
            with patch.object(prepare,'ROOT',root), patch.object(prepare,'git',side_effect=git), patch.object(prepare.subprocess,'run'), patch.object(sys,'argv',['prepare_release',kind,'--plugin-bump',kind]):
                prepare.main(); prepare.main()
                self.assertEqual((root/'VERSION').read_text(),expected + '\n')
                self.assertEqual(json.loads((root/'mattermost-plugin/plugin.json').read_text())['version'],plugin_expected)
            before={path:path.read_bytes() for path in (root/'VERSION',root/'mattermost-plugin/plugin.json')}
            with patch.object(prepare,'ROOT',root), patch.object(prepare,'git',side_effect=git), patch.object(prepare.subprocess,'run'), patch.object(sys,'argv',['prepare_release','check']): prepare.main()
            for path, content in before.items():
                self.assertEqual(path.read_bytes(),content)

    def test_preparation_rejects_retired_super_bump_before_accessing_release_state(self):
        for arguments in (['super'], ['patch', '--plugin-bump', 'super']):
            with self.subTest(arguments=arguments), patch.object(sys,'argv',['prepare_release',*arguments]), \
                    patch('sys.stderr',new_callable=io.StringIO), patch.object(prepare,'git') as git, \
                    self.assertRaises(SystemExit) as error:
                prepare.main()
            self.assertEqual(error.exception.code,2)
            git.assert_not_called()

    def test_different_prepared_bump_is_rejected_without_writing(self):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory)
            (root/'VERSION').write_text('1.12.0\n')
            with patch.object(prepare,'ROOT',root), patch.object(prepare,'git',return_value='1.11.6'), \
                    patch.object(sys,'argv',['prepare_release','patch']), \
                    self.assertRaisesRegex(ValueError,'different release bump'):
                prepare.main()
            self.assertEqual((root/'VERSION').read_text(),'1.12.0\n')
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
            with patch.object(prepare,'ROOT',root), patch.object(prepare,'git',side_effect=git), patch.object(sys,'argv',['prepare_release','patch','--plugin-bump','patch']), self.assertRaises(ValueError):
                prepare.main()
            self.assertEqual((root/'VERSION').read_text(),'1.11.6\n')

class DeploymentBackupGate(unittest.TestCase):
    def exercise(self, backup_fails, seed_fails=False, photo_fails=False, qr_fails=False):
        import datetime, hashlib, os
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); (root/'.git').mkdir()
            expected='a'*40; previous='c'*40
            images={k:'ghcr.io/example/dnr/'+k+'@sha256:'+'b'*64 for k in ('app','ingress','bridge','database')}
            manifest=root/'manifest.json'
            manifest.write_text(json.dumps(dict(commit=expected,version='1.11.7',migration_mode='pause-writers',images=images,
                migrations={'001.sql':hashlib.sha256(b'SELECT 1;').hexdigest()})))
            (root/'mirrors.json').write_text('{}')
            seed_sha = ''
            if seed_fails:
                (root/'speaker-seed.json').write_text('{}')
                seed_sha = hashlib.sha256(b'{}').hexdigest()
            events=[]
            clock=[1000.0]
            def sleep(seconds):
                self.assertEqual(len([event for event in events if event[:2] == ['docker', 'pull']]), 4,
                                 'Download all qualified images before the countdown')
                clock[0] += seconds
            notice=DeploymentNotice(root, clock=lambda:clock[0], sleep=sleep)
            notice.start('e'*32)
            clock[0]=1180.0  # Three minutes of release preparation already elapsed.
            def run(args,**kwargs):
                events.append(args)
                if args[:2] == ['docker', 'pull']:
                    self.assertEqual(notice.read()['phase'], 'preparing')
                if args==['git','branch','--show-current']: return 'main'
                if args[:2]==['git','status']: return ''
                if args==['git','rev-parse','HEAD']: return previous
                if args==['git','rev-parse','origin/main']: return expected
                if args[:2]==['git','show']:
                    if args[-1].endswith(':VERSION'): return '1.11.7' if args[-1].startswith(expected) else '1.11.6'
                    if args[-1].endswith(':migrations/order.txt'): return '001.sql'
                    return '2026-09-05T15:51:40+02:00'
                if args[:2]==['docker','inspect']:
                    return json.dumps([dict(Id='image-id',Image='image-id',Config={'Env':[], 'Image':'mysql:8.4@sha256:'+'e'*64,'Labels':{'org.opencontainers.image.revision':expected}},State={'Health':{'Status':'healthy'}})])
                if args[:3]==['sh','scripts/compose_with_provenance.sh','production-ubuntu-proton-mattermost']:
                    self.assertEqual(kwargs['env']['DNR_BUILD_TIMESTAMP'], '2026-09-05T13:51:40Z')
                    if args[3:5]==['ps','-aq']: return args[-1]
                    if args[3]=='stop': self.assertGreaterEqual(clock[0],1480.0)
                    if args[3:5]==['run','--rm']:
                        if not seed_fails and not photo_fails and not qr_fails: raise ValueError('synthetic migration failure')
                        if args[-1] == '/opt/dnr/bin/optimize_existing_photos.php':
                            self.assertIn(['backup'], events)
                            self.assertTrue(any(event[-1:] == ['migrator'] for event in events))
                            if photo_fails: raise ValueError('synthetic photo optimization failure')
                        if args[-1] == '/opt/dnr/bin/backfill_short_link_qr.php':
                            self.assertIn(['backup'], events)
                            self.assertTrue(any(event[-1:] == ['migrator'] for event in events))
                            self.assertIn('DNR_PUBLIC_BASE_URL=https://example.test', args)
                            raise ValueError('synthetic QR backfill failure')
                        if 'apply' in args:
                            self.assertIn(['backup'], events)
                            self.assertTrue(any(event[-1:] == ['migrator'] for event in events))
                            raise ValueError('synthetic seed failure')
                    return ''
                return ''
            def backup(*args):
                events.append(['backup'])
                if backup_fails: raise ValueError('synthetic backup failure')
                return {'backup_path':'private.sql.gz.dnrenc','restore_verified':True}
            cwd=Path.cwd()
            try:
                with patch.object(deploy_release_host,'require_encrypted_upload_storage',return_value={'encryption':'dm-crypt'}), patch.object(deploy_release_host,'DeploymentNotice',return_value=notice), patch.object(deploy_release_host,'run',side_effect=run), patch.object(deploy_release_host,'create_verified_backup',side_effect=backup), patch.object(deploy_release_host.subprocess,'run'), patch.object(deploy_release_host.subprocess,'check_output',return_value=b'SELECT 1;'), patch.object(sys,'argv',['host',str(root),expected,str(manifest),'/password','https://example.test','e'*32,seed_sha]), self.assertRaisesRegex(ValueError,'synthetic'):
                    deploy_release_host.main()
                record=json.loads((root/'.git/dnr-deploy'/f'{expected}.json').read_text())
                self.assertEqual(record['outcome'],'failed')
                self.assertEqual(clock[0],1480.0, 'Give a full five-minute window after host preflight')
                self.assertEqual(notice.read()['phase'],'failed')
                merge=[i for i,event in enumerate(events) if event[:2]==['git','merge']]
                backup_index=events.index(['backup'])
                if backup_fails:
                    self.assertFalse(merge)
                    self.assertEqual(record['phase'],'backing-up')
                else:
                    self.assertGreater(merge[0],backup_index)
                    self.assertTrue(record['backup']['restore_verified'])
                    self.assertEqual(record['phase'], 'seeding-speaker' if seed_fails else
                        ('optimizing-photos' if photo_fails else ('preparing-qr-images' if qr_fails else 'migrating')))
                self.assertIn('stop',events[-1], 'Failure must leave writers paused')
            finally: os.chdir(cwd)
    def test_backup_failure_prevents_checkout_and_database_upgrade(self): self.exercise(True)
    def test_migration_failure_retains_verified_recovery_record(self): self.exercise(False)
    def test_speaker_import_failure_keeps_writers_paused_after_verified_backup(self): self.exercise(False, True)
    def test_photo_optimization_failure_keeps_writers_paused_after_verified_backup(self): self.exercise(False, photo_fails=True)
    def test_qr_backfill_failure_keeps_writers_paused_after_verified_backup(self): self.exercise(False, qr_fails=True)

if __name__ == '__main__': unittest.main()
