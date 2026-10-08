"""Exercise archive/restore/delete on one explicitly disposable localhost Account.

The fixture JSON is produced during local setup and must name a lifecycle-check-*
Account. This never accepts production origins or existing user Accounts.
"""
import argparse
import json
from pathlib import Path
import re
import subprocess
import sys
import time
from types import SimpleNamespace

from account_isolation_http_test import ROOT, Client, csrf, fixture, cleanup
sys.path.insert(0, str(ROOT/'scripts'))
from account_lifecycle_local import process_lifecycle, project_resources
from provision_local_account import control


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--local-preview', action='store_true', required=True)
    parser.add_argument('--fixture-file', type=Path, required=True)
    args = parser.parse_args()
    data = json.loads(args.fixture_file.read_text())
    key = data['key']
    assert re.fullmatch(r'lifecycle-check-[a-f0-9]{6}', key), 'Only disposable lifecycle Accounts are permitted'
    project = 'moed-account-' + key
    member_url = 'http://localhost:8080/a/' + key
    primary_url = 'http://localhost:8080/a/shalom-in-messiah'
    operator = SimpleNamespace(primary_web='dnr-web-1', primary_ingress='dnr-ingress-1', primary_port=8080)
    left = data['primary']
    if 'member' not in data:
        data['member'] = fixture(project + '-web-1')
        args.fixture_file.write_text(json.dumps(data)); args.fixture_file.chmod(0o600)
    right = data['member']
    admin = Client(primary_url); admin.login(left['users']['superadmin'])
    ordinary = Client(primary_url); ordinary.login(left['users']['admin'])
    member = Client(member_url); member.login(right['users']['admin'])
    token = csrf(admin.request('accounts.php')[2])
    fields = {'csrf_token': token, 'action': 'archive', 'account_key': key}
    assert ordinary.request('accounts.php', dict(fields, csrf_token=csrf(ordinary.request('dashboard.php')[2])))[0] == 403
    assert admin.request('accounts.php', {'action': 'archive', 'account_key': key})[0] == 400
    assert 'admin_elevation.php' in admin.request('accounts.php', fields)[1].get('Location', ''), 'Archive did not require recent verification'
    admin.elevate(left['users']['superadmin'])

    def request(action, confirmation='', target=key):
        page = admin.request('accounts.php')
        return admin.request('accounts.php', {'csrf_token': csrf(page[2]), 'action': action,
            'account_key': target, 'confirmation': confirmation})

    def state():
        return next(a['state'] for a in control('dnr-web-1', 'directory') if a['account_key'] == key)

    def finish(expected):
        assert state() == expected
        process_lifecycle(operator, {'account_key': key, 'state': expected})

    def assert_blocked():
        for _ in range(10):
            if member.request('dashboard.php')[0] == 404: break
            time.sleep(1)
        else: raise AssertionError('Archived Account remained reachable')
        assert Client(member_url).request('calendar.php?token=' + right['calendar']['token'])[0] == 404
        assert Client(member_url).request('short_link.php?code=' + right['short_code'])[0] == 404
        result = member.request('/login.php')
        login = member.request('/login.php', {'csrf_token': csrf(result[2]), 'username': right['users']['admin']['username'], 'password': right['users']['admin']['password']})
        assert 'account_login.php?ticket=' not in login[1].get('Location', ''), 'Archived username received a sign-in ticket'

    assert request('archive', target='shalom-in-messiah')[0] == 200 and state() == 'ready', 'Primary Account was changed'
    assert request('delete', key)[0] == 200 and state() == 'ready', 'Active Account could be deleted'
    assert request('archive')[0] == 302
    finish('archiving'); assert state() == 'disabled'; assert_blocked()
    assert project_resources(project, 'volume'), 'Archive removed persistent data'
    assert request('delete', 'wrong-label')[0] == 200 and state() == 'disabled', 'Incorrect deletion confirmation accepted'
    assert request('restore')[0] == 302
    finish('restoring'); assert state() == 'ready'
    for _ in range(10):
        if member.request('dashboard.php')[0] != 404: break
        time.sleep(1)
    assert member.request('dashboard.php')[0] == 302, 'Pre-archive session survived restoration'
    member.login(right['users']['admin'])
    assert right['marker'] in member.request(f"view_organization.php?id={right['organization_id']}")[2], 'Restore lost records'
    assert right['marker'] in Client(member_url).request('short_link.php?code=' + right['short_code'] + '&download=1')[2], 'Restore lost files'
    assert request('archive')[0] == 302
    finish('archiving'); assert_blocked()
    assert request('delete', key)[0] == 302
    finish('deleting'); assert state() == 'deleted'; assert_blocked()
    assert not project_resources(project, 'container') and not project_resources(project, 'volume')
    assert not (ROOT/'var/accounts'/key).exists()
    receipt = json.loads((ROOT/'var/backups/account-deletion'/key/'deletion-receipt.json').read_text())
    assert receipt['restore_verified'] and receipt['configuration'] and receipt['persistent_files']
    assert key not in admin.request('accounts.php')[2], 'Deleted Account remained in the directory'
    # Remove only this synthetic tombstone so its synthetic creator can be removed.
    sql = "DELETE FROM platform_accounts WHERE account_key='" + key + "' AND state='deleted';"
    subprocess.run(['docker','exec','-i','dnr-db-1','sh','-c',
        'MYSQL_PWD=$(cat /run/secrets/dnr_mysql_root_password) mysql -uroot dnr'], input=sql,text=True,check=True,capture_output=True)
    cleanup('dnr-db-1', left)
    args.fixture_file.unlink()
    print('PASS: SuperAdmin-only actions; CSRF/elevation; primary protection; archive access denial; restore preserves records/files and revokes sessions; typed deletion confirmation; verified recovery backup; deployment removal.')


if __name__ == '__main__': main()
