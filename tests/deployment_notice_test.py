import fcntl
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / 'scripts'))
from deployment_notice import DeploymentNotice, NOTICE_LIFETIME_SECONDS


class NoticeLifecycle(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.now = 1000.0
        self.sleeps = []
        self.notice = DeploymentNotice(self.root, clock=lambda: self.now, sleep=self.sleep)
        self.identifier = 'a' * 32
        self.commit = 'b' * 40
        self.notice.start(self.identifier)

    def sleep(self, seconds):
        self.sleeps.append(seconds)
        self.now += seconds

    def test_fast_preparation_waits_only_remaining_time(self):
        self.now += 180
        result = self.notice.wait_and_claim(self.identifier, self.commit)
        self.assertEqual(sum(self.sleeps), 120)
        self.assertEqual(result['phase'], 'deploying')
        self.assertEqual(result['commit'], self.commit)
        self.assertEqual(self.now, 1300)

    def test_slow_preparation_and_exact_deadline_do_not_wait(self):
        for elapsed in (300, 480):
            with self.subTest(elapsed=elapsed):
                self.now = 1000 + elapsed
                self.notice.wait_and_claim(self.identifier, self.commit)
                self.assertEqual(self.sleeps, [])
                # Restore the pending fixture for the second independent boundary.
                state = self.notice.read()
                state['phase'] = 'pending'
                self.notice.write(state)

    def test_retry_keeps_original_deadline_and_id(self):
        before = self.notice.read()
        self.now += 80
        self.assertEqual(self.notice.start(self.identifier), before)

    def test_different_workflow_cannot_replace_an_active_notice(self):
        with self.assertRaisesRegex(ValueError, 'Another deployment'):
            self.notice.start('c' * 32)
        self.assertEqual(self.notice.read()['id'], self.identifier)

    def test_cancel_during_wait_prevents_maintenance(self):
        def cancel(seconds):
            self.sleep(seconds)
            self.notice.cancel(self.identifier)
        self.notice.sleep = cancel
        with self.assertRaisesRegex(ValueError, 'cancelled'):
            self.notice.wait_and_claim(self.identifier, self.commit)
        self.assertEqual(self.notice.read()['phase'], 'cancelled')

    def test_cancellation_cannot_clear_active_or_failed_maintenance(self):
        self.now += 300
        self.notice.wait_and_claim(self.identifier, self.commit)
        for phase in ('deploying', 'failed'):
            if phase == 'failed':
                self.notice.finish(self.identifier, phase)
            with self.assertRaisesRegex(ValueError, 'recovery'):
                self.notice.cancel(self.identifier)
            self.assertEqual(self.notice.read()['phase'], phase)

    def test_success_allows_next_workflow_without_reusing_old_deadline(self):
        self.now += 300
        self.notice.wait_and_claim(self.identifier, self.commit)
        self.notice.finish(self.identifier, 'complete')
        self.notice.cancel(self.identifier)  # Shell cleanup preserves completion.
        self.assertEqual(self.notice.read()['phase'], 'complete')
        self.now += 30
        state = self.notice.start('c' * 32)
        self.assertEqual(state['not_before'], 1630)

    def test_missing_mismatched_and_expired_notices_cannot_deploy(self):
        with self.assertRaises(ValueError):
            self.notice.wait_and_claim('d' * 32, self.commit)
        self.now += NOTICE_LIFETIME_SECONDS
        with self.assertRaisesRegex(ValueError, 'expired'):
            self.notice.wait_and_claim(self.identifier, self.commit)
        with self.assertRaises(ValueError):
            self.notice.start(self.identifier)
        self.notice.path.unlink()
        with self.assertRaises(ValueError):
            self.notice.wait_and_claim(self.identifier, self.commit)
        self.assertEqual(self.sleeps, [])

    def test_shortened_window_is_rejected(self):
        state = self.notice.read()
        state['not_before'] = state['started_at']
        self.notice.write(state)
        with self.assertRaisesRegex(ValueError, 'save window'):
            self.notice.wait_and_claim(self.identifier, self.commit)

    def test_public_file_is_atomic_and_world_readable_but_lock_is_private(self):
        self.assertEqual(self.notice.path.stat().st_mode & 0o777, 0o644)
        self.assertEqual((self.notice.directory / 'notice.lock').stat().st_mode & 0o777, 0o600)
        self.assertFalse(self.notice.path.with_suffix('.tmp').exists())
        self.assertEqual(json.loads(self.notice.path.read_text())['id'], self.identifier)

    def test_recovery_resolution_refuses_running_deployment(self):
        self.now += 300
        self.notice.wait_and_claim(self.identifier, self.commit)
        lock_path = self.root / '.git/dnr-deploy/deploy.lock'
        lock_path.parent.mkdir(parents=True)
        with lock_path.open('a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            with self.assertRaises(BlockingIOError):
                self.notice.resolve(self.identifier, self.root)
        self.notice.resolve(self.identifier, self.root)
        self.assertEqual(self.notice.read()['phase'], 'cancelled')


class NoticeCommand(unittest.TestCase):
    def test_start_reuses_cached_id_and_cancel_clears_it_without_network(self):
        source = Path(__file__).resolve().parent.parent
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'scripts').mkdir()
            (root / 'bin').mkdir()
            host = root / 'host'
            host.mkdir()
            for name in ('deployment_notice.sh', 'deployment_notice.py'):
                shutil.copy(source / 'scripts' / name, root / 'scripts' / name)
            # Replace only the SSH transport; execute the real host module via stdin.
            (root / 'bin/ssh').write_text('#!' + sys.executable + '\n'
                'import shlex, subprocess, sys\n'
                'sys.exit(subprocess.run(shlex.split(sys.argv[-1]), stdin=sys.stdin).returncode)\n')
            (root / 'bin/git').write_text('#!/bin/sh\nprintf "%s\\n" .git/dnr-deploy\n')
            for name in ('ssh', 'git'):
                (root / 'bin' / name).chmod(0o755)
            environment = dict(os.environ, PATH=str(root / 'bin') + os.pathsep + os.environ['PATH'],
                               DNR_S1_PROJECT_DIR=str(host))
            environment.pop('DNR_DEPLOYMENT_NOTICE_ID', None)
            def command(action):
                return subprocess.check_output(['sh', str(root / 'scripts/deployment_notice.sh'), action],
                                               env=environment, text=True).strip()
            first = command('start')
            original = DeploymentNotice(host).read()
            self.assertRegex(first, r'^[a-f0-9]{32}$')
            self.assertEqual(command('start'), first)
            self.assertEqual(DeploymentNotice(host).read(), original)
            self.assertEqual(json.loads(command('status'))['id'], first)
            self.assertEqual(command('cancel'), first)
            self.assertFalse((root / '.git/dnr-deploy/notice-id').exists())
            self.assertEqual(DeploymentNotice(host).read()['phase'], 'cancelled')
            second = command('start')
            self.assertNotEqual(second, first)
            self.assertEqual(DeploymentNotice(host).read()['phase'], 'pending')


if __name__ == '__main__':
    unittest.main()
