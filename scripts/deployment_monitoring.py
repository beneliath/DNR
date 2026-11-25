"""Read host-owned deployment state for monitoring without changing deployment state."""
import fcntl
import json
import math
from pathlib import Path
import re
import time

MAINTENANCE_SECONDS = 15 * 60
RECOVERY_SECONDS = 120


def read_json(path, default=None):
    try:
        return json.loads(Path(path).read_text())
    except FileNotFoundError:
        return default


def deployment_status(project, now=None):
    """A deployment crash, invalid notice, or expired deadline requires attention."""
    now = time.time() if now is None else now
    try:
        notice = read_json(Path(project) / 'var/deployment/notice.json')
        if not notice or notice.get('phase') in ('preparing', 'pending', 'cancelled'):
            return {'mode': 'normal'}
        phase = notice['phase']
        identifier, commit = notice['id'], notice['commit']
        if phase not in ('deploying', 'complete', 'failed') or not re.fullmatch(r'[a-f0-9]{32}', identifier) \
                or not re.fullmatch(r'[a-f0-9]{40}', commit):
            raise ValueError('Invalid deployment identity')
        updated = float(notice['updated_at'])
        if not math.isfinite(updated) or updated > now + 5:
            raise ValueError('Invalid deployment timestamp')
        record = read_json(Path(project) / '.git/dnr-deploy' / (commit + '.json'), {})
        claimed = record.get('notice') or {}
        if claimed and (claimed.get('id') != identifier or claimed.get('commit') != commit):
            raise ValueError('Deployment record belongs to another attempt')
        started = updated if phase == 'deploying' else claimed.get('updated_at')
        if started is None:
            # Old, completed notices need no suppression and no migration.
            if phase == 'complete':
                return {'mode': 'normal'}
            raise ValueError('Deployment start is unavailable')
        started = float(started)
        if not math.isfinite(started) or started > updated or started > now + 5:
            raise ValueError('Invalid deployment start')
        status = dict(id=identifier, commit=commit, started_at=started,
                      deadline=started + MAINTENANCE_SECONDS, phase=phase,
                      stage=record.get('phase', 'unknown'), mode='normal')
        if phase == 'complete':
            if now < min(updated + RECOVERY_SECONDS, status['deadline']):
                status['mode'] = 'recovering'
            return status
        if phase == 'failed':
            return dict(status, mode='incident', reason='deployment failed')
        if now >= status['deadline']:
            return dict(status, mode='incident', reason='deployment exceeded the 15-minute maintenance limit')
        with (Path(project) / '.git/dnr-deploy/deploy.lock').open('r') as lock:
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                return dict(status, mode='maintenance')
        return dict(status, mode='incident', reason='deployment stopped before recovery was verified')
    except (OSError, ValueError, TypeError, KeyError, AttributeError):
        return {'mode': 'invalid', 'reason': 'deployment monitoring state could not be verified'}


def expected_queue_report(report, previous, deployment):
    """Paused workers may delay jobs, while delivery failures remain actionable."""
    if deployment['mode'] not in ('maintenance', 'recovering', 'incident') or previous.get('healthy') is not True:
        return False
    if report.get('unavailable'):
        return True
    issues = {issue for queue in report.get('queues', []) for issue in queue.get('issues', [])}
    return bool(issues) and issues <= {'stalled', 'overdue'}
