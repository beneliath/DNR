#!/usr/bin/env python3
"""Host-owned deployment notice and minimum save window; no database writes."""
import argparse
from contextlib import contextmanager
import fcntl
import json
import os
from pathlib import Path
import re
import sys
import time

SAVE_WINDOW_SECONDS = 300
NOTICE_LIFETIME_SECONDS = 6 * 60 * 60


class DeploymentNotice:
    def __init__(self, project, clock=None, sleep=None):
        self.directory = Path(project) / 'var/deployment'
        self.path = self.directory / 'notice.json'
        self.clock = clock or time.time
        self.sleep = sleep or time.sleep

    @contextmanager
    def locked(self):
        self.directory.mkdir(parents=True, exist_ok=True)
        # Only this directory and the public notice are readable by the web UID.
        self.directory.chmod(0o755)
        with (self.directory / 'notice.lock').open('a') as lock:
            os.chmod(lock.name, 0o600)
            fcntl.flock(lock, fcntl.LOCK_EX)
            yield

    def read(self):
        if not self.path.exists():
            return None
        return json.loads(self.path.read_text())

    def write(self, notice):
        temporary = self.path.with_suffix('.tmp')
        temporary.write_text(json.dumps(notice) + '\n')
        temporary.chmod(0o644)
        temporary.replace(self.path)

    def current(self, identifier):
        notice = self.read()
        if not notice or notice['id'] != identifier:
            raise ValueError('Deployment notice is missing or belongs to another workflow')
        return notice

    def start(self, identifier):
        if not re.fullmatch(r'[a-f0-9]{32}', identifier):
            raise ValueError('Notice ID must be a 32-character lowercase hexadecimal UUID')
        with self.locked():
            now = self.clock()
            notice = self.read()
            if notice and notice['id'] == identifier:
                if notice['phase'] != 'pending' or notice['expires_at'] <= now:
                    raise ValueError('This notice has ended or expired; start a new workflow with a new ID')
                return notice  # Retry preserves the original five-minute deadline.
            if notice and (notice['phase'] in ('deploying', 'failed') or
                           (notice['phase'] == 'pending' and notice['expires_at'] > now)):
                raise ValueError('Another deployment is active; cancel it or resolve its recovery record first')
            notice = dict(id=identifier, phase='pending', started_at=now,
                          not_before=now + SAVE_WINDOW_SECONDS,
                          expires_at=now + NOTICE_LIFETIME_SECONDS, updated_at=now)
            self.write(notice)
            return notice

    def cancel(self, identifier):
        with self.locked():
            notice = self.current(identifier)
            if notice['phase'] in ('deploying', 'failed'):
                raise ValueError('Deployment has entered maintenance; use the recovery workflow')
            if notice['phase'] == 'pending':
                notice.update(phase='cancelled', updated_at=self.clock())
                self.write(notice)
            return notice

    def wait_and_claim(self, identifier, expected):
        """Atomically enter maintenance only after the host deadline, never earlier."""
        if not re.fullmatch(r'[a-f0-9]{40}', expected):
            raise ValueError('Expected a full deployment commit SHA')
        while True:
            with self.locked():
                notice = self.current(identifier)
                now = self.clock()
                if notice['phase'] != 'pending' or notice['expires_at'] <= now:
                    raise ValueError('Deployment notice was cancelled or expired; no writers were stopped')
                # Validate the minimum even if a persisted notice was edited incorrectly.
                if notice['not_before'] < notice['started_at'] + SAVE_WINDOW_SECONDS:
                    raise ValueError('Invalid deployment save window')
                remaining = max(0, notice['not_before'] - now)
                if not remaining:
                    notice.update(phase='deploying', commit=expected, updated_at=now)
                    self.write(notice)
                    return notice
            print(f'Waiting for user save window: {remaining:.0f}s remaining', flush=True)
            self.sleep(min(remaining, 5))

    def finish(self, identifier, phase):
        if phase not in ('complete', 'failed'):
            raise ValueError('Invalid deployment outcome')
        with self.locked():
            notice = self.current(identifier)
            if notice['phase'] != 'deploying':
                raise ValueError('Notice is not in deployment')
            notice.update(phase=phase, updated_at=self.clock())
            self.write(notice)

    def resolve(self, identifier, project):
        """Operator-only cleanup after the documented recovery has been verified."""
        deployment_lock = Path(project) / '.git/dnr-deploy/deploy.lock'
        deployment_lock.parent.mkdir(parents=True, exist_ok=True)
        with deployment_lock.open('a') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.locked():
                notice = self.current(identifier)
                if notice['phase'] not in ('failed', 'deploying'):
                    raise ValueError('Only interrupted/failed deployments need recovery resolution')
                notice.update(phase='cancelled', updated_at=self.clock())
                self.write(notice)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('project', type=Path)
    parser.add_argument('action', choices=['start', 'cancel', 'status', 'resolve'])
    parser.add_argument('identifier', nargs='?')
    args = parser.parse_args()
    notice = DeploymentNotice(args.project)
    if args.action == 'status':
        print(json.dumps(notice.read()))
    else:
        if not args.identifier:
            parser.error('start, cancel, and resolve require a notice ID')
        if args.action == 'resolve':
            notice.resolve(args.identifier, args.project)
        else:
            getattr(notice, args.action)(args.identifier)
        print(args.identifier)


if __name__ == '__main__':
    try:
        main()
    except (ValueError, KeyError, OSError) as error:
        sys.exit(str(error))
