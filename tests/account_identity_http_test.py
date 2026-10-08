"""Local-only regression for human proof, primary switching and Account backups.

Uses nonce-tagged identities, never reads/prints private records or credentials.
Encrypted test exports are checked by envelope and immediately removed.
"""
import argparse
import base64
import html
import json
import re
import secrets
import subprocess
from urllib.parse import parse_qs, urlsplit

from account_isolation_http_test import Client, csrf, fixture, cleanup
from account_creation_http_test import sql

PRIMARY = 'http://localhost:8080/a/shalom-in-messiah'
KEY = 'test-account'
MEMBER = 'http://localhost:8080/a/' + KEY
WEB = 'moed-account-test-account-web-1'
BACKUP = 'moed-account-test-account-backup-1'


def php(container, code, payload=None):
    encoded = base64.b64encode(json.dumps(payload or {}).encode()).decode()
    source = "<?php require '/var/www/html/bootstrap.php'; $input=json_decode(base64_decode('" + encoded + "'),true); " + code
    result = subprocess.run(['docker','exec','-i',container,'php'], input=source,
                            text=True, capture_output=True, check=True)
    return json.loads(result.stdout)


def api(operation, payload):
    return php(WEB, "try { echo json_encode(['ok'=>true,'result'=>platformCall($input['operation'],$input['payload'])]); } catch (Throwable $e) { echo '{\"ok\":false}'; }",
               {'operation':operation,'payload':payload})


def issue_ticket(client):
    page = client.request('accounts.php')
    result = client.request('accounts.php', {'csrf_token':csrf(page[2]),'action':'switch','account_key':KEY})
    assert result[0] == 302 and '/account_signin.php?ticket=' in result[1].get('Location','')
    return result[1]['Location']


def export(request):
    return php(BACKUP, "require_once '/var/www/html/database_backup_service.php'; try { $archive=createAuthenticatedDatabaseExport($conn,$input,'synthetic-test'); "
               "$valid=file_get_contents($archive['path'],false,null,0,strlen(DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC))===DNR_DATABASE_BACKUP_ENCRYPTED_MAGIC; "
               "unlink($archive['path']); echo json_encode(['ok'=>$valid]); } catch (Throwable $e) { echo '{\"ok\":false}'; }", request)['ok']


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--local-preview',action='store_true',required=True);parser.parse_args()
    primary=member_fixture=None
    try:
        primary=fixture('dnr-web-1');member_fixture=fixture(WEB)
        user=primary['users']['superadmin']
        version=int(sql(f"SELECT auth_version FROM users WHERE id={user['id']}"))
        identity={'id':user['id'],'auth_version':version}
        # This eligible identity has never signed in. Service credentials alone
        # must not authenticate it or expose the directory / issue tickets.
        for operation in ['validate','list','ticket','elevate','extend_elevation','create']:
            assert not api(operation,identity)['ok'], 'Human sign-in proof missing: '+operation
        admin=Client(PRIMARY);admin.login(user)
        ticket=parse_qs(urlsplit(issue_ticket(admin)).query)['ticket'][0]
        redeemed=api('redeem',{'ticket':ticket})
        assert redeemed['ok'] and not api('redeem',{'ticket':ticket})['ok']
        proof=identity | {'grant':redeemed['result']['grant']}
        assert api('validate',proof)['ok'] and api('list',proof)['ok']
        assert not api('ticket',proof | {'account_key':'account-isolation-preview'})['ok'], 'Service minted cross-Account ticket'
        for variant in ['expired','wrong-member','wrong-user','wrong-version','wrong-purpose']:
            token=php('dnr-web-1', "$identity=$input['identity']; $key='test-account'; $now=time(); "
                "switch ($input['variant']) { case 'expired':$now-=901;break;case 'wrong-member':$key='account-isolation-preview';break;"
                "case 'wrong-user':$identity['id']++;break;case 'wrong-version':$identity['auth_version']++;break;} "
                "echo json_encode($input['variant']==='wrong-purpose'?platformIssueSwitchIntent($identity,$key):platformIssueIdentityGrant($key,$identity,$now));",
                {'identity':identity,'variant':variant})
            assert not api('validate',identity | {'grant':token})['ok'], variant
        assert not api('validate',identity | {'grant':'forged'})['ok']

        member=Client(MEMBER);assert member.request(issue_ticket(admin))[0]==302
        page=member.request('accounts.php');assert page[0]==200
        form=next(f for f in re.findall(r'<form[^>]*>.*?</form>',page[2],re.S)
                  if 'name="account_key" value="account-isolation-preview"' in f)
        intent=html.unescape(re.search(r'name="switch_intent" value="([^"]+)"',form)[1])
        fields={'action':'switch','account_key':'account-isolation-preview','switch_intent':intent}
        anonymous=Client(PRIMARY).request('accounts.php',fields)
        assert anonymous[0]!=302 or '/account_signin.php?ticket=' not in anonymous[1].get('Location','')
        assert admin.request('accounts.php',fields | {'account_key':KEY})[0]==403
        switched=admin.request('accounts.php',fields)
        assert switched[0]==302 and '/a/account-isolation-preview/account_signin.php?ticket=' in switched[1].get('Location','')

        proxy=php(WEB,"echo json_encode($conn->execute_query('SELECT id,auth_version FROM users WHERE platform_identity_id=?',[$input['id']])->fetch_assoc());",identity)
        request={'user_id':int(proxy['id']),'auth_version':int(proxy['auth_version']),
                 'account_key':KEY,'platform_identity':proof,'admin_password':user['password'],
                 'admin_code':user['codes'].pop(0),'backup_password':secrets.token_hex(24)}
        assert not export(request | {'account_key':'wrong-account'}), 'Exporter ignored Account identity'
        assert not export(request | {'platform_identity':identity}), 'Exporter accepted no sign-in proof'
        assert not export(request | {'admin_password':'incorrect'}), 'Exporter accepted wrong password'
        assert export(request), 'SuperAdmin encrypted member export failed'
        assert not export(request), 'Exporter reused consumed MFA'
        ordinary=member_fixture['users']['admin']
        actor_version=int(sql(f"SELECT auth_version FROM users WHERE id={ordinary['id']}",'moed-account-test-account-db-1'))
        assert export({'user_id':ordinary['id'],'auth_version':actor_version,'account_key':KEY,
                       'admin_password':ordinary['password'],'admin_code':ordinary['codes'].pop(0),
                       'backup_password':secrets.token_hex(24)}), 'Ordinary Account Admin export regressed'
        sql(f"UPDATE users SET auth_version=auth_version+1 WHERE id={user['id']}")
        assert not api('validate',proof)['ok'] and not export(request | {'admin_code':user['codes'].pop(0)}), 'Revoked identity retained access'
        assert member.request('dashboard.php')[0]==403
        print('PASS: no-proof/forged/expired/audience/identity/version rejection; MFA sign-in; replay denial; primary-session switching; encrypted member backups; fresh MFA; ordinary Admin; revocation. Test exports removed.')
    finally:
        if primary:
            sql(f"DELETE FROM users WHERE platform_identity_id={primary['users']['superadmin']['id']}",'moed-account-test-account-db-1')
        if member_fixture:cleanup('moed-account-test-account-db-1',member_fixture)
        if primary:cleanup('dnr-db-1',primary)


if __name__=='__main__':main()
