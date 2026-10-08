"""Synthetic localhost regressions for audit privacy, directory commits, recovery and mail size."""
import argparse
import fcntl
import json
import secrets
import subprocess
import time
from account_isolation_http_test import ROOT, Client, csrf, fixture, cleanup
from account_mail_http_test import php, sql


def sync():
    php('dnr', "$a=$conn->query(\"SELECT * FROM platform_accounts WHERE account_key='test-account'\")->fetch_assoc(); reconcileAccountDirectory($conn,$a); echo '{}';")


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--local-preview',action='store_true',required=True);parser.parse_args()
    lock=(ROOT/'var/deployment/account-provisioner.lock').open('a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    left=right=None; marker='consistency'+secrets.token_hex(6); audit_ids=[]; original_profile=None
    project='moed-account-test-account'
    try:
        left=fixture('dnr-web-1');right=fixture(project+'-web-1')
        ordinary=Client('http://localhost:8080/a/shalom-in-messiah');ordinary.login(left['users']['admin'])
        superadmin=Client(ordinary.base);superadmin.login(left['users']['superadmin'])
        for event in ['platform_account_archived','platform_mail_route']:
            audit_ids.append(php('dnr',f"recordAuditEvent($conn,['event_category'=>'security','event_type'=>'{event}','entity_label'=>'{marker}','details'=>'Private foreign Account']); echo json_encode($conn->insert_id);"))
        page=ordinary.request('audit_log.php?q='+marker+'&retention_days=1')
        assert page[0]==200 and 'Private foreign Account' not in page[2] and 'Prune Old Entries' not in page[2]
        assert 'Private foreign Account' in superadmin.request('audit_log.php?q='+marker)[2]
        assert ordinary.request('audit_log.php',{'csrf_token':csrf(ordinary.request('dashboard.php')[2]),'action':'prune','retention_days':'1','prune_confirmation':'PRUNE'})[0]==403

        user=right['users']['editor'];uid=user['id'];old=user['username'];new=old+'-new'
        stage=f"$conn->begin_transaction(); $conn->execute_query('UPDATE users SET username=? WHERE id=?',['{new}',{uid}]); registerAccountLogin($conn,{uid},'{new}');"
        php(project,stage+"$conn->rollback(); echo '{}';")
        assert sql('dnr',f"SELECT username FROM platform_login_routes WHERE account_key='test-account' AND user_id={uid}")==old
        sync()
        assert sql('dnr',f"SELECT COUNT(*) FROM platform_login_reservations WHERE account_key='test-account' AND user_id={uid}")=='0'
        assert sql('dnr',f"SELECT username FROM platform_login_routes WHERE account_key='test-account' AND user_id={uid}")==old
        # A lost response after local commit is recovered without caller help.
        php(project,stage+"$conn->commit(); echo '{}';")
        assert sql('dnr',f"SELECT username FROM platform_login_routes WHERE account_key='test-account' AND user_id={uid}")==old
        # Reservations exclude every other Account, including the primary.
        blocked=php('dnr',f"try {{$conn->begin_transaction(); platformRegisterLogin($conn,'shalom-in-messiah',{left['users']['editor']['id']},'{new}'); echo 'false';}} catch(Throwable $e) {{echo 'true';}} finally {{$conn->rollback();}}")
        assert blocked is True
        sync();sync()
        assert sql('dnr',f"SELECT username FROM platform_login_routes WHERE account_key='test-account' AND user_id={uid}")==new
        user['username']=new
        # While a transaction is undecided the reservation must remain held.
        renamed=old+'-slow'
        code=f"<?php require '/var/www/html/bootstrap.php'; $conn->begin_transaction(); $conn->execute_query('UPDATE users SET username=? WHERE id=?',['{renamed}',{uid}]); registerAccountLogin($conn,{uid},'{renamed}'); echo \"reserved\\n\"; flush(); sleep(6); $conn->rollback();"
        process=subprocess.Popen(['docker','exec','-i',project+'-web-1','php'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        process.stdin.write(code);process.stdin.close()
        assert process.stdout.readline().strip()=='reserved'
        try: sync()
        except RuntimeError: pass
        assert sql('dnr',f"SELECT username FROM platform_login_routes WHERE account_key='test-account' AND user_id={uid}")==new
        assert sql('dnr',f"SELECT COUNT(*) FROM platform_login_reservations WHERE account_key='test-account' AND user_id={uid}")=='1'
        assert process.wait(timeout=15)==0
        sync()

        # Deletion rollback must retain the old username and claim.
        php(project,f"$conn->begin_transaction(); $conn->execute_query('DELETE FROM users WHERE id=?',[{uid}]); removeAccountLogin($conn,{uid}); $conn->rollback(); echo '{{}}';")
        sync()
        assert sql('dnr',f"SELECT username FROM platform_login_routes WHERE account_key='test-account' AND user_id={uid}")==new
        # Actual concurrent attempts in separate primary/member transactions.
        primary_uid=left['users']['editor']['id'];race=left['users']['editor']['username']+'-race'
        code=f"<?php require '/var/www/html/bootstrap.php'; $conn->begin_transaction(); $conn->execute_query('UPDATE users SET username=? WHERE id=?',['{race}',{primary_uid}]); registerAccountLogin($conn,{primary_uid},'{race}'); echo \"claimed\\n\"; flush(); sleep(2); $conn->commit();"
        concurrent=subprocess.Popen(['docker','exec','-i','dnr-web-1','php'],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
        concurrent.stdin.write(code);concurrent.stdin.close()
        assert concurrent.stdout.readline().strip()=='claimed'
        collision=php(project,f"$conn->begin_transaction();try {{$conn->execute_query('UPDATE users SET username=? WHERE id=?',['{race}',{uid}]); registerAccountLogin($conn,{uid},'{race}');echo 'false';}} catch(Throwable $e){{echo 'true';}} finally{{$conn->rollback();}}")
        assert collision is True and concurrent.wait(timeout=15)==0
        sync()
        assert sql('dnr',f"SELECT account_key FROM platform_login_claims WHERE username='{race}'")=='shalom-in-messiah'

        # Recovery from common login targets only the matching verified member.
        email=marker+'@example.invalid'
        sql(project,f"UPDATE users SET email='{email}',email_verified_at=UTC_TIMESTAMP() WHERE id={uid}")
        visitor=Client('http://localhost:8080')
        def request_recovery(username,address):
            form=visitor.request('recover_password.php')
            return visitor.request('recover_password.php',{'csrf_token':csrf(form[2]),'action':'request','username':username,'email':address})
        assert request_recovery(new,email)[0]==200
        php('dnr',"require '/var/www/html/account_recovery_helpers.php'; processSharedPasswordRecovery($conn); echo '{}';")
        assert sql(project,f"SELECT COUNT(*) FROM user_email_tokens WHERE user_id={uid} AND purpose='recovery'")=='1'
        assert sql('dnr',f"SELECT COUNT(*) FROM user_email_tokens WHERE email='{email}'")=='0'
        # Replay the delivery ID after a simulated lost response: no second mail.
        operation=sql(project,'SELECT operation_id FROM account_recovery_receipts ORDER BY created_at DESC LIMIT 1')
        php(project,"require '/var/www/html/account_recovery_helpers.php'; echo json_encode(acceptSharedPasswordRecovery($conn,"+"['operation_id'=>'"+operation+f"','user_id'=>{uid},'username'=>'{new}','email'=>'{email}']));")
        assert sql(project,f"SELECT COUNT(*) FROM user_email_tokens WHERE user_id={uid} AND purpose='recovery'")=='1'
        assert request_recovery(marker+'-missing',email)[0]==200
        php('dnr',"require '/var/www/html/account_recovery_helpers.php'; processSharedPasswordRecovery($conn); echo '{}';")
        assert sql(project,f"SELECT COUNT(*) FROM user_email_tokens WHERE user_id={uid} AND purpose='recovery'")=='1'

        # Name synchronization is replayable and ignores stale profile versions.
        original_profile=php(project,"echo json_encode($conn->query('SELECT name,version FROM account_profile WHERE id=1')->fetch_assoc());")
        php(project,f"$conn->execute_query('UPDATE account_profile SET name=?,version=version+1 WHERE id=1',['{marker}']); echo '{{}}';")
        sync();sync()
        assert sql('dnr',"SELECT name FROM platform_accounts WHERE account_key='test-account'")==marker
        assert php(project,"try {platformCall('rename_account',['account_key'=>'shalom-in-messiah','name'=>'Forbidden']);echo 'false';} catch(Throwable $e){echo 'true';}") is True

        # Real signed HTTP delivery above the old 2 MiB limit, with JSON escaping.
        token=php(project,'echo json_encode(applicationInboundMarker(2000000000));')
        import base64
        headers=('From: Test <size@example.invalid>\r\nTo: moed@beneliath.com\r\nMessage-ID: <'+marker+'@example.invalid>\r\nSubject: '+token+'\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n')
        encoded=base64.b64encode(headers.encode()).decode()
        payload=php('dnr', "putenv('DNR_INBOUND_MAX_BYTES=16777215'); $m=parseInboundEmail(base64_decode('"+encoded+"').str_repeat(chr(34),12*1024*1024)); $a=$conn->query(\"SELECT * FROM platform_accounts WHERE account_key='test-account'\")->fetch_assoc(); echo json_encode(platformMemberCall($a,'deliver_mail',['message'=>accountMailPack($m),'gateway'=>'moed@beneliath.com']));")
        assert payload.get('ok') is True
        assert int(sql(project,f"SELECT OCTET_LENGTH(body_text) FROM inbound_email_messages WHERE rfc_message_id='{marker}@example.invalid'"))>2097152
        assert sql('dnr',f"SELECT COUNT(*) FROM inbound_email_messages WHERE rfc_message_id='{marker}@example.invalid'")=='0'
        print('PASS: audit privacy/prune denial; rollback/commit/lost-response directory reconciliation; global reservation exclusion; in-flight commit protection; common recovery/replay; name synchronization; large signed member delivery.')
    finally:
        if original_profile:
            encoded=json.dumps(original_profile['name'])
            php(project,"$conn->execute_query('UPDATE account_profile SET name=?,version=version+1 WHERE id=1',["+encoded+"]); echo '{}';");sync()
        sql(project,f"DELETE FROM inbound_email_messages WHERE rfc_message_id='{marker}@example.invalid'")
        if audit_ids:sql('dnr','DELETE FROM security_audit_log WHERE id IN ('+','.join(map(str,audit_ids))+')')
        cleanup('dnr-db-1',left);cleanup(project+'-db-1',right)


if __name__=='__main__':main()
