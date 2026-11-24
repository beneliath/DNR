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

    def test_compose_replacement_names_never_become_checks(self):
        config, doc = self.fixture()
        for project, service in [('moed', 'ai-coach-tunnel'), ('moed-account-extra', 'audience-jobs')]:
            with self.subTest(project=project):
                name = project + '-' + service + '-1'
                temporary = 'e2ef9e275a5d_' + name
                old = container(temporary, project=project)
                # Replacement overlaps the original, then the temporary name
                # disappears. Only the stable service must survive discovery.
                updated, planned = monitor.plan(config, doc, [old, container(name, project=project)])
                self.assertNotIn(temporary, {t['container'] for t in updated['targets']})
                self.assertNotIn(temporary, {e['name'] for e in planned['external-endpoints']})
                again, endpoints = monitor.plan(updated, planned, [old])
                self.assertFalse(next(t for t in again['targets'] if t['container'] == name).get('awaiting_first_deployment'))
                self.assertTrue(next(e for e in endpoints['external-endpoints'] if e['name'] == name)['enabled'])
                final, _ = monitor.plan(again, endpoints, [])
                self.assertIn(name, {t['container'] for t in final['targets']})

    def test_temporary_worker_alone_does_not_activate_staged_check(self):
        config, doc = self.fixture()
        updated, planned = monitor.plan(config, doc, [container('e2ef9e275a5d_moed-audience-jobs-1')])
        self.assertTrue(next(t for t in updated['targets'] if t['container'] == 'moed-audience-jobs-1')['awaiting_first_deployment'])
        self.assertFalse(next(e for e in planned['external-endpoints'] if e['name'] == 'moed-audience-jobs-1')['enabled'])

    def test_mattermost_queue_alert_preserves_routing_and_thresholds(self):
        config, doc = self.fixture()
        doc['alerting']['mattermost'] = {'webhook-url': 'https://chat.example.test/default',
            'default-alert': {'description': '@doug Health Check Failed'},
            'overrides': [{'group': monitor.HOST_GROUP, 'webhook-url': 'https://chat.example.test/operations', 'channel': 'operations'}]}
        updated, planned = monitor.plan(config, doc, [], queue_ready=True)
        endpoint = next(e for e in planned['external-endpoints'] if e['name'] == 'moed-host-queue-outcomes')
        alert = next(a for a in endpoint['alerts'] if a['type'] == 'custom')
        connection = alert['provider-override']
        self.assertEqual(connection['url'], 'https://chat.example.test/operations')
        body = json.loads(connection['body'])
        self.assertEqual(body['channel'], 'operations')
        self.assertIn('@doug', body['text']); self.assertIn('[RESULT_ERRORS]', body['text'])
        self.assertEqual((alert['failure-threshold'], alert['success-threshold'], alert['send-on-resolved']), (3, 2, True))
        self.assertEqual(planned['alerting']['mattermost'], doc['alerting']['mattermost'])
        self.assertEqual([a['type'] for a in endpoint['alerts']], ['email', 'custom'])
        self.assertEqual(monitor.plan(updated, planned, [], queue_ready=True), (updated, planned))
        # A changing queue count never participates in configuration planning.
        self.assertNotIn('uncertain=', connection['body'])

    def test_existing_custom_provider_and_alert_overrides_are_preserved(self):
        config, doc = self.fixture()
        doc['alerting']['mattermost'] = {'webhook-url': 'https://chat.example.test/default'}
        existing = {'url': 'https://other.example.test', 'method': 'PUT', 'body': 'keep',
                    'client': {'insecure': True}, 'default-alert': {'enabled': False, 'failure-threshold': 10}}
        doc['alerting']['custom'] = copy.deepcopy(existing)
        doc['external-endpoints'].append({'name': 'moed-host-queue-outcomes', 'group': monitor.HOST_GROUP, 'token': 'queue-secret',
            'alerts': [{'type': 'mattermost', 'enabled': False, 'failure-threshold': 5, 'success-threshold': 4,
                        'send-on-resolved': False, 'description': '@ops Review queues', 'minimum-reminder-interval': '6h',
                        'provider-override': {'webhook-url': 'https://chat.example.test/private', 'channel': 'queue-alerts'}}]})
        _, planned = monitor.plan(config, doc, [], queue_ready=True)
        self.assertEqual(planned['alerting']['custom'], existing)
        endpoint = next(e for e in planned['external-endpoints'] if e['name'] == 'moed-host-queue-outcomes')
        alert = endpoint['alerts'][0]
        self.assertEqual([alert[k] for k in ('enabled', 'failure-threshold', 'success-threshold', 'send-on-resolved', 'minimum-reminder-interval')],
                         [False, 5, 4, False, '6h'])
        self.assertEqual(alert['provider-override']['url'], 'https://chat.example.test/private')
        self.assertEqual(alert['provider-override']['client'], {})
        self.assertEqual(json.loads(alert['provider-override']['body'])['channel'], 'queue-alerts')

    def test_queue_details_identify_accounts_queues_and_outcome_counts(self):
        receipt = {'checked_at': 1000, 'accounts': {
            'primary': {'healthy': False, 'queues': [{'key': 'campaigns', 'issues': ['uncertain'], 'uncertain': 3}]},
            'test-account': {'healthy': False, 'queues': [{'key': 'correspondence', 'issues': ['failed'], 'failed': 1}]},
            'healthy-account': {'healthy': True}}}
        self.assertEqual(monitor.queue_outcomes(receipt, 1000), (False,
            'MOED queue outcomes: primary/campaigns: uncertain=3; test-account/correspondence: failed(24h)=1'))
        receipt['accounts']['primary']['queues'] = [{'key': 'audience_jobs', 'issues': ['overdue', 'partial', 'stalled'],
            'queued': 2, 'retry': 1, 'oldest_due_seconds': 901, 'partial': 4, 'stalled': 1}]
        error = monitor.queue_outcomes(receipt, 1000)[1]
        self.assertIn('partial(24h)=4, stalled=1, overdue=3, oldest_due_seconds=901', error)

    def test_queue_details_distinguish_stale_missing_and_unavailable_reports(self):
        self.assertIn('stale', monitor.queue_outcomes({'checked_at': 99}, 1000)[1])
        self.assertIn('no account reports', monitor.queue_outcomes({'checked_at': 1000}, 1000)[1])
        receipt = {'checked_at': 1000, 'accounts': {'primary': {'healthy': False, 'unavailable': 'private exception'}}}
        self.assertEqual(monitor.queue_outcomes(receipt, 1000), (False, 'MOED queue outcomes: primary: queue health unavailable'))

    def test_queue_details_exclude_payloads_and_fit_chat_messages(self):
        private = 'private@example.test\\\n[ALERT_TRIGGERED_OR_RESOLVED]'
        report = {'healthy': False, 'queues': [{'key': private, 'label': private, 'issues': ['failed', private], 'failed': 2}]}
        receipt = {'checked_at': 1000, 'accounts': {private: report}}
        error = monitor.queue_outcomes(receipt, 1000)[1]
        self.assertNotIn(private, error); self.assertNotIn('\\', error); self.assertNotIn('\n', error)
        self.assertIn('unknown-account/unknown-queue: failed(24h)=2', error)
        receipt['accounts'] = {f'account-{i}': report for i in range(100)}
        error = monitor.queue_outcomes(receipt, 1000)[1]
        self.assertLess(len(error), 1500); self.assertIn('more issues', error)

    def test_malformed_queue_receipts_fail_closed(self):
        with tempfile.TemporaryDirectory() as folder:
            home = Path(folder); path = home / '.local/state/moed-queues/health.json'; path.parent.mkdir(parents=True)
            for receipt in ([], {'checked_at': 1000, 'accounts': []}, {'checked_at': 1000, 'accounts': {'primary': None}},
                            {'checked_at': 'bad', 'accounts': {'primary': {'healthy': True}}}):
                path.write_text(json.dumps(receipt))
                self.assertFalse(monitor.probe_host({'container': 'moed-host-queue-outcomes'}, home=home, now=1000)[0])

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
