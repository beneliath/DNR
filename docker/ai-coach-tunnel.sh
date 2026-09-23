#!/bin/sh
set -eu
umask 077
mkdir -p /tmp/coach-ssh
cp /run/secrets/ai_coach_ssh_key /tmp/coach-ssh/key
cp /run/secrets/ai_coach_known_hosts /tmp/coach-ssh/known_hosts
chmod 600 /tmp/coach-ssh/key /tmp/coach-ssh/known_hosts
chown -R 10001:10001 /tmp/coach-ssh
# Compose bind-mounted secrets retain host ownership on Linux. Copy the 0600
# key as root, then run the connection as an unprivileged identity.
exec setpriv --reuid=10001 --regid=10001 --clear-groups ssh -NT -F /dev/null \
    -i /tmp/coach-ssh/key \
    -o IdentitiesOnly=yes -o BatchMode=yes -o StrictHostKeyChecking=yes \
    -o UserKnownHostsFile=/tmp/coach-ssh/known_hosts \
    -o GlobalKnownHostsFile=/dev/null -o ExitOnForwardFailure=yes \
    -o ConnectTimeout=8 -o ServerAliveInterval=5 -o ServerAliveCountMax=2 \
    -L 0.0.0.0:11437:127.0.0.1:11437 \
    -l "${DNR_AI_COACH_SSH_USER:?Set the SSH user}" "${DNR_AI_COACH_SSH_HOST:?Set the SSH host}"
