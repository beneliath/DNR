import datetime
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch, MagicMock
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'scripts'))
from recovery_monitor import recovery_health, alert_due, send_alert, ALERT_RECIPIENT


class MonitorTests(unittest.TestCase):
    def test_health_requires_fresh_verified_success_and_no_error(self):
        now=datetime.datetime.now(datetime.timezone.utc)
        status={'last_success':{'snapshot_started_at':now.isoformat(),'completed_at':now.isoformat()}}
        self.assertEqual(recovery_health(status)['Status'],'Healthy')
        status['last_error']={'type':'RuntimeError'}
        self.assertEqual(recovery_health(status)['Status'],'Attention required')
        for timestamp in ('invalid', (now+datetime.timedelta(hours=1)).isoformat(), now.replace(tzinfo=None).isoformat()):
            self.assertEqual(recovery_health({'last_success':{'snapshot_started_at':timestamp}})['Status'],'Attention required')
        self.assertEqual(recovery_health({})['Status'],'Attention required')
        status={'last_success':{'snapshot_started_at':(now-datetime.timedelta(hours=2)).isoformat()}}
        self.assertEqual(recovery_health(status)['Status'],'Attention required')

    def test_alerts_are_deduplicated_and_recovery_is_sent_once(self):
        now=datetime.datetime.now(datetime.timezone.utc)
        self.assertTrue(alert_due({},False,now))
        self.assertFalse(alert_due({},True,now))
        failed={'healthy':False,'sent_at':now.isoformat()}
        self.assertFalse(alert_due(failed,False,now+datetime.timedelta(minutes=5)))
        self.assertTrue(alert_due(failed,False,now+datetime.timedelta(hours=6)))
        self.assertTrue(alert_due(failed,True,now))
        self.assertFalse(alert_due({'healthy':True,'sent_at':now.isoformat()},True,now))

    def test_tls_and_fixed_destination_without_secret_in_message(self):
        with tempfile.TemporaryDirectory() as folder:
            password=Path(folder)/'password'; password.write_text('synthetic-secret')
            config=dict(sender='backup@example.invalid',host='mail.example.invalid',connect_host='mail.example.invalid',peer='mail.example.invalid',port=587,encryption='starttls',username='fixture',password_file=str(password),ca=None)
            with patch('recovery_monitor.smtplib.SMTP') as smtp:
                send_alert(config,False)
                client=smtp.return_value
                client.starttls.assert_called_once()
                message=client.send_message.call_args.args[0]
                self.assertEqual(message['To'],ALERT_RECIPIENT)
                self.assertNotIn('synthetic-secret',message.as_string())
                client.close.assert_called_once()
            config['encryption']='none'
            with self.assertRaises(ValueError): send_alert(config,False)


if __name__=='__main__': unittest.main()
