"""Local-only account-wide security restrictions using nonce-tagged fixtures."""
import argparse
import fcntl
import json
import secrets
import subprocess

from account_isolation_http_test import ROOT, Client, csrf, fixture, cleanup
from account_creation_http_test import sql

TEST_WEB = 'moed-account-test-account-web-1'
TEST_DB = 'moed-account-test-account-db-1'
OTHER_WEB = 'moed-account-account-isolation-preview-web-1'


def php(code, data=None, web=TEST_WEB):
    source = '<?php require "/var/www/html/bootstrap.php"; '
    source += '$input=json_decode(' + json.dumps(json.dumps(data or {})) + ',true); ' + code
    result = subprocess.run(['docker', 'exec', '-i', web, 'php'], input=source,
                            text=True, capture_output=True, check=True)
    return json.loads(result.stdout)


def assert_security_denied(client, password, user_id, check_profile=True):
    page = client.request('dashboard.php')
    assert page[0] == 200 and 'href="two_factor_settings.php"' not in page[2], 'Account Security appeared in Test Account sidebar'
    assert 'href="mattermost.php"' not in page[2], 'Mattermost appeared in Test Account sidebar'
    token = csrf(page[2])
    digest = "echo json_encode(hash('sha256',serialize($conn->execute_query('SELECT * FROM users WHERE id=?',[$input['id']])->fetch_assoc())));"
    before = php(digest, {'id': user_id})
    fields = {'csrf_token': token, 'action': 'change_password', 'password': password,
              'new_password': password + '1', 'new_password_confirmation': password + '1'}
    for route in ['two_factor_settings.php', 'setup_2fa.php', 'two_factor_recovery_codes.php',
                  'recover_password.php', 'verify_email.php', 'mattermost.php']:
        assert client.request(route)[0] == 403, 'Security GET allowed: ' + route
        assert client.request(route, fields)[0] == 403, 'Security POST allowed: ' + route
    for action, payload in [('status', None), ('connect', {'code': 'SAMPLE'})]:
        response = client.request('api/v1/mattermost.php?action=' + action, payload)
        assert response[0] == 403 and json.loads(response[2])['code'] == 'account_unavailable', 'Mattermost API allowed Test Account access'
    if check_profile:
        profile = client.request('profile.php')
        assert profile[0] == 200 and 'id="recovery-email-heading"' not in profile[2], 'Recovery email controls appeared'
        for action in ['change_email', 'cancel_email_change', 'resend_verification']:
            assert client.request('profile.php', dict(fields, action=action))[0] == 403, 'Profile security mutation allowed: ' + action
    assert php(digest, {'id': user_id}) == before, 'Blocked security requests modified the user'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--local-preview', action='store_true', required=True)
    parser.parse_args()
    lock = (ROOT / 'var/deployment/account-provisioner.lock').open('a')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    primary = test = other = None
    try:
        test = fixture(TEST_WEB)
        other = fixture(OTHER_WEB)
        primary = fixture('dnr-web-1')
        for user in test['users'].values():
            client = Client('http://localhost:8080/a/test-account')
            client.login(user)  # Non-demo users still have to complete MFA.
            assert_security_denied(client, user['password'], user['id'])

        operator = primary['users']['superadmin']
        primary_client = Client('http://localhost:8080/a/shalom-in-messiah')
        primary_client.login(operator)
        dashboard = primary_client.request('dashboard.php')
        assert 'href="two_factor_settings.php"' in dashboard[2], 'Primary lost the security menu'
        assert primary_client.request('two_factor_settings.php')[0] == 200, 'Primary SuperAdmin security access regressed'
        assert 'href="mattermost.php"' in dashboard[2] and primary_client.request('mattermost.php')[0] == 200, 'Primary Mattermost access regressed'
        switch = primary_client.request('accounts.php', {'csrf_token': csrf(dashboard[2]),
                                                       'action': 'switch', 'account_key': 'test-account'})
        assert switch[0] == 302 and '/account_signin.php?ticket=' in switch[1].get('Location', '')
        member = Client('http://localhost:8080/a/test-account')
        assert member.request(switch[1]['Location'])[0] == 302
        linked_id = int(sql('SELECT id FROM users WHERE platform_identity_id=' + str(operator['id']), TEST_DB))
        assert_security_denied(member, operator['password'], linked_id, check_profile=False)

        for user in other['users'].values():
            client = Client('http://localhost:8080/a/account-isolation-preview')
            client.login(user)
            assert 'href="two_factor_settings.php"' in client.request('dashboard.php')[2], 'Other Account lost security menu'
            assert client.request('two_factor_settings.php')[0] == 200, 'Other Account security access regressed'
            assert 'href="mattermost.php"' in client.request('dashboard.php')[2] and client.request('mattermost.php')[0] == 200, 'Other Account Mattermost access regressed'

        anonymous = Client('http://localhost:8080/a/test-account')
        for route in ['setup_2fa.php', 'recover_password.php', 'verify_email.php']:
            assert anonymous.request(route)[0] == 403, 'Anonymous Test Account security route allowed'
        blocked = php('require_once "/var/www/html/account_recovery_helpers.php"; '
                      '$before=(int)$conn->execute_query("SELECT COUNT(*) FROM user_email_tokens WHERE user_id=?",[$input["user_id"]])->fetch_row()[0]; '
                      '$r=acceptSharedPasswordRecovery($conn,$input); '
                      '$after=(int)$conn->execute_query("SELECT COUNT(*) FROM user_email_tokens WHERE user_id=?",[$input["user_id"]])->fetch_row()[0]; '
                      'echo json_encode(["ok"=>$r["ok"],"unchanged"=>$before===$after]);',
                      {'operation_id': secrets.token_hex(32), 'user_id': test['users']['editor']['id']})
        assert blocked == {'ok': True, 'unchanged': True}, 'Shared recovery bypassed Test Account restriction'
        print('PASS: every Test Account role and linked SuperAdmin denied security/sidebar/GET/POST; user state unchanged; MFA preserved; other Accounts retain access; shared recovery suppressed.')
    finally:
        if primary:
            sql('DELETE FROM users WHERE platform_identity_id=' + str(primary['users']['superadmin']['id']), TEST_DB)
        if test:
            cleanup(TEST_DB, test)
        if other:
            cleanup('moed-account-account-isolation-preview-db-1', other)
        if primary:
            cleanup('dnr-db-1', primary)
        lock.close()


if __name__ == '__main__':
    main()
