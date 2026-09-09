"""Verify simultaneous anonymous downloads against an explicitly disposable origin."""
import argparse
import concurrent.futures
import hashlib
import http.client
import json
import os
import threading
import time
from urllib.parse import urlsplit


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--base', default='http://127.0.0.1:8216')
    parser.add_argument('--path', required=True, help='Synthetic fixture /surls/CODE path')
    parser.add_argument('--sha256', required=True)
    parser.add_argument('--bytes', type=int, required=True)
    parser.add_argument('--clients', type=int, default=20,
                        help='Start with 20; request larger bursts explicitly after checking runtime memory.')
    args = parser.parse_args()
    base = urlsplit(args.base)
    if os.environ.get('DNR_INTEGRATION_TARGET') != 'disposable' or base.scheme != 'http' or base.hostname not in {'localhost', '127.0.0.1'}:
        raise SystemExit('Refusing a load test outside an explicitly disposable loopback origin.')
    if not 1 <= args.clients <= 500 or not args.path.startswith('/surls/') or '?' in args.path:
        raise SystemExit('Use 1–500 clients and a synthetic short-link path.')
    barrier = threading.Barrier(args.clients)

    def download(_):
        connection = http.client.HTTPConnection(base.hostname, base.port, timeout=60)
        try:
            barrier.wait(timeout=30)
            began = time.monotonic()
            connection.request('GET', args.path, headers={'User-Agent': 'Mozilla/5.0 Chrome/128.0.0.0 Safari/537.36'})
            response = connection.getresponse()
            if response.status == 302:
                location = response.getheader('Location', '')
                response.read()
                if not location.startswith(args.path + '/speaker-notes.pdf'):
                    raise RuntimeError('Unexpected redirect.')
                connection.close()
                connection = http.client.HTTPConnection(base.hostname, base.port, timeout=60)
                connection.request('GET', location)
                response = connection.getresponse()
            first_byte = time.monotonic() - began
            if response.status != 200:
                raise RuntimeError(f'HTTP {response.status}')
            digest = hashlib.sha256()
            size = 0
            while chunk := response.read(65536):
                digest.update(chunk)
                size += len(chunk)
            if digest.hexdigest() != args.sha256 or size != args.bytes:
                raise RuntimeError('Truncated or corrupted PDF.')
            return {'seconds': time.monotonic() - began, 'headers_seconds': first_byte}
        except Exception as error:
            return {'error': str(error)}
        finally:
            connection.close()

    with concurrent.futures.ThreadPoolExecutor(max_workers=args.clients) as pool:
        results = list(pool.map(download, range(args.clients)))
    errors = [r['error'] for r in results if 'error' in r]
    durations = sorted(r['seconds'] for r in results if 'seconds' in r)
    report = {'clients': args.clients, 'pdf_bytes': args.bytes, 'successful': len(durations), 'failed': len(errors),
              'p50_seconds': durations[len(durations)//2] if durations else None,
              'p95_seconds': durations[min(len(durations)-1, int(len(durations)*.95))] if durations else None,
              'maximum_seconds': max(durations) if durations else None, 'errors': errors[:10],
              'scope': 'Disposable origin only; no CDN or venue-network latency is modeled.'}
    print(json.dumps(report, indent=2))
    raise SystemExit(1 if errors else 0)


if __name__ == '__main__':
    main()
