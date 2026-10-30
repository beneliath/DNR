import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
from deployment_guard import DeploymentGuard, guard_labels


class GuardTests(unittest.TestCase):
    config = dict(origin='https://moed.example.test', edge_network='moed_edge', entrypoint='https',
                  certresolver='cloudflare', primary_control_edge_ip='172.29.255.4')
    identifier = 'a' * 32

    def test_every_content_path_uses_one_host_route_with_separate_operator_probes(self):
        labels = guard_labels(self.config, self.identifier, 'b' * 64)
        router = 'traefik.http.routers.moed-maintenance-' + self.identifier
        self.assertEqual(labels[router + '.priority'], '10000')
        rule = labels[router + '.rule']
        self.assertTrue(rule.startswith('Host(`moed.example.test`) && !Header('))
        self.assertIn('ClientIP(`172.29.255.4`) && Method(`POST`)', rule)
        self.assertIn('/account_service[.]php$', rule)
        self.assertNotIn('X-Forwarded-For', rule)

    def test_guard_has_no_app_network_secrets_socket_or_host_port(self):
        with tempfile.TemporaryDirectory() as directory:
            guard = DeploymentGuard(directory, self.config, 'qualified-image', self.identifier, ['primary-account'])
            with patch('deployment_guard.subprocess.run') as execute, patch.object(guard, 'wait_for_route') as wait:
                guard.start()
            args = execute.call_args.args[0]
            self.assertEqual(args[-4:], ['qualified-image', 'apache2-foreground', '-D', 'DNR_MAINTENANCE_GUARD'])
            self.assertEqual(args.count('--network'), 1)
            self.assertEqual(args.count('--mount'), 1)
            self.assertIn('dst=/run/dnr/deployment,readonly', args[args.index('--mount') + 1])
            self.assertNotIn('-p', args)
            self.assertNotIn('--ip', args, 'Use the protected dynamic pool, never another static reservation')
            self.assertNotIn('docker.sock', ' '.join(args))
            wait.assert_called_once_with(protected=True)

    def test_handoff_withdraws_healthy_route_without_stopping_the_responder(self):
        with tempfile.TemporaryDirectory() as directory:
            guard = DeploymentGuard(directory, self.config, 'qualified-image', self.identifier, [])
            guard.directory.mkdir(parents=True)
            with patch('deployment_guard.subprocess.run') as execute, patch.object(guard, 'wait_for_route') as wait:
                guard.withdraw()
                self.assertTrue(guard.marker.exists())
                wait.assert_called_once_with(protected=False)
                execute.assert_not_called()
                guard.protect()
                self.assertFalse(guard.marker.exists())
                self.assertEqual(wait.call_args.kwargs, {'protected': True})
                execute.assert_not_called()

    def test_route_verification_checks_every_account_and_rejects_wrong_notice(self):
        with tempfile.TemporaryDirectory() as directory:
            guard = DeploymentGuard(directory, self.config, 'qualified-image', self.identifier, ['primary-account', 'member-account'])
            urls = []
            class Response:
                headers = {'X-DNR-Maintenance-Guard': guard.identifier}
                def __enter__(self): return self
                def __exit__(self, *_): pass
                def read(self): return json.dumps({'server_now': 123, 'notice': {'id': guard.identifier}}).encode()
            def response(request, **_):
                urls.append(request.full_url.split('?')[0]); return Response()
            with patch('deployment_guard.urllib.request.urlopen', side_effect=response), patch('deployment_guard.time.sleep'):
                guard.wait_for_route(protected=True)
            self.assertEqual(urls, [origin + '/deployment_status.php' for origin in guard.origins] * 3)


if __name__ == '__main__': unittest.main()
