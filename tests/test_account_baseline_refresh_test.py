"""Date boundaries and cron preservation for the recurring baseline restore."""
import datetime
import json
from pathlib import Path
import subprocess
import sys
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'scripts'))
from test_account_baseline_refresh import month_shift,migration_plan,upgrade_staged_schema
from install_test_account_refresh_schedule import scheduled_crontab,BEGIN,END


class BaselineRefreshTest(unittest.TestCase):
    def mapped(self,cases):
        result=subprocess.run(['php',str(ROOT/'scripts/test_account_baseline_remap.php'),'--map-json='+json.dumps(cases)],capture_output=True,text=True,check=True)
        return json.loads(result.stdout)

    def test_calendar_window_clamps_month_ends_and_leap_days(self):
        for anchor,start,end in [('2027-05-31','2027-02-28','2028-02-29'),('2028-05-31','2028-02-29','2029-02-28'),('2028-02-29','2027-11-29','2028-11-29')]:
            date=datetime.date.fromisoformat(anchor)
            self.assertEqual(month_shift(date,-3).isoformat(),start)
            self.assertEqual(month_shift(date,9).isoformat(),end)

    def test_monthly_event_endpoints_match_window(self):
        for anchor in ['2026-10-15','2027-01-31','2027-05-31','2028-02-29']:
            cases=[{'event':event,'source':'2026-10-12','anchor':anchor} for event in ['2026-07-12','2027-07-12']]
            date=datetime.date.fromisoformat(anchor)
            self.assertEqual(self.mapped(cases),[month_shift(date,-3).isoformat(),month_shift(date,9).isoformat()])

    def test_history_snapshot_nulls_birthdays_and_times(self):
        value={'expense_date':'2026-10-11','null':None,'time':'09:30:00','birthday':'1980-02-29',
            'context':{'submitted_at':'2026-10-12T15:30:00-05:00','date':'2026-10-13 09:15:00.123456'}}
        result=self.mapped([{'value':value,'source':'2026-10-12','anchor':'2026-10-15'}])[0]
        self.assertEqual(result,{'expense_date':'2026-10-14','null':None,'time':'09:30:00','birthday':'1980-02-29',
            'context':{'submitted_at':'2026-10-15T15:30:00-05:00','date':'2026-10-16 09:15:00.123456'}})

    def test_cron_preserves_existing_jobs_and_rejects_duplicate(self):
        original='MAILTO=operator@example.invalid\n11 */4 * * * existing-backup\n'
        updated=scheduled_crontab(original)
        self.assertTrue(updated.startswith(original))
        self.assertEqual(updated.count('0 2 * * *'),1)
        self.assertIn('--apply',updated)
        for partial in [updated,BEGIN+'\n',END+'\n']:
            with self.assertRaises(ValueError):scheduled_crontab(partial)

    def test_schema_plan_accepts_new_migrations_and_keeps_baseline_checksums(self):
        baseline='20261012_baseline.sql\t'+'a'*64+'\tapplied'
        current=baseline+'\n20261015_mileage.sql\t'+'b'*64+'\tapplied'
        self.assertEqual(migration_plan(baseline,current),{'baseline_migration_count':1,'current_migration_count':2,'added_migrations':['20261015_mileage.sql']})
        self.assertEqual(migration_plan(baseline,baseline)['added_migrations'],[])

    def test_schema_plan_rejects_missing_modified_or_failed_migrations(self):
        baseline='20261012_baseline.sql\t'+'a'*64+'\tapplied'
        for current in ['',baseline.replace('a'*64,'b'*64),baseline.replace('applied','applying'),baseline+'\n20261015_mileage.sql\t'+'b'*64+'\tfailed']:
            with self.assertRaises(RuntimeError):migration_plan(baseline,current)

    def test_schema_upgrade_refuses_live_or_networked_databases_before_writing(self):
        for name,network,owner in [('moed-account-test-account-db-1','none','test-account-baseline-refresh'),
                ('dnr-online-restore-nightly-baseline-stage-fixture','bridge','test-account-baseline-refresh'),
                ('dnr-online-restore-nightly-baseline-stage-fixture','none','unrelated')]:
            with patch('test_account_baseline_refresh.inspect',return_value={'HostConfig':{'NetworkMode':network},'Config':{'Labels':{'org.dnr.recovery-owner':owner}}}), patch('test_account_baseline_refresh.subprocess.run') as command:
                with self.assertRaises(RuntimeError):upgrade_staged_schema(name,'','','')
                command.assert_not_called()


if __name__=='__main__':unittest.main()
