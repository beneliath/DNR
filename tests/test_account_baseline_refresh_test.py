"""Date boundaries and cron preservation for the recurring baseline restore."""
import datetime
import fcntl
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import threading
from types import SimpleNamespace
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'scripts'))
import test_account_baseline_refresh as refresh_operator
from test_account_baseline_refresh import wait_for_lock,month_shift,migration_plan,upgrade_staged_schema,check_external_references,report_import_sql,MESSAGE_MONKEY_TABLES,PRESERVE,CLEAR_TABLES
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

    def test_reviewed_inbox_dependencies_are_accepted(self):
        links='marketing_inbox_inbound_parts\tinbound_email_messages\nmarketing_inbox_route_receipts\tinbound_email_messages'
        with patch('test_account_baseline_refresh.query',return_value=links):
            check_external_references()

    def test_unknown_business_dependency_still_blocks_refresh(self):
        with patch('test_account_baseline_refresh.query',return_value='future_inbox_records\tinbound_email_messages'):
            with self.assertRaisesRegex(RuntimeError,'future_inbox_records'):check_external_references()

    def test_unknown_reference_to_cleared_inbox_metadata_also_blocks_refresh(self):
        with patch('test_account_baseline_refresh.query',return_value='future_part_metadata\tmarketing_inbox_inbound_parts'):
            with self.assertRaisesRegex(RuntimeError,'future_part_metadata'):check_external_references()

    def test_message_monkey_sample_window_and_validation(self):
        for date,start,end in [('2028-05-31','2028-02-29','2029-02-28'),('2028-02-29','2027-11-29','2028-11-29')]:
            result=subprocess.run(['php',str(ROOT/'scripts/test_account_message_monkey_samples.php'),'--as-of='+date],capture_output=True,text=True,check=True)
            plan=json.loads(result.stdout)
            self.assertEqual(plan['window'],[start,end])
            self.assertEqual(plan['outgoing_jobs'],0)
        invalid=subprocess.run(['php',str(ROOT/'scripts/test_account_message_monkey_samples.php'),'--as-of=2028-02-30'],capture_output=True)
        self.assertNotEqual(invalid.returncode,0)

    def test_message_monkey_dependencies_are_classified_without_settings_reset(self):
        self.assertIn('marketing_campaign_recipients',MESSAGE_MONKEY_TABLES)
        self.assertIn('marketing_inbox_attachments',MESSAGE_MONKEY_TABLES)
        self.assertIn('marketing_service_mail',CLEAR_TABLES)
        self.assertIn('marketing_ses_credentials',PRESERVE)
        self.assertFalse(set(PRESERVE)&set(MESSAGE_MONKEY_TABLES+CLEAR_TABLES))
        links='marketing_inbox_attachments\tmarketing_inbox_messages\nmarketing_message_events\tmarketing_campaign_recipients'
        with patch('test_account_baseline_refresh.query',return_value=links):check_external_references()
        with patch('test_account_baseline_refresh.query',return_value='future_mail_records\tmarketing_service_mail'):
            with self.assertRaisesRegex(RuntimeError,'future_mail_records'):check_external_references()

    def test_report_transfer_decodes_hex_as_utf8_before_mysql_json_cast(self):
        with patch('test_account_baseline_refresh.query',return_value='5B5D'):
            self.assertIn(b'CAST(CONVERT(0x5B5D USING utf8mb4) AS JSON)',report_import_sql('stage'))
        for invalid in ['NULL','',"5B5D; DELETE FROM users",'123']:
            with patch('test_account_baseline_refresh.query',return_value=invalid):
                with self.assertRaises(RuntimeError):report_import_sql('stage')


class RefreshLockTest(unittest.TestCase):
    def test_waits_for_a_real_overlap_lock_and_retains_exclusion(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'run.lock'
            with path.open('a') as holder,path.open('a') as waiter:
                fcntl.flock(holder,fcntl.LOCK_EX|fcntl.LOCK_NB)
                release=threading.Timer(.05,lambda:fcntl.flock(holder,fcntl.LOCK_UN))
                release.start()
                try:
                    wait_for_lock(waiter,'Fixture overlap lock',timeout=5,poll_interval=.01)
                    with path.open('a') as probe:
                        with self.assertRaises(BlockingIOError):fcntl.flock(probe,fcntl.LOCK_EX|fcntl.LOCK_NB)
                finally:
                    release.join()

    def test_overlap_timeout_replaces_stale_success_without_running_refresh(self):
        with tempfile.TemporaryDirectory() as directory:
            state=Path(directory)
            (state/'status.json').write_text(json.dumps({'ok':True,'completed_at':'2026-10-26T22:04:47+00:00'}))
            with (state/'run.lock').open('a') as holder:
                fcntl.flock(holder,fcntl.LOCK_EX|fcntl.LOCK_NB)
                with patch.object(refresh_operator,'STATE',state),patch.object(refresh_operator,'LOCK_WAIT_SECONDS',0),patch.object(refresh_operator,'refresh') as refresh,patch.object(refresh_operator.signal,'signal'):
                    with self.assertRaisesRegex(RuntimeError,'Refresh overlap lock remained busy'):
                        refresh_operator.main(['--apply'])
                    refresh.assert_not_called()
            status=json.loads((state/'status.json').read_text())
            self.assertFalse(status['ok'])
            self.assertIn('failed_at',status)
            self.assertIn('no data replaced',status['error'])
            self.assertNotIn('completed_at',status)

    def test_live_refresh_holds_overlap_lock_until_completion(self):
        with tempfile.TemporaryDirectory() as directory:
            state=Path(directory)
            def refresh(args):
                self.assertTrue(args.apply)
                with (state/'run.lock').open('a') as probe:
                    with self.assertRaises(BlockingIOError):fcntl.flock(probe,fcntl.LOCK_EX|fcntl.LOCK_NB)
            with patch.object(refresh_operator,'STATE',state),patch.object(refresh_operator,'refresh',side_effect=refresh),patch.object(refresh_operator.signal,'signal'):
                refresh_operator.main(['--apply'])
            self.assertTrue(json.loads((state/'status.json').read_text())['ok'])
            with (state/'run.lock').open('a') as probe:fcntl.flock(probe,fcntl.LOCK_EX|fcntl.LOCK_NB)

    def test_rehearsal_preserves_live_status(self):
        with tempfile.TemporaryDirectory() as directory:
            state=Path(directory);status='{"ok":true,"completed_at":"previous-live-refresh"}'
            (state/'status.json').write_text(status)
            with patch.object(refresh_operator,'STATE',state),patch.object(refresh_operator,'refresh'),patch.object(refresh_operator.signal,'signal'):
                refresh_operator.main([])
            self.assertEqual((state/'status.json').read_text(),status)

    def test_failure_after_acquiring_lock_is_recorded(self):
        with tempfile.TemporaryDirectory() as directory:
            state=Path(directory)
            with patch.object(refresh_operator,'STATE',state),patch.object(refresh_operator,'refresh',side_effect=RuntimeError('Fixture validation failure')),patch.object(refresh_operator.signal,'signal'):
                with self.assertRaisesRegex(RuntimeError,'Fixture validation failure'):refresh_operator.main(['--apply'])
            status=json.loads((state/'status.json').read_text())
            self.assertFalse(status['ok'])
            self.assertEqual(status['error'],'Fixture validation failure')

    def test_deployment_metadata_is_read_after_waiting_for_lock(self):
        class StopAfterMetadata(Exception):pass
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory);(root/'.git/dnr-deploy').mkdir(parents=True)
            account=root/'var/accounts/test-account';account.mkdir(parents=True)
            def finish_deployment(lock):
                (account/'deployment.json').write_text(json.dumps({'account_key':'test-account','project':'moed-account-test-account','mode':'production'}))
            with patch.object(refresh_operator,'ROOT',root),patch.object(refresh_operator,'deployment_lock',side_effect=finish_deployment) as lock,patch.object(refresh_operator,'query',side_effect=StopAfterMetadata):
                with self.assertRaises(StopAfterMetadata):refresh_operator.refresh(SimpleNamespace(apply=False,as_of=None))
                lock.assert_called_once()


if __name__=='__main__':unittest.main()
