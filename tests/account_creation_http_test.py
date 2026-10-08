"""Verify SuperAdmin creation and a consistent directory across localhost Accounts.

Requires the local provisioning worker to be stopped. Holds its lock while
creating and removing only nonce-tagged pending requests; no deployment is made.
"""
import argparse
import fcntl
import html
import json
import re
import secrets
import subprocess

from account_isolation_http_test import ROOT, Client, csrf, fixture, cleanup, preview_administrator

PRIMARY = 'http://localhost:8080/a/shalom-in-messiah'
MEMBERS = ['account-isolation-preview', 'test-account']


def sql(statement, db='dnr-db-1'):
    return subprocess.run(['docker', 'exec', '-i', db, 'sh', '-c',
        'MYSQL_PWD=$(cat /run/secrets/dnr_mysql_root_password) mysql -N -B -uroot dnr'],
        input=statement, text=True, check=True, capture_output=True).stdout.strip()


def directory(client, current):
    status, _, body = client.request('accounts.php')
    assert status == 200 and '<h2>Create Account</h2>' in body
    assert 'Return to Shalom in Messiah' not in body
    rows = re.findall(r'<li class="account-directory-row(.*?)</li>', body, re.S)
    keys = []
    for row in rows:
        key = re.search(r'aria-label="Address label">([^<]+)', row)[1]
        keys.append(key)
        assert ('account-directory-current' in row) == (key == current), 'Wrong Account highlighted'
        assert ('aria-current="true"' in row) == (key == current)
        if key == 'shalom-in-messiah':
            assert 'Archive Account' not in row and 'Delete Account' not in row, 'Primary Account lost protection'
            if key != current:
                assert f'href="{PRIMARY}/dashboard.php"' in row and 'Open Account' in row
    assert len(keys) == len(set(keys)) and keys[0] == 'shalom-in-messiah'
    assert all(key in keys for key in MEMBERS), 'Directory omitted an available Account'
    return body, keys


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--local-preview', action='store_true', required=True)
    parser.add_argument('--keep-fixture', action='store_true', help='Keep synthetic users for a following browser check')
    args = parser.parse_args()
    lock = (ROOT/'var/deployment/account-provisioner.lock').open('a')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    data = None
    keys = ['creation-check-' + secrets.token_hex(6) for _ in range(3)]
    fixture_path = ROOT/'var/deployment/account-creation-fixture.json'
    try:
        data = fixture('dnr-web-1')
        fixture_path.write_text(json.dumps(data)); fixture_path.chmod(0o600)
        user = data['users']['superadmin']
        admin = Client(PRIMARY); admin.login(user)
        page, original = directory(admin, 'shalom-in-messiah')
        ordinary = Client(PRIMARY); ordinary.login(data['users']['admin'])
        denied = ordinary.request('accounts.php', {'csrf_token': csrf(ordinary.request('dashboard.php')[2]),
            'action': 'create', 'name': 'Should Not Exist', 'account_key': keys[0]})
        assert denied[0] == 403, 'Account Admin created an Account'

        clients = [admin]
        for key in MEMBERS:
            result = admin.request('accounts.php', {'csrf_token': csrf(admin.request('accounts.php')[2]),
                'action': 'switch', 'account_key': key})
            member = Client('http://localhost:8080/a/' + key)
            assert member.request(result[1]['Location'])[0] == 302
            body, listed = directory(member, key)
            assert listed == original, 'Switching changed directory membership/order'
            assert admin.request(PRIMARY + '/dashboard.php')[0] == 200, 'Primary Open destination is unavailable'
            preview_administrator(member)
            clients.append(member)

        # A member API key and valid identity alone must not authorize creation.
        version = int(sql(f"SELECT auth_version FROM users WHERE id={user['id']}"))
        probe = """<?php require '/var/www/html/bootstrap.php';
try { platformCall('create', ['id'=>%d, 'auth_version'=>%d, 'name'=>'Denied', 'account_key'=>'%s']);
    exit(1); } catch (RuntimeException $expected) { echo 'denied'; }
""" % (user['id'], version, keys[0])
        response = subprocess.run(['docker', 'exec', '-i', 'moed-account-test-account-web-1', 'php'],
            input=probe, text=True, check=True, capture_output=True)
        assert response.stdout == 'denied', 'Central creation accepted no fresh-factor proof'

        for index, client in enumerate(clients):
            current = 'shalom-in-messiah' if index == 0 else MEMBERS[index - 1]
            body, _ = directory(client, current)
            fields = {'action': 'create', 'name': 'Creation Check', 'account_key': keys[index]}
            assert client.request('accounts.php', fields)[0] == 400, 'Create accepted no CSRF'
            locked = client.request('accounts.php', dict(fields, csrf_token=csrf(body)))
            assert locked[0] == 302 and 'admin_elevation.php' in locked[1]['Location'], 'Create skipped unlock'
            client.elevate(user)
            body, _ = directory(client, current)
            extension = client.request('admin_extend.php', {'csrf_token': csrf(body)}, {'Accept': 'application/json'})
            extended = json.loads(extension[2])
            assert extension[0] == 200 and extended['expires_at'] - extended['server_now'] > 550, 'Unlock extension failed'
            invalid = client.request('accounts.php', dict(fields, account_key='../invalid', csrf_token=csrf(body)))
            assert invalid[0] == 200 and 'Enter an Account name' in invalid[2], 'Invalid label accepted'
            created = client.request('accounts.php', dict(fields, csrf_token=csrf(body)))
            assert created[0] == 302 and created[1]['Location'] == 'accounts.php?created=1', 'Create left current Account or failed'
            assert sql(f"SELECT CONCAT(state, ':', requested_by) FROM platform_accounts WHERE account_key='{keys[index]}'") == f"pending:{user['id']}", 'Creation did not use canonical actor'
            duplicate = client.request('accounts.php', dict(fields, csrf_token=csrf(body)))
            assert duplicate[0] == 200 and 'already' in duplicate[2], 'Duplicate label was not handled'
            for observer, observer_key in zip(clients, ['shalom-in-messiah', *MEMBERS]):
                _, listed = directory(observer, observer_key)
                assert keys[index] in listed, 'New Account absent from shared directory'
            client.request('admin_lock.php', {'csrf_token': csrf(body)})
            locked = client.request('accounts.php', dict(fields, csrf_token=csrf(client.request('accounts.php')[2])))
            assert locked[0] == 302 and 'admin_elevation.php' in locked[1]['Location'], 'Locked session reused elevation proof'

        # A member displays a target-bound form submitted to the primary browser
        # session. A member service cannot issue cross-Account tickets itself.
        body = clients[1].request('accounts.php')[2]
        form = next(form for form in re.findall(r'<form[^>]*>.*?</form>', body, re.S)
                    if f'name="account_key" value="{MEMBERS[1]}"' in form)
        intent = html.unescape(re.search(r'name="switch_intent" value="([^"]+)"', form)[1])
        target = admin.request('accounts.php', {'switch_intent': intent, 'action': 'switch', 'account_key': MEMBERS[1]})
        assert target[0] == 302 and '/account_signin.php?ticket=' in target[1]['Location']
        assert clients[2].request(target[1]['Location'])[0] == 302
        directory(clients[2], MEMBERS[1])
        print('PASS: creation from all three Accounts; shared directory/current highlight; canonical actor; Admin/preview restrictions; MFA/CSRF; proof requirement; extension/lock; validation/duplicates; member switching.')
    finally:
        for key in keys:
            sql(f"DELETE FROM platform_accounts WHERE account_key='{key}' AND state='pending'")
        if data:
            fixture_path.write_text(json.dumps(data)); fixture_path.chmod(0o600)
            if not args.keep_fixture:
                for key in MEMBERS:
                    sql(f"DELETE FROM users WHERE platform_identity_id={data['users']['superadmin']['id']}", 'moed-account-' + key + '-db-1')
                cleanup('dnr-db-1', data)
                fixture_path.unlink(missing_ok=True)
        lock.close()


if __name__ == '__main__': main()
