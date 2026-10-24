import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch
sys.path.insert(0,str(Path(__file__).resolve().parents[1]/'scripts'))
from recovery_accounts import account_directory, AccountCoverageError


class AccountDiscoveryTests(unittest.TestCase):
    def directory(self, rows):
        with tempfile.TemporaryDirectory() as directory:
            root=Path(directory); config=root/'var/deployment/account-production/config.json'
            config.parent.mkdir(parents=True)
            config.write_text(json.dumps(dict(primary_key='primary',project_prefix='moed-account')))
            primary={'Id':'web','Config':{'Env':['DNR_ACCOUNT_KEY=primary','DNR_ACCOUNT_MODE=primary','DNR_ACCOUNTS_ENABLED=1']}}
            with patch('recovery_accounts.inspect',return_value=primary), patch('recovery_accounts.resolve_service',return_value='web'), patch('recovery_accounts.subprocess.check_output',return_value=json.dumps(rows)):
                return account_directory(root,'moed')

    def test_includes_primary_test_and_future_accounts(self):
        accounts=self.directory([{'account_key':key,'state':'ready'} for key in ('test-account','new-account')])
        self.assertEqual([a['key'] for a in accounts],['new-account','primary','test-account'])
        self.assertEqual([a['project'] for a in accounts],['moed-account-new-account','moed','moed-account-test-account'])

    def test_cannot_silently_omit_offline_or_malformed_account(self):
        for row in ({'account_key':'test-account','state':'disabled'}, {'account_key':'../escape','state':'ready'}):
            with self.assertRaises(AccountCoverageError): self.directory([row])


if __name__=='__main__': unittest.main()
