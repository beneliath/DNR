import copy
import fcntl
import json
from pathlib import Path
import sys
import tempfile
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from deployment_monitoring import deployment_status, expected_queue_report
import gatus_deployment as gatus


class DeploymentMonitoringTests(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        (self.root / 'var/deployment').mkdir(parents=True)
        (self.root / '.git/dnr-deploy').mkdir(parents=True)
        self.notice = dict(id='a' * 32, commit='b' * 40, phase='deploying', updated_at=1000)
        self.save_notice()
        self.lock = (self.root / '.git/dnr-deploy/deploy.lock').open('w')
        self.addCleanup(self.lock.close)
        fcntl.flock(self.lock, fcntl.LOCK_EX)

    def save_notice(self):
        (self.root / 'var/deployment/notice.json').write_text(json.dumps(self.notice))

    def test_only_active_maintenance_is_quiet_and_deadline_cannot_slide(self):
        self.assertEqual(deployment_status(self.root, 1001)['mode'], 'maintenance')
        self.assertEqual(deployment_status(self.root, 1899)['mode'], 'maintenance')
        self.assertEqual(deployment_status(self.root, 1900)['mode'], 'incident')
        self.notice.update(phase='preparing')
        self.save_notice()
        self.assertEqual(deployment_status(self.root, 1010)['mode'], 'normal')
        self.notice.update(phase='pending')
        self.save_notice()
        self.assertEqual(deployment_status(self.root, 1010)['mode'], 'normal')

    def test_crash_failed_phase_invalid_notice_and_future_clock_require_attention(self):
        fcntl.flock(self.lock, fcntl.LOCK_UN)
        self.assertEqual(deployment_status(self.root, 1010)['mode'], 'incident')
        record = dict(notice=self.notice.copy(), phase='backing-up')
        (self.root / '.git/dnr-deploy' / ('b' * 40 + '.json')).write_text(json.dumps(record))
        self.notice.update(phase='failed', updated_at=1010)
        self.save_notice()
        self.assertEqual(deployment_status(self.root, 1011)['reason'], 'deployment failed')
        self.assertEqual(deployment_status(self.root, 900)['mode'], 'invalid')
        (self.root / 'var/deployment/notice.json').write_text('{')
        self.assertEqual(deployment_status(self.root, 1010)['mode'], 'invalid')

    def test_complete_has_bounded_recovery_and_old_complete_is_normal(self):
        record = dict(notice=self.notice.copy(), phase='verified')
        (self.root / '.git/dnr-deploy' / ('b' * 40 + '.json')).write_text(json.dumps(record))
        self.notice.update(phase='complete', updated_at=1500)
        self.save_notice()
        self.assertEqual(deployment_status(self.root, 1501)['mode'], 'recovering')
        self.assertEqual(deployment_status(self.root, 1620)['mode'], 'normal')
        (self.root / '.git/dnr-deploy' / ('b' * 40 + '.json')).unlink()
        self.assertEqual(deployment_status(self.root, 1700)['mode'], 'normal')

    def test_queue_delivery_errors_and_preexisting_backlogs_always_require_attention(self):
        deployment = deployment_status(self.root, 1010)
        self.assertTrue(expected_queue_report({'unavailable': 'paused'}, {'healthy': True}, deployment))
        self.assertFalse(expected_queue_report({'unavailable': 'paused'}, {'healthy': False}, deployment))
        for issue in ('uncertain', 'failed', 'partial'):
            report = {'queues': [{'issues': ['overdue', issue]}]}
            self.assertFalse(expected_queue_report(report, {'healthy': True}, deployment))
        self.assertTrue(expected_queue_report({'queues': [{'issues': ['overdue']}]}, {'healthy': True}, deployment))
        self.assertFalse(expected_queue_report({'unavailable': 'paused'}, {'healthy': True}, {'mode': 'normal'}))


class GatusDeploymentTests(unittest.TestCase):
    def setUp(self):
        self.alerts = [{'type': 'email', 'failure-threshold': 3, 'send-on-resolved': True}]
        self.document = {
            'endpoints': [{'name': 'moed', 'group': 'web', 'conditions': ['[STATUS] == 200', '[CERTIFICATE_EXPIRATION] > 96h'],
                           'interval': '5m', 'alerts': copy.deepcopy(self.alerts)}],
            'external-endpoints': [dict(name=name, group='3_moed production', alerts=copy.deepcopy(self.alerts))
                                   for name in ('moed-web-1', 'moed-mail-dispatch-1', 'moed-host-nas-recovery',
                                                'moed-host-account-worker', 'moed-host-queue-outcomes', 'moed-host-deployment')],
            'alerting': {'email': {'default-alert': {'failure-threshhold': 2, 'success-threshhold': 2}}},
        }
        gatus.configure(self.document)
        self.observations = {gatus.key(endpoint): dict(healthy=True, ready=True, fresh=True, timestamp=999)
                             for endpoint in self.document['endpoints'] + self.document['external-endpoints']}
        _, self.baseline = gatus.plan(self.document, {}, {'mode': 'normal'}, self.observations, {}, 999)
        self.deployment = dict(mode='maintenance', id='a', started_at=1000, deadline=1900, stage='backing-up')

    def endpoint(self, document, name):
        return next(e for e in document['endpoints'] + document['external-endpoints'] if e['name'] == name)

    def test_certificate_and_unrelated_checks_keep_alerting_during_maintenance(self):
        planned, state = gatus.plan(self.document, self.baseline, self.deployment, self.observations, {}, 1010)
        for name in ('moed', 'moed-web-1', 'moed-mail-dispatch-1', 'moed-host-account-worker'):
            self.assertIn('maintenance-windows', self.endpoint(planned, name))
        for name in ('moed-tls', 'moed-host-nas-recovery', 'moed-host-deployment', 'moed-host-queue-outcomes'):
            self.assertNotIn('maintenance-windows', self.endpoint(planned, name))
        self.assertTrue(state['healthy'])
        self.assertEqual(self.endpoint(planned, 'moed-tls')['conditions'], ['[CERTIFICATE_EXPIRATION] > 96h'])
        self.assertEqual(self.endpoint(planned, 'moed')['conditions'], ['[STATUS] == 200'])
        self.assertEqual(planned['alerting']['email']['default-alert'], {'failure-threshold': 2, 'success-threshold': 2})

    def test_preexisting_incidents_and_missing_or_old_baselines_are_never_muted(self):
        identity = gatus.key(self.endpoint(self.document, 'moed-mail-dispatch-1'))
        self.baseline['baseline']['healthy'].remove(identity)
        planned, _ = gatus.plan(self.document, self.baseline, self.deployment, self.observations, {}, 1010)
        self.assertNotIn('maintenance-windows', self.endpoint(planned, 'moed-mail-dispatch-1'))
        self.baseline['baseline']['checked_at'] = 500
        planned, _ = gatus.plan(self.document, self.baseline, self.deployment, self.observations, {}, 1010)
        self.assertFalse(any(e.get('maintenance-windows') for e in planned['external-endpoints']))

    def test_public_alert_threshold_allows_collector_discovery_and_native_reload(self):
        endpoint = self.endpoint(self.document, 'moed')
        endpoint['alerts'] = [{'type': 'email'}, {'type': 'email', 'failure-threshold': 1},
                              {'type': 'email', 'failure-threshold': 5}]
        gatus.configure(self.document)
        self.assertEqual([alert['failure-threshold'] for alert in endpoint['alerts']], [3, 3, 5])
        self.assertEqual(endpoint['interval'], '1m')

    def test_failed_deployment_groups_children_and_retains_the_root_alert(self):
        planned, active = gatus.plan(self.document, self.baseline, self.deployment, self.observations, {}, 1010)
        identity = gatus.key(self.endpoint(planned, 'moed-web-1'))
        self.observations[identity]['ready'] = False
        failed = dict(self.deployment, mode='incident', reason='deployment failed')
        planned, incident = gatus.plan(planned, active, failed, self.observations, {}, 1020)
        self.assertFalse(incident['healthy'])
        self.assertIn('moed-web-1', incident['error'])
        self.assertFalse(self.endpoint(planned, 'moed-web-1')['alerts'][0]['enabled'])
        self.assertNotIn('enabled', self.endpoint(planned, 'moed-host-deployment')['alerts'][0])
        self.assertNotIn('enabled', self.endpoint(planned, 'moed-host-nas-recovery')['alerts'][0])
        repeated, state = gatus.plan(planned, incident, failed, self.observations, {}, 1030)
        self.assertEqual(repeated, planned)
        recovering = dict(self.deployment, mode='normal')
        restored, state = gatus.plan(planned, incident, recovering, self.observations, {}, 2000)
        self.assertFalse(state['healthy'], 'Incomplete recovery keeps the incident open')
        self.observations[identity]['ready'] = True
        restored, state = gatus.plan(restored, state, recovering, self.observations, {}, 2010)
        self.assertTrue(state['healthy'])
        self.assertNotIn('enabled', self.endpoint(restored, 'moed-web-1')['alerts'][0])

    def test_success_removes_windows_and_retains_existing_user_windows(self):
        original = {'start': '23:00', 'duration': '5m'}
        self.document['external-endpoints'][0]['maintenance-windows'] = [original]
        planned, active = gatus.plan(self.document, self.baseline, self.deployment, self.observations, {}, 1010)
        restored, _ = gatus.plan(planned, active, dict(self.deployment, mode='normal'), self.observations, {}, 1500)
        self.assertEqual(restored, self.document)

    def test_queue_maintenance_requires_all_errors_to_be_expected(self):
        queue = {'accounts': {'primary': {'healthy': False, 'deployment_id': 'a'}}}
        planned, active = gatus.plan(self.document, self.baseline, self.deployment, self.observations, queue, 1010)
        self.assertIn('maintenance-windows', self.endpoint(planned, 'moed-host-queue-outcomes'))
        queue['accounts']['member'] = {'healthy': False, 'queues': [{'issues': ['uncertain']}]}
        planned, _ = gatus.plan(planned, active, self.deployment, self.observations, queue, 1020)
        self.assertNotIn('maintenance-windows', self.endpoint(planned, 'moed-host-queue-outcomes'))

    def test_crash_journal_restores_disabled_alerts_even_if_final_write_was_interrupted(self):
        failed = dict(self.deployment, mode='incident', reason='deployment failed')
        planned, incident = gatus.plan(self.document, self.baseline, failed, self.observations, {}, 1010)
        pending = {'pending_restore': [dict(windows=incident['windows'], grouped=incident['grouped'])]}
        restored, _ = gatus.plan(planned, pending, {'mode': 'invalid', 'reason': 'bad state'}, self.observations, {}, 2000)
        self.assertEqual(restored, self.document)


if __name__ == '__main__':
    unittest.main()
