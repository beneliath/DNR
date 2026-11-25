"""Exercise the installed Gatus binary against private, local notification capture."""
import copy
import http.server
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import threading
import time
import unittest
import urllib.request

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'scripts'))
import gatus_deployment


@unittest.skipUnless(os.environ.get('GATUS_TEST_BINARY'), 'Set GATUS_TEST_BINARY to the installed Gatus executable')
class GatusDeploymentIntegrationTests(unittest.TestCase):
    def test_notifications_across_maintenance_failure_recovery_and_unrelated_outage(self):
        import yaml
        received = []

        class Capture(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                self.send_response(200)
                self.end_headers()

            def do_POST(self):
                received.append(json.loads(self.rfile.read(int(self.headers['Content-Length']))))
                self.send_response(200)
                self.end_headers()

            def log_message(self, *args):
                pass

        capture = http.server.ThreadingHTTPServer(('127.0.0.1', 0), Capture)
        threading.Thread(target=capture.serve_forever, daemon=True).start()
        self.addCleanup(capture.server_close)
        self.addCleanup(capture.shutdown)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1', 0))
            port = listener.getsockname()[1]
        base = 'http://127.0.0.1:' + str(port)
        alerts = [{'type': 'custom', 'failure-threshold': 1, 'success-threshold': 1, 'send-on-resolved': True}]
        names = ['moed-web-1', 'moed-host-deployment', 'moed-host-nas-recovery', 'moed-tls', 'unrelated']
        document = {
            'web': {'address': '127.0.0.1', 'port': port},
            'storage': {'type': 'sqlite', 'path': 'test.db'},
            'endpoints': [{'name': 'fixture-health', 'url': 'http://127.0.0.1:' + str(capture.server_port),
                           'interval': '1m', 'conditions': ['[STATUS] == 200']}],
            'alerting': {'custom': {
                'url': 'http://127.0.0.1:' + str(capture.server_port), 'method': 'POST',
                'headers': {'Content-Type': 'application/json'},
                'body': '{"name":"[ENDPOINT_NAME]","state":"[ALERT_TRIGGERED_OR_RESOLVED]"}',
            }},
            'external-endpoints': [dict(name=name,
                group='3_moed production' if name.startswith('moed-') and name != 'moed-tls' else 'other',
                token='test-token', alerts=copy.deepcopy(alerts)) for name in names],
        }
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            config = root / 'config.yaml'

            def write_config(value):
                temporary = config.with_suffix('.tmp')
                temporary.write_text(yaml.safe_dump(value, sort_keys=False))
                temporary.replace(config)

            write_config(document)
            with (root / 'gatus.log').open('w') as log:
                process = subprocess.Popen([os.environ['GATUS_TEST_BINARY']], cwd=root,
                    env={**os.environ, 'GATUS_CONFIG_PATH': str(config)}, stdout=log, stderr=log)
                try:
                    for attempt in range(100):
                        if process.poll() is not None:
                            self.fail((root / 'gatus.log').read_text()[-3000:])
                        try:
                            with urllib.request.urlopen(base + '/health', timeout=1):
                                break
                        except OSError:
                            time.sleep(.1)
                    else:
                        self.fail('Isolated Gatus did not become ready')

                    def push(name, healthy):
                        endpoint = next(e for e in document['external-endpoints'] if e['name'] == name)
                        request = urllib.request.Request(base + '/api/v1/endpoints/' + gatus_deployment.key(endpoint)
                            + '/external?success=' + str(healthy).lower(), data=b'', method='POST',
                            headers={'Authorization': 'Bearer test-token'})
                        with urllib.request.urlopen(request, timeout=3) as response:
                            self.assertEqual(response.status, 200)

                    def reload(value):
                        # Gatus compares whole-second mtimes, so a write in the
                        # same second as startup/reload can otherwise be missed.
                        time.sleep(1.1)
                        write_config(value)
                        # This installed revision polls every 30 seconds, then
                        # pauses one second while restarting its HTTP server.
                        time.sleep(35)

                    for name in names:
                        push(name, True)
                        push(name, True)
                    now = time.time()
                    observations = {gatus_deployment.key(e): dict(healthy=True, ready=True, fresh=True, timestamp=now)
                                    for e in document['external-endpoints']}
                    _, baseline = gatus_deployment.plan(document, {}, {'mode': 'normal'}, observations, {}, now - 1)
                    deployment = dict(mode='maintenance', id='test', started_at=now, deadline=now + 900, stage='starting')
                    planned, active = gatus_deployment.plan(document, baseline, deployment, observations, {}, now)
                    reload(planned)
                    push('moed-web-1', False)
                    push('unrelated', False)
                    push('moed-tls', False)
                    push('moed-host-nas-recovery', False)
                    self.assertEqual({event['name'] for event in received}, {'unrelated', 'moed-tls', 'moed-host-nas-recovery'})
                    for name in ('unrelated', 'moed-tls', 'moed-host-nas-recovery'):
                        push(name, True)
                    received.clear()
                    # A normal deployment sends neither component outage nor recovery.
                    push('moed-web-1', True)
                    restored, baseline = gatus_deployment.plan(planned, active, dict(deployment, mode='normal'), observations, {}, time.time())
                    reload(restored)
                    push('moed-web-1', True)
                    self.assertEqual(received, [])
                    # Failed deployment sends one root incident, then one recovery.
                    deployment.update(id='failed', started_at=time.time(), deadline=time.time() + 900)
                    failed = dict(deployment, mode='incident', reason='deployment failed')
                    planned, incident = gatus_deployment.plan(restored, baseline, failed, observations, {}, time.time())
                    reload(planned)
                    for _ in range(3):
                        push('moed-web-1', False)
                        push('moed-host-deployment', False)
                    self.assertEqual([event['name'] for event in received], ['moed-host-deployment'])
                    restored, _ = gatus_deployment.plan(planned, incident, dict(deployment, mode='normal'), observations, {}, time.time())
                    reload(restored)
                    push('moed-web-1', True)
                    push('moed-host-deployment', True)
                    self.assertEqual([event['name'] for event in received], ['moed-host-deployment', 'moed-host-deployment'])
                    received.clear()
                    push('moed-web-1', False)
                    self.assertEqual([event['name'] for event in received], ['moed-web-1'])
                finally:
                    if sys.exc_info()[0] is not None:
                        print((root / 'gatus.log').read_text()[-4000:], file=sys.stderr)
                    process.terminate()
                    try:
                        process.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait(timeout=5)


if __name__ == '__main__':
    unittest.main()
