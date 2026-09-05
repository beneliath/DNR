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

    # More clients than the three app workers, with a deadline shorter than the
    # old five-second keep-alive timeout. Keep clients open until the batch ends.
    count = 8
    deadline = 3.0
    connection_type = (
        http.client.HTTPSConnection if base.scheme == "https" else http.client.HTTPConnection
    )
    connections = [
        connection_type(base.hostname, base.port, timeout=deadline) for _ in range(count)
    ]
    start = threading.Barrier(count)
    path = base.path.rstrip("/") + "/health.php"

    def request(connection):
        start.wait(timeout=deadline)
        began = time.monotonic()
        connection.request("GET", path, headers={"Connection": "keep-alive"})
        response = connection.getresponse()
        payload = response.read()
        elapsed = time.monotonic() - began
        if response.status != 200 or json.loads(payload).get("status") != "ok":
            raise RuntimeError("The app health endpoint did not return a healthy response.")
        if elapsed >= deadline:
            raise RuntimeError(f"A request waited {elapsed:.3f}s for an available worker.")
        return elapsed

    try:
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
