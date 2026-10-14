"""Date boundaries and cron preservation for the recurring baseline restore."""
import datetime
import json
from pathlib import Path
import subprocess
import sys
import unittest

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'scripts'))
from test_account_baseline_refresh import month_shift
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


if __name__=='__main__':unittest.main()
