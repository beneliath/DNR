"""Local-only password-only demo regression, with exact-ID fixture cleanup."""
import argparse
import fcntl
import json
import secrets
import subprocess
from account_isolation_http_test import ROOT, Client, csrf, fixture, cleanup
from account_creation_http_test import sql

WEB='moed-account-test-account-web-1'
DB='moed-account-test-account-db-1'


def php(code, data=None, web=WEB):
    prefix='<?php require "/var/www/html/config.php"; require_once "/var/www/html/functions.php"; require_once "/var/www/html/two_factor_helpers.php"; '
    if data is not None:prefix+='$input=json_decode('+json.dumps(json.dumps(data))+',true); '
    result=subprocess.run(['docker','exec','-i',web,'php'],input=prefix+code,text=True,capture_output=True,check=True)
    return json.loads(result.stdout)


def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--local-preview',action='store_true',required=True);parser.parse_args()
    lock=(ROOT/'var/deployment/account-provisioner.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    uid=None;other=None;password=secrets.token_hex(16)
    try:
        created=php("if($conn->query(\"SELECT 1 FROM users WHERE username='TestAdmin'\")->num_rows)throw new RuntimeException('A pre-existing TestAdmin must not be changed by this test.'); "
            "$p=$conn->query('SELECT settings FROM account_profile WHERE id=1')->fetch_assoc()['settings']; $conn->begin_transaction(); "
            "$conn->execute_query(\"INSERT INTO users(username,password,role,account_status,is_superadmin,platform_identity_id,two_factor_enabled,email,task_digest_enabled) VALUES('TestAdmin',?,'admin','active',0,NULL,0,'testadmin@example.invalid',0)\",[\\Dnr\\Security\\PasswordPolicy::hash($input['password'])]); "
            "$id=(int)$conn->insert_id;registerAccountLogin($conn,$id,'TestAdmin'); "
            "$conn->execute_query(\"UPDATE account_profile SET settings=JSON_SET(settings,'$.demo_password_only_user_id',CAST(? AS UNSIGNED)) WHERE id=1\",[$id]); "
            "$conn->commit();flushAccountDirectory();echo json_encode(['id'=>$id,'settings'=>$p]);",{'password':password})
        uid=created['id'];member=Client('http://localhost:8080/a/test-account')
        form=member.request('/login.php')
        bad=member.request('/login.php',{'username':'TestAdmin','password':'wrong-password','csrf_token':csrf(form[2])})
        assert 'dashboard.php' not in bad[1].get('Location',''),'Wrong password accepted'
        form=member.request('/login.php')
        signed=member.request('/login.php',{'username':'TestAdmin','password':password,'csrf_token':csrf(form[2])})
        assert signed[0]==302 and '/a/test-account/account_login.php?ticket=' in signed[1].get('Location',''),'Common gateway did not route to Test Account'
        ticket=signed[1]['Location'];signed=member.request(ticket)
        assert signed[0]==302 and signed[1].get('Location')=='dashboard.php','Demo prompted for MFA enrollment/challenge'
        assert member.request(ticket)[0]==403,'Password handoff replay accepted'
        assert member.request('dashboard.php')[0]==200,'Password-only session rejected'
        assert member.request('users.php')[0]==200,'Ordinary Admin access missing'
        assert member.request('accounts.php')[0]==403,'Demo has platform account-directory access'
        assert member.request('/a/shalom-in-messiah/dashboard.php')[0]==302,'Demo can enter the primary Account'
        assert member.request('/a/account-isolation-preview/dashboard.php')[0]==302,'Demo can enter another member Account'
        form=member.request('admin_elevation.php')
        assert 'name="admin_code"' not in form[2] or 'name="admin_code" value=""' in form[2],'Demo elevation still requires a factor'
        response=member.request('admin_elevation.php',{'admin_password':password,'admin_code':'','csrf_token':csrf(form[2])},{'Accept':'application/json'})
        assert response[0]==200 and json.loads(response[2])['unlocked'],'Password-only Admin elevation failed'
        other=fixture('moed-account-account-isolation-preview-web-1')
        ordinary=Client('http://localhost:8080/a/account-isolation-preview');ordinary.login(other['users']['admin'])
        assert ordinary.request('dashboard.php')[0]==200,'Other user MFA sign-in regressed'
        php("$conn->execute_query('UPDATE users SET two_factor_enabled=1,auth_version=auth_version+1 WHERE id=?',[$input['id']]);echo '{}';",{'id':uid})
        assert member.request('dashboard.php')[0]==302,'Voluntary MFA/version change did not revoke the demo session'
        print('PASS: wrong-password rejection; common sign-in without MFA/setup; ticket replay denial; ordinary Admin/elevation; cross-account/directory denial; other-user MFA; session revocation.')
    finally:
        if other:cleanup('moed-account-account-isolation-preview-db-1',other)
        if uid:
            php("$conn->begin_transaction(); $conn->execute_query('UPDATE account_profile SET settings=? WHERE id=1',[$input['settings']]); removeAccountLogin($conn,$input['id']); $conn->commit();flushAccountDirectory(); $conn->execute_query('DELETE FROM account_login_handoffs WHERE user_id=?',[$input['id']]); $conn->execute_query(\"DELETE FROM users WHERE id=? AND username='TestAdmin'\",[$input['id']]);echo '{}';",{'id':uid,'settings':created['settings']})


if __name__=='__main__':main()
