#!/bin/sh
set -eu

bind_address=${DNR_DEV_BIND_ADDRESS:-127.0.0.1}
allow_remote_http=${DNR_DEV_ALLOW_REMOTE_HTTP:-0}

case "$allow_remote_http" in
    0|1) ;;
    *)
        echo "DNR_DEV_ALLOW_REMOTE_HTTP must be 0 or 1." >&2
        exit 1
        ;;
esac

case "$bind_address" in
    127.*|::1|'[::1]') ;;
    *)
        if [ "$allow_remote_http" != "1" ]; then
            echo "Refusing to publish the plaintext development ingress on non-loopback address '$bind_address'." >&2
            echo "Set DNR_DEV_ALLOW_REMOTE_HTTP=1 only for an explicitly trusted network." >&2
            exit 1
        fi
        ;;
esac

# Allows CI to exercise the policy without starting Apache.
if [ "${1:-}" = "--check" ]; then
    exit 0
fi

if [ "$#" -eq 0 ]; then
    set -- apache2-foreground
fi

exec docker-php-entrypoint "$@"
