#!/usr/bin/env python3
"""Add external backup alerts to the existing s1 backup schedule; preserve other jobs."""
from pathlib import Path
import datetime
import os
import subprocess
import sys
from recovery_monitor import ALERT_RECIPIENT, load_smtp_configuration


def main():
    os.umask(0o077)
    monitor=Path(__file__).resolve().with_name('recovery_monitor.py')
    if str(monitor)!='/home/dgilmore/.local/lib/moed-recovery/v1/recovery_monitor.py':
        raise SystemExit('Install the reviewed helper bundle at the documented s1 path first.')
    state=Path.home()/'.local/state/moed-backup'
    config=load_smtp_configuration('moed',state/'smtp-connection.json')
    if config['encryption'] not in ('tls','starttls') or not config['sender']:
        raise SystemExit('Verified TLS SMTP and a sender address are required.')
    subprocess.run([sys.executable,str(monitor),'--check-only'],check=True)
    result=subprocess.run(['crontab','-l'],capture_output=True,text=True)
    if result.returncode not in (0,1): raise SystemExit('Unable to read the existing schedule')
    original=result.stdout
    begin='# BEGIN MOED EXTERNAL RECOVERY MONITOR'; end='# END MOED EXTERNAL RECOVERY MONITOR'
    if begin in original or end in original: raise SystemExit('A monitor schedule already exists; inspect it before updating.')
    block=begin+'\n*/5 * * * * /usr/bin/python3 '+str(monitor)+' 2>&1 | /usr/bin/logger -t moed-recovery-monitor\n'+end+'\n'
    backup=state/('crontab-before-monitor-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'.txt')
    backup.write_text(original)
    fresh=subprocess.run(['crontab','-l'],capture_output=True,text=True)
    if fresh.stdout!=original: raise SystemExit('Schedule changed during preparation; no update made.')
    subprocess.run(['crontab','-'],input=original.rstrip()+'\n\n'+block,text=True,check=True)
    if block not in subprocess.check_output(['crontab','-l'],text=True): raise SystemExit('Monitor installation verification failed')
    print('External recovery monitor installed; failure and recovery alerts go to '+ALERT_RECIPIENT+'.')


if __name__=='__main__': main()
