"""Check that idle proxy connections do not starve the bounded PHP worker pool."""

import concurrent.futures
import http.client
import json
import os
import threading
import time
from urllib.parse import urlsplit


def main():
    base = urlsplit(os.environ.get("DNR_TEST_BASE_URL", "http://127.0.0.1:8080"))
    if base.scheme not in {"http", "https"} or not base.hostname:
        raise SystemExit("DNR_TEST_BASE_URL must be an HTTP(S) URL.")

    # More clients than the twenty app workers, with a deadline shorter than the
    # old five-second keep-alive timeout. Keep clients open until the batch ends.
    count = 40
    deadline = 3.0
    connection_type = (
        http.client.HTTPSConnection if base.scheme == "https" else http.client.HTTPConnection
    )
    connections = [
        connection_type(base.hostname, base.port, timeout=15) for _ in range(count)
    ]
    start = threading.Barrier(count)
    path = base.path.rstrip("/") + "/health.php"

    def request(connection, measured=True):
        if measured:
            start.wait(timeout=deadline)
        began = time.monotonic()
        connection.request("GET", path, headers={"Connection": "keep-alive"})
        response = connection.getresponse()
        payload = response.read()
        elapsed = time.monotonic() - began
        if response.status != 200 or json.loads(payload).get("status") != "ok":
            raise RuntimeError("The app health endpoint did not return a healthy response.")
        if measured and elapsed >= deadline:
            raise RuntimeError(f"A request waited {elapsed:.3f}s for an available worker.")
        return elapsed

    try:
        # Let ingress create its prefork children before measuring backend idle
        # connection starvation. Cold-start burst latency is covered separately.
        with concurrent.futures.ThreadPoolExecutor(max_workers=count) as pool:
            list(pool.map(lambda connection: request(connection, False), connections))
        for connection in connections:
            # Early warmup sockets may reach ingress's idle timeout while later
            # prefork children start. Measure on fresh sockets, keeping all of
            # those connections open until the measured batch completes.
            connection.close()
            connection.timeout = deadline
        with concurrent.futures.ThreadPoolExecutor(max_workers=count) as pool:
            durations = list(pool.map(request, connections))
    except (OSError, ValueError, RuntimeError, http.client.HTTPException) as error:
        raise SystemExit(f"HTTP connection capacity test failed: {error}") from error
    finally:
        for connection in connections:
            connection.close()

    print(
        f"HTTP connection capacity test passed: {count} concurrent requests; "
        f"slowest {max(durations):.3f}s."
    )


if __name__ == "__main__":
    main()
