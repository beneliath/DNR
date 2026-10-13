"""Local-only password-only demo regression, with exact-ID fixture cleanup."""
import argparse
import fcntl
import json
import secrets
import subprocess
from account_isolation_http_test import ROOT, Client, csrf, fixture, cleanup
from account_creation_http_test import sql
from account_security_http_test import assert_security_denied

WEB='moed-account-test-account-web-1'
DB='moed-account-test-account-db-1'


def php(code, data=None, web=WEB):
    prefix='<?php require "/var/www/html/config.php"; require_once "/var/www/html/functions.php"; require_once "/var/www/html/two_factor_helpers.php"; '
    if data is not None:prefix+='$input=json_decode('+json.dumps(json.dumps(data))+',true); '
    result=subprocess.run(['docker','exec','-i',web,'php'],input=prefix+code,text=True,capture_output=True)
    if result.returncode:raise RuntimeError((result.stderr+result.stdout)[:1800])
    return json.loads(result.stdout)


def main():
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--local-preview',action='store_true',required=True);parser.parse_args()
    lock=(ROOT/'var/deployment/account-provisioner.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    uid=None;other=None;protected_fixture=None;demo_roles=[];password=secrets.token_hex(16)
    try:
        created=php("if($conn->query(\"SELECT 1 FROM users WHERE username='TestAdmin'\")->num_rows)throw new RuntimeException('A pre-existing TestAdmin must not be changed by this test.'); "
            "$p=$conn->query('SELECT settings FROM account_profile WHERE id=1')->fetch_assoc()['settings']; $conn->begin_transaction(); "
            "$conn->execute_query(\"INSERT INTO users(username,password,role,account_status,is_superadmin,platform_identity_id,two_factor_enabled,email,task_digest_enabled) VALUES('TestAdmin',?,'admin','active',0,NULL,0,'testadmin@example.invalid',0)\",[\\Dnr\\Security\\PasswordPolicy::hash($input['password'])]); "
            "$id=(int)$conn->insert_id;registerAccountLogin($conn,$id,'TestAdmin'); "
            "$conn->execute_query(\"UPDATE account_profile SET settings=JSON_SET(settings,'$.demo_password_only_user_id',CAST(? AS UNSIGNED)) WHERE id=1\",[$id]); "
            "$conn->commit();flushAccountDirectory();echo json_encode(['id'=>$id,'settings'=>$p]);",{'password':password})
        uid=created['id'];member=Client('http://localhost:8080/a/test-account')
        protected_fixture=fixture(WEB)
        protected=php("$u=$conn->execute_query('SELECT * FROM users WHERE id=?',[$input['id']])->fetch_assoc();echo json_encode(['id'=>(int)$u['id'],'hash'=>hash('sha256',serialize($u))]);",{'id':protected_fixture['users']['admin']['id']})
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
        assert_security_denied(member,password,uid)
        assert member.request('users.php')[0]==200,'Ordinary Admin access missing'
        assert member.request('accounts.php')[0]==403,'Demo has platform account-directory access'
        assert member.request('/a/shalom-in-messiah/dashboard.php')[0]==302,'Demo can enter the primary Account'
        assert member.request('/a/account-isolation-preview/dashboard.php')[0]==302,'Demo can enter another member Account'
        form=member.request('admin_elevation.php')
        assert 'name="admin_code"' not in form[2] or 'name="admin_code" value=""' in form[2],'Demo elevation still requires a factor'
        response=member.request('admin_elevation.php',{'admin_password':password,'admin_code':'','csrf_token':csrf(form[2])},{'Accept':'application/json'})
        assert response[0]==200 and json.loads(response[2])['unlocked'],'Password-only Admin elevation failed'
        users=member.request('users.php');assert 'TestAdmin' in users[2] and 'href="edit_user.php?id='+str(protected['id'])+'"' not in users[2], 'Other user displayed'
        assert 'href="account_settings.php"' not in users[2] and 'href="two_factor_settings.php"' not in users[2] and 'href="register.php"' not in users[2], 'Restricted navigation displayed'
        token=csrf(users[2])
        for route in ['account_settings.php','register.php','edit_user.php?id='+str(protected['id']),'profile_picture.php?id='+str(protected['id'])]:
            assert member.request(route)[0]==403, 'Restricted page accessible: '+route
            assert member.request(route,{'csrf_token':token,'action':'change_password','password':password,'new_password':password+'1','new_password_confirmation':password+'1'})[0]==403, 'Restricted POST accepted: '+route
        assert member.request('reset_user_password.php?id='+str(protected['id']))[0]==403,'Other password form accessible'
        bulk=member.request('bulk_delete.php',{'csrf_token':token,'action':'review','entity':'user','selected_ids[]':str(protected['id'])});assert bulk[0]==403, 'Bulk user access allowed'
        for route,data in [('reset_user_2fa.php',{'two_factor_confirmation':'RESET 2FA'}),('user_lifecycle.php',{'action':'deactivate'}),('delete_user.php',{'delete_confirmation':'DELETE USER'})]:
            member.request(route,dict(data,csrf_token=token,id=str(protected['id'])))
        unchanged=php("$u=$conn->execute_query('SELECT * FROM users WHERE id=?',[$input['id']])->fetch_assoc();echo json_encode(['hash'=>hash('sha256',serialize($u))]);",{'id':protected['id']})
        assert unchanged['hash']==protected['hash'],'Protected user changed'
        for name,role in [('TestEditor','editor'),('TestReviewer','reviewer')]:
            new=php("if($conn->execute_query('SELECT id FROM users WHERE username=?',[$input['name']])->num_rows)throw new RuntimeException('Existing demo role login must be preserved.'); $conn->execute_query(\"INSERT INTO users(username,password,role,account_status,is_superadmin,platform_identity_id,two_factor_enabled,must_change_password) VALUES(?,?,?,'active',0,NULL,0,0)\",[$input['name'],\\Dnr\\Security\\PasswordPolicy::hash($input['password']),$input['role']]);$id=(int)$conn->insert_id;registerAccountLogin($conn,$id,$input['name']);$p=json_decode($conn->query('SELECT settings FROM account_profile WHERE id=1')->fetch_assoc()['settings'],true);$p['demo_password_only_user_ids'][$input['name']]=$id;$conn->execute_query('UPDATE account_profile SET settings=? WHERE id=1',[json_encode($p)]);flushAccountDirectory();echo json_encode(['id'=>$id]);",{'name':name,'role':role,'password':password})
            demo_roles.append(new['id']);visitor=Client('http://localhost:8080/a/test-account');form=visitor.request('/login.php')
            login=visitor.request('/login.php',{'username':name,'password':password,'csrf_token':csrf(form[2])});assert login[0]==302
            signed=visitor.request(login[1]['Location']);assert signed[0]==302 and signed[1].get('Location')=='dashboard.php','Demo role required MFA'
            assert_security_denied(visitor,password,new['id'])
            assert visitor.request('dashboard.php')[0]==200 and visitor.request('users.php')[0]==403 and visitor.request('accounts.php')[0]==403,'Demo role gained Admin access'
            assert visitor.request('/a/shalom-in-messiah/dashboard.php')[0]==302,'Demo role entered primary account'
            if role=='reviewer': assert visitor.request('index.php')[0] in [302,403],'Reviewer gained edit access'
        other=fixture('moed-account-account-isolation-preview-web-1')
        ordinary=Client('http://localhost:8080/a/account-isolation-preview');ordinary.login(other['users']['admin'])
        assert ordinary.request('dashboard.php')[0]==200,'Other user MFA sign-in regressed'
        php("$conn->execute_query('UPDATE users SET two_factor_enabled=1,auth_version=auth_version+1 WHERE id=?',[$input['id']]);echo '{}';",{'id':uid})
        assert member.request('dashboard.php')[0]==302,'Voluntary MFA/version change did not revoke the demo session'
        print('PASS: password-only sign-in/elevation; restricted Users/navigation and GET/POST denial; protected user unchanged; cross-account denial; other-user MFA; session revocation.')
    finally:
        if other:cleanup('moed-account-account-isolation-preview-db-1',other)
        if protected_fixture:cleanup(DB,protected_fixture)
        for demo_id in demo_roles:
            php("removeAccountLogin($conn,$input['id']);flushAccountDirectory();$conn->execute_query('DELETE FROM account_login_handoffs WHERE user_id=?',[$input['id']]);$conn->execute_query('DELETE FROM users WHERE id=?',[$input['id']]);echo '{}';",{'id':demo_id})
        if uid:
            php("$conn->begin_transaction(); $conn->execute_query('UPDATE account_profile SET settings=? WHERE id=1',[$input['settings']]); removeAccountLogin($conn,$input['id']); $conn->commit();flushAccountDirectory(); $conn->execute_query('DELETE FROM account_login_handoffs WHERE user_id=?',[$input['id']]); $conn->execute_query(\"DELETE FROM users WHERE id=? AND username='TestAdmin'\",[$input['id']]);echo '{}';",{'id':uid,'settings':created['settings']})


if __name__=='__main__':main()
