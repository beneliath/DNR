"""Hold every download worker with slow clients and check the independent web pool."""
import http.client
import json
import socket
import sys
import time
from urllib.parse import urlsplit

def run(base, path):
    target = urlsplit(base)
    if target.hostname not in ('localhost', '127.0.0.1') or not path.startswith('/surls/'):
        raise ValueError('Disposable loopback download fixture required')
    transfers = []
    try:
        for _ in range(20):
            c = http.client.HTTPConnection(target.hostname, target.port, timeout=10)
            c.connect(); c.sock.setsockopt(socket.SOL_SOCKET, socket.SO_RCVBUF, 4096)
            c.request('GET', path)
            response = c.getresponse()
            if response.status != 200 or int(response.getheader('Content-Length', '0')) < 50000000:
                raise AssertionError('Large download fixture unavailable')
            transfers.append((c, response))
        times = []
        for route in ('/ready.php', '/login.php', '/health.php'):
            c = http.client.HTTPConnection(target.hostname, target.port, timeout=3)
            start = time.monotonic(); c.request('GET', route); response = c.getresponse(); response.read()
            times.append(time.monotonic() - start)
            if response.status != 200: raise AssertionError(f'{route}: HTTP {response.status}')
            c.close()
        print(json.dumps({'slow_downloads': len(transfers), 'web_max_response_seconds': max(times)}))
    finally:
        for c, response in transfers: response.close(); c.close()

if __name__ == '__main__':
    # The owning integration harness verifies labels before creating the fixture or invoking this test.
    run(sys.argv[1], sys.argv[2])
