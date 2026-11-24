import datetime
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
import queue_monitor as monitor


def report(*issues):
    queues = [dict(key='q' + str(i), label='Queue ' + str(i), issues=list(issues) if i == 0 else [],
                   queued=1, retry=0, processing=0, failed=0, partial=0, uncertain=1 if issues else 0,
                   stalled=0, oldest_due_seconds=0, action='Check provider receipts before resending.') for i in range(8)]
    return dict(account_key='primary', healthy=not issues, checked_at=datetime.datetime.now(datetime.timezone.utc).isoformat(), queues=queues)


class QueueMonitorTests(unittest.TestCase):
    def test_alert_lifecycle_and_new_issue_deduplication(self):
        now = datetime.datetime.now(datetime.timezone.utc)
        bad = report('uncertain')
        self.assertFalse(monitor.queue_alert_due({}, report(), now))
        self.assertTrue(monitor.queue_alert_due({}, bad, now))
        previous = dict(healthy=False, sent_at=now.isoformat(), issues=monitor.fingerprint(bad))
        self.assertFalse(monitor.queue_alert_due(previous, bad, now))
        self.assertTrue(monitor.queue_alert_due(previous, bad, now + datetime.timedelta(hours=6)))
        self.assertTrue(monitor.queue_alert_due(previous, report('uncertain', 'stalled'), now))
        self.assertTrue(monitor.queue_alert_due(previous, report(), now))
        self.assertFalse(monitor.queue_alert_due(dict(healthy=True, sent_at=now.isoformat(), issues=[]), report(), now))

    def test_discovery_failure_cannot_report_healthy(self):
        with patch.object(monitor, 'account_directory', side_effect=ValueError('secret detail')):
            reports = monitor.collect_health(Path('/project'), 'moed')
        self.assertFalse(reports['account-directory']['healthy'])
        self.assertNotIn('secret', json.dumps(reports))

    def test_each_account_is_checked_and_bad_identity_is_unavailable(self):
        accounts = [dict(key='primary', project='moed'), dict(key='member', project='moed-member')]
        responses = [subprocess.CompletedProcess([], 0, json.dumps(report()), ''),
                     subprocess.CompletedProcess([], 0, json.dumps(report()), '')]
        with patch.object(monitor, 'account_directory', return_value=accounts), \
             patch.object(monitor, 'resolve_service', side_effect=['web-primary', 'web-member']), \
             patch.object(monitor.subprocess, 'run', side_effect=responses) as run:
            reports = monitor.collect_health(Path('/project'), 'moed')
        self.assertTrue(reports['primary']['healthy'])
        self.assertFalse(reports['member']['healthy'])
        self.assertEqual(run.call_count, 2)
        self.assertEqual(run.call_args_list[1].args[0][4], 'web-member')

    def test_query_failures_missing_coverage_and_timeout_are_unavailable(self):
        for result in [subprocess.CompletedProcess([], 2, '', 'secret'),
                       subprocess.CompletedProcess([], 0, '{', ''),
                       subprocess.CompletedProcess([], 0, json.dumps(dict(report(), queues=[])), ''),
                       subprocess.CompletedProcess([], 0, json.dumps(report('uncertain')), '')]:
            with self.subTest(result=result), patch.object(monitor, 'account_directory', return_value=[dict(key='primary', project='moed')]), \
                 patch.object(monitor, 'resolve_service', return_value='web'), patch.object(monitor.subprocess, 'run', return_value=result):
                self.assertFalse(monitor.collect_health(Path('/project'), 'moed')['primary']['healthy'])
        with patch.object(monitor, 'account_directory', return_value=[dict(key='primary', project='moed')]), \
             patch.object(monitor, 'resolve_service', side_effect=TimeoutError):
            self.assertIn('unavailable', monitor.collect_health(Path('/project'), 'moed')['primary'])

    def test_check_only_never_loads_smtp_or_writes_state(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(monitor, 'collect_health', return_value={'primary': report('failed')}), \
             patch.object(monitor, 'load_smtp_configuration') as smtp, patch('builtins.print'):
            state = Path(directory) / 'not-created'
            self.assertEqual(monitor.monitor(Path('/project'), 'moed', state, 'test@example.test', True), 1)
            self.assertFalse(state.exists())
            smtp.assert_not_called()

    def test_unresponsive_docker_uses_cached_smtp_and_reports_discovery_failure(self):
        with tempfile.TemporaryDirectory() as directory:
            state = Path(directory)
            cached = dict(sender='monitor@example.test', encryption='starttls')
            cache = state / 'smtp-connection.json'
            cache.write_text(json.dumps(cached)); cache.chmod(0o600)

            def unresponsive(*args, **kwargs):
                # Exercise actual discovery and SMTP fallback: every Docker
                # probe must be bounded before a hung engine can raise this.
                self.assertEqual(kwargs.get('timeout'), 30)
                raise subprocess.TimeoutExpired(args[0], 30)

            with patch('subprocess.check_output', side_effect=unresponsive), \
                 patch.object(monitor, 'send_smtp_message') as send, patch('builtins.print'):
                self.assertEqual(monitor.monitor(Path('/project'), 'moed', state, 'admin@example.test'), 1)
            self.assertEqual(send.call_args.args[0], cached)
            self.assertIn('Account discovery unavailable', send.call_args.args[1].get_content())
            self.assertFalse(json.loads((state / 'alerts.json').read_text())['account-directory']['healthy'])

    def test_per_account_alerts_are_acknowledged_only_after_acceptance(self):
        with tempfile.TemporaryDirectory() as directory, patch.object(monitor, 'collect_health', return_value={'primary': report('failed'), 'member': report('stalled')}), \
             patch.object(monitor, 'load_smtp_configuration', return_value={}), \
             patch.object(monitor, 'send_queue_alert', side_effect=[None, RuntimeError('transport unavailable')]):
            with self.assertRaises(RuntimeError):
                monitor.monitor(Path('/project'), 'moed', Path(directory), 'test@example.test')
            saved = json.loads((Path(directory) / 'alerts.json').read_text())
            self.assertIn('primary', saved)
            self.assertNotIn('member', saved)

    def test_alerts_include_account_counts_and_safe_recovery_action(self):
        with patch.object(monitor, 'send_smtp_message') as send:
            monitor.send_queue_alert(dict(sender='monitor@example.test'), 'admin@example.test', 'member', report('uncertain'))
        message = send.call_args.args[1]
        self.assertIn('member', message['Subject'])
        self.assertIn('/a/member/operations.php', message.get_content())
        self.assertIn('uncertain: 1', message.get_content())
        self.assertIn('before resending', message.get_content())


if __name__ == '__main__':
    unittest.main()
