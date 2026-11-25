#!/usr/bin/env python3
"""Install the reviewed external queue monitor on s1, preserving unrelated cron jobs."""
import datetime
import os
from pathlib import Path
import subprocess
import sys
from recovery_monitor import load_smtp_configuration


def main():
    os.umask(0o077)
    monitor = Path(__file__).resolve().with_name('queue_monitor.py')
    if str(monitor) != '/home/dgilmore/.local/lib/moed-recovery/v1/queue_monitor.py':
        raise SystemExit('Install the reviewed helper bundle at the documented s1 path first.')
    state = Path.home() / '.local/state/moed-queues'
    state.mkdir(parents=True, exist_ok=True, mode=0o700)
    config = load_smtp_configuration('moed', state / 'smtp-connection.json')
    if config['encryption'] not in ('tls', 'starttls') or not config['sender']:
        raise SystemExit('Verified TLS SMTP and a sender address are required.')
    check = subprocess.run([sys.executable, str(monitor), '--check-only'])
    if check.returncode not in (0, 1):
        raise SystemExit('Queue monitor preflight failed.')
    result = subprocess.run(['crontab', '-l'], capture_output=True, text=True)
    if result.returncode not in (0, 1):
        raise SystemExit('Unable to read the existing schedule.')
    original = result.stdout
    begin, end = '# BEGIN MOED EXTERNAL QUEUE MONITOR', '# END MOED EXTERNAL QUEUE MONITOR'
    block = begin + '\n* * * * * /usr/bin/python3 ' + str(monitor) + ' 2>&1 | /usr/bin/logger -t moed-queue-monitor\n' + end + '\n'
    updated = original.rstrip() + '\n\n' + block
    if begin in original or end in original:
        if original.count(begin) != 1 or original.count(end) != 1 or original.index(begin) > original.index(end):
            raise SystemExit('The queue monitor schedule markers are invalid.')
        start, finish = original.index(begin), original.index(end) + len(end)
        updated = original[:start] + block.rstrip() + original[finish:]
    if updated == original:
        print('External queue monitor already runs every minute.')
        return
    backup = state / ('crontab-before-monitor-' + datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ') + '.txt')
    backup.write_text(original)
    fresh = subprocess.run(['crontab', '-l'], capture_output=True, text=True)
    if fresh.returncode not in (0, 1) or fresh.stdout != original:
        raise SystemExit('Schedule changed during preparation; no update made.')
    subprocess.run(['crontab', '-'], input=updated, text=True, check=True)
    if block not in subprocess.check_output(['crontab', '-l'], text=True):
        raise SystemExit('Queue monitor installation verification failed.')
    print('External queue monitor installed; alerts are independent of application queues.')


if __name__ == '__main__':
    main()
