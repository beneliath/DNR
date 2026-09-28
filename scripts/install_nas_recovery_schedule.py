#!/usr/bin/env python3
"""Install a user cron schedule after a complete recovery run passes its age check."""
from pathlib import Path
import os
import subprocess
import sys


def main():
    os.umask(0o077)
    runner=Path(__file__).resolve().with_name('run_nas_recovery.py')
    # Fixed home path also avoids shell/cron metacharacters in generated entries.
    if str(runner)!='/home/dgilmore/.local/lib/moed-recovery/v1/run_nas_recovery.py':
        raise SystemExit('Install the reviewed helper bundle in the documented s1 path first.')
    subprocess.run([sys.executable,str(runner),'--check'],check=True)
    current=subprocess.run(['crontab','-l'],capture_output=True,text=True)
    if current.returncode not in (0,1): raise SystemExit('Unable to inspect crontab')
    original=current.stdout
    begin='# BEGIN MOED VERIFIED RECOVERY'
    end='# END MOED VERIFIED RECOVERY'
    if begin in original or end in original:
        raise SystemExit('A recovery schedule already exists. Inspect it instead of adding a duplicate.')
    entries=(begin+'\n'
        '*/30 * * * * /usr/bin/python3 '+str(runner)+' 2>&1 | /usr/bin/logger -t moed-recovery\n'
        '*/5 * * * * /usr/bin/python3 '+str(runner)+' --check >/dev/null 2>&1 || /usr/bin/logger -p user.err -t moed-recovery "Recovery target missed or latest backup attempt failed; inspect ~/.local/state/moed-backup/status.json"\n'+end+'\n')
    state=Path.home()/'.local/state/moed-backup'
    backup=state/'crontab-before-recovery.txt'
    with backup.open('x') as handle: handle.write(original)
    fresh=subprocess.run(['crontab','-l'],capture_output=True,text=True)
    if fresh.returncode not in (0,1) or fresh.stdout!=original:
        raise SystemExit('Crontab changed during preparation; no schedule installed.')
    subprocess.run(['crontab','-'],input=original.rstrip()+'\n\n'+entries,text=True,check=True)
    installed=subprocess.check_output(['crontab','-l'],text=True)
    if entries not in installed: raise SystemExit('Installed schedule verification failed')
    print('Recovery runs every 30 minutes, with a freshness check every five minutes. Existing cron entries preserved.')


if __name__=='__main__': main()
