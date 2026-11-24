import copy
import datetime
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
import gatus_moed as monitor


def container(name='moed-web-1', *, project='moed', oneoff='False', restart='unless-stopped'):
    return {'Name': '/' + name, 'Config': {'Labels': {'com.docker.compose.project': project,
        'com.docker.compose.oneoff': oneoff}}, 'HostConfig': {'RestartPolicy': {'Name': restart}}}


class GatusMoedTests(unittest.TestCase):
    def fixture(self):
        target = dict(container='moed-web-1', key='3-moed-production_moed-web-1', probe='docker-health', token='existing')
        foreign = dict(container='unrelated-db', key='other_db', probe='postgres', token='private')
        doc = {'alerting': {'email': {'password': 'untouched'}}, 'endpoints': [{'name': 'unrelated', 'url': 'https://example.test'}],
               'external-endpoints': [dict(name='moed-web-1', group='3_moed production', token='existing'),
                                      dict(name='db', group='other', token='private')]}
        return {'gatus_url': 'http://127.0.0.1:8087', 'targets': [target, foreign]}, doc

    def test_preserves_unrelated_settings_and_credentials_and_adds_host_coverage(self):
        config, doc = self.fixture(); before = copy.deepcopy((config, doc))
        updated, planned = monitor.plan(config, doc, [container()])
        self.assertEqual((config, doc), before)
        self.assertEqual(planned['alerting'], doc['alerting'])
        self.assertEqual(planned['endpoints'], doc['endpoints'])
        self.assertEqual(updated['targets'][1], config['targets'][1])
        self.assertEqual(planned['external-endpoints'][1], doc['external-endpoints'][1])
        self.assertEqual(updated['targets'][0]['token'], 'existing')
        hosts = [t for t in updated['targets'] if t['probe'] == 'moed-host' and not t.get('awaiting_first_deployment')]
        self.assertEqual(len(hosts), len(monitor.HOST_CHECKS))
        self.assertEqual(planned['external-endpoints'][0]['alerts'][0]['failure-threshold'], 3)

    def test_new_workers_activate_on_deployment_and_missing_workers_never_turn_green(self):
        config, doc = self.fixture()
        config, doc = monitor.plan(config, doc, [container()])
        worker = 'moed-audience-jobs-1'
        target = next(t for t in config['targets'] if t['container'] == worker)
        self.assertTrue(target['awaiting_first_deployment'])
        endpoint = next(e for e in doc['external-endpoints'] if e['name'] == worker)
        self.assertFalse(endpoint['enabled']); token = target['token']
        config, doc = monitor.plan(config, doc, [container(), container(worker)])
        config, doc = monitor.plan(config, doc, [])
        target = next(t for t in config['targets'] if t['container'] == worker)
        self.assertNotIn('awaiting_first_deployment', target)
        self.assertEqual(target['token'], token)
        self.assertTrue(next(e for e in doc['external-endpoints'] if e['name'] == worker)['enabled'])

    def test_discovers_replicas_and_new_accounts_but_ignores_completed_jobs(self):
        config, doc = self.fixture()
        inputs = [container('moed-account-extra-ai-coach-worker-2', project='moed-account-extra'),
                  container('moed-migrator-1', restart='no'), container('moed-maintenance-run-abcd', oneoff='True')]
        updated, _ = monitor.plan(config, doc, inputs)
        names = {t['container'] for t in updated['targets']}
        self.assertIn(inputs[0]['Name'][1:], names)
        self.assertNotIn('moed-migrator-1', names)
        self.assertNotIn('moed-maintenance-run-abcd', names)

    def test_queue_outcome_check_waits_for_installation_then_stale_receipts_fail(self):
        config, doc = self.fixture()
        updated, _ = monitor.plan(config, doc, [], queue_ready=True)
        target = next(t for t in updated['targets'] if t['container'] == 'moed-host-queue-outcomes')
        self.assertNotIn('awaiting_first_deployment', target)
        with tempfile.TemporaryDirectory() as folder:
            home = Path(folder); path = home / '.local/state/moed-queues/health.json'; path.parent.mkdir(parents=True)
            now = datetime.datetime.now(datetime.timezone.utc)
            for age, healthy, expected in [(0, True, True), (1000, True, False), (0, False, False)]:
                path.write_text(json.dumps({'checked_at': (now - datetime.timedelta(seconds=age)).isoformat(),
                                            'accounts': {'primary': {'healthy': healthy}}}))
                self.assertEqual(monitor.probe_host(target, home=home, now=now.timestamp())[0], expected)

    def test_account_worker_requires_live_service_and_recent_success(self):
        with tempfile.TemporaryDirectory() as folder, patch.object(monitor, 'service_properties', return_value={'ActiveState': 'active', 'SubState': 'running'}):
            home = Path(folder); path = home / 'moed/var/deployment/account-production-health.json'; path.parent.mkdir(parents=True)
            target = {'container': 'moed-host-account-worker'}
            for timestamp, ok, expected in [(999, True, True), (700, True, False), (999, False, False)]:
                path.write_text(json.dumps({'checked_at': timestamp, 'ok': ok}))
                self.assertEqual(monitor.probe_host(target, home=home, now=1000)[0], expected)
        with patch.object(monitor, 'service_properties', side_effect=subprocess.TimeoutExpired(['systemctl'], 20)):
            self.assertFalse(monitor.probe_host(target)[0])

    def test_backup_requires_fresh_verified_copies_for_every_expected_account(self):
        with tempfile.TemporaryDirectory() as folder:
            home = Path(folder); path = home / '.local/state/moed-backup/status.json'; path.parent.mkdir(parents=True)
            now = datetime.datetime.now(datetime.timezone.utc)
            receipt = {'expected_account_keys': ['primary', 'test-account'], 'last_success': {'snapshot_started_at': now.isoformat(),
                'accounts': {key: {'snapshot_started_at': now.isoformat(), 'remote_directory': key} for key in ('primary', 'test-account')}}}
            target = {'container': 'moed-host-nas-recovery'}
            path.write_text(json.dumps(receipt)); self.assertTrue(monitor.probe_host(target, home=home)[0])
            del receipt['last_success']['accounts']['test-account']
            path.write_text(json.dumps(receipt)); self.assertFalse(monitor.probe_host(target, home=home)[0])
            self.assertFalse(monitor.fresh(now.isoformat(), 270 * 60, now.timestamp() + 271 * 60))

    def test_systemd_user_checks_work_without_a_login_session(self):
        with patch.dict(os.environ, {}, clear=True), patch.object(monitor.subprocess, 'check_output', return_value='ActiveState=active\n') as command:
            self.assertEqual(monitor.service_properties('moed-account-worker.service')['ActiveState'], 'active')
        environment = command.call_args.kwargs['env']
        self.assertEqual(environment['DBUS_SESSION_BUS_ADDRESS'], 'unix:path=/run/user/' + str(os.getuid()) + '/bus')
        self.assertEqual(command.call_args.kwargs['timeout'], 20)

    def test_scheduled_queue_monitor_is_checked_even_before_first_success(self):
        with tempfile.TemporaryDirectory() as folder:
            home = Path(folder)
            with patch.object(monitor, 'run', return_value='# */5 * * * * python3 /helpers/queue_monitor.py\n'):
                self.assertFalse(monitor.queue_monitor_installed(home))
            with patch.object(monitor, 'run', return_value='*/5 * * * * python3 /helpers/queue_monitor.py\n'):
                self.assertTrue(monitor.queue_monitor_installed(home))
            self.assertFalse(monitor.probe_host({'container': 'moed-host-queue-outcomes'}, home=home)[0])

    def test_retention_requires_an_active_timer_and_successful_recent_run(self):
        target = {'container': 'moed-host-deployment-retention'}
        for result, age, expected in [('success', 100, True), ('failed', 100, False), ('success', 28 * 3600, False)]:
            services = [{'ActiveState': 'active'}, {'Result': result, 'ExecMainStatus': '0',
                        'ExecMainExitTimestampMonotonic': str((200000 - age) * 1000000)}]
            with patch.object(monitor, 'service_properties', side_effect=services), patch.object(monitor.time, 'monotonic', return_value=200000):
                self.assertEqual(monitor.probe_host(target)[0], expected)

    def test_reconciliation_is_idempotent_and_rejects_conflicting_tokens(self):
        config, doc = self.fixture()
        updated, planned = monitor.plan(config, doc, [container()])
        self.assertEqual(monitor.plan(updated, planned, [container()]), (updated, planned))
        doc['external-endpoints'][0]['token'] = 'changed'
        with self.assertRaises(ValueError): monitor.plan(config, doc, [container()])


if __name__ == '__main__':
    unittest.main()
