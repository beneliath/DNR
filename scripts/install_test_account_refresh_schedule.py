#!/usr/bin/env python3
"""Install the qualified Test Account baseline refresh in s1's existing crontab."""
import datetime
import json
import os
from pathlib import Path
import subprocess

BEGIN='# BEGIN MOED TEST ACCOUNT BASELINE REFRESH'
END='# END MOED TEST ACCOUNT BASELINE REFRESH'
RUNNER=Path('/home/dgilmore/.local/lib/moed-test-account-refresh/v1/test_account_baseline_refresh.py')
STATE=Path('/home/dgilmore/.local/state/moed-test-account-refresh')


def scheduled_crontab(original):
    if BEGIN in original or END in original:
        raise ValueError('A Test Account refresh schedule already exists; inspect it before changing it.')
    block=(BEGIN+'\n'
        '# Daily at 02:00 America/Chicago, using the verified s1 cron daemon timezone.\n'
        '0 2 * * * /usr/bin/python3 '+str(RUNNER)+' --apply >>'+str(STATE/'run.log')+' 2>&1 || '
        '/usr/bin/logger -p user.err -t moed-test-account-refresh "Test Account baseline refresh failed; inspect '+str(STATE/'status.json')+' and run.log"\n'
        +END+'\n')
    return original.rstrip()+'\n\n'+block


def main():
    os.umask(0o077)
    if Path(__file__).resolve().with_name(RUNNER.name)!=RUNNER:
        raise SystemExit('Install the reviewed helper bundle at the documented s1 path first.')
    if subprocess.check_output(['timedatectl','show','--value','-p','Timezone'],text=True).strip()!='America/Chicago':
        raise SystemExit('s1 cron must use America/Chicago; no schedule installed.')
    subprocess.run(['systemctl','is-active','--quiet','cron'],check=True)
    # Debian cron uses the system zone. Do not use TZ= as a scheduling override.
    import test_account_baseline_refresh as refresh
    rehearsal=json.loads((STATE/'rehearsal.json').read_text())
    age=datetime.datetime.now(datetime.timezone.utc)-datetime.datetime.fromisoformat(rehearsal['qualified_at'])
    if not (rehearsal['isolated_restore_verified'] and rehearsal['baseline_unchanged']
            and rehearsal['code_sha256']==refresh.code_hash()
            and datetime.timedelta(0)<=age<datetime.timedelta(hours=24)
            and rehearsal['application_image']==refresh.inspect(refresh.WEB)['Image']):
        raise SystemExit('A successful recent rehearsal of this exact runner and live app is required.')
    result=subprocess.run(['crontab','-l'],text=True,capture_output=True)
    if result.returncode not in (0,1):raise SystemExit('Cannot read crontab.')
    original=result.stdout; replacement=scheduled_crontab(original)
    backup=STATE/('crontab-before-'+datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')+'.txt')
    with backup.open('x') as handle:handle.write(original)
    current=subprocess.run(['crontab','-l'],text=True,capture_output=True)
    if current.returncode!=result.returncode or current.stdout!=original:raise SystemExit('Crontab changed during preparation.')
    subprocess.run(['crontab','-'],input=replacement,text=True,check=True)
    if subprocess.check_output(['crontab','-l'],text=True)!=replacement:raise SystemExit('Installed crontab verification failed.')
    policy_path=refresh.BUNDLE/'refresh-policy.json'
    policy=json.loads(policy_path.read_text())
    policy['daily_refresh']={'schedule':'0 2 * * *','timezone':'America/Chicago','runner':str(RUNNER),
        'source':'approved immutable baseline','window_months_before':3,'window_months_after':9,
        'status_directory':str(STATE),'cron_backup':str(backup)}
    temporary=policy_path.with_suffix('.json.tmp');temporary.write_text(json.dumps(policy,indent=2)+'\n');temporary.replace(policy_path)
    print('Installed daily Test Account baseline refresh at 02:00 America/Chicago; existing cron entries preserved.')


if __name__=='__main__':main()
