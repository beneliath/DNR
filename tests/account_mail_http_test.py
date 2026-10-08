"""Synthetic mail isolation checks for the explicitly selected localhost preview.

No IMAP or SMTP connection is made. Only nonce-tagged fixtures are removed.
Stop the local provisioner first; this test holds its delivery/creation lock.
"""
import argparse
import base64
import fcntl
import hashlib
import json
import re
import secrets
import subprocess

from account_isolation_http_test import ROOT, Client, csrf, fixture, cleanup, preview_administrator

PROJECTS = {'shalom-in-messiah': 'dnr', 'test-account': 'moed-account-test-account',
            'account-isolation-preview': 'moed-account-account-isolation-preview'}


def php(project, code, worker=False):
    prefix = ''
    if worker:
        # Use the actual restricted ingest principal, without putting its secret
        # in command arguments, files, or test output.
        password = subprocess.run(['docker', 'exec', project + '-db-1', 'cat',
            '/run/secrets/dnr_mysql_mail_ingest_password'], capture_output=True, check=True).stdout.strip()
        encoded = base64.b64encode(password).decode()
        prefix = "putenv('MYSQL_PASSWORD_FILE'); putenv('MYSQL_USER=dnrmailingest'); putenv('MYSQL_PASSWORD='.base64_decode('" + encoded + "')); "
    result = subprocess.run(['docker', 'exec', '-i', project + '-web-1', 'php'],
        input="<?php " + prefix + "require '/var/www/html/bootstrap.php'; require_once '/var/www/html/two_factor_helpers.php'; require '/var/www/html/inbound_ingestion_helpers.php'; " + code,
        text=True, capture_output=True)
    if result.returncode:
        raise RuntimeError((result.stderr + result.stdout)[:1800])
    return json.loads(result.stdout)


def sql(project, statement):
    result = subprocess.run(['docker', 'exec', '-i', project + '-db-1', 'sh', '-c',
        'MYSQL_PWD=$(cat /run/secrets/dnr_mysql_root_password) mysql -N -B -uroot dnr'],
        input=statement, text=True, capture_output=True, check=True)
    return result.stdout.strip()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--local-preview', action='store_true', required=True)
    parser.parse_args()
    lock = (ROOT/'var/deployment/account-provisioner.lock').open('a')
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    nonce = secrets.token_hex(8)
    marker = 'mail-isolation-' + nonce
    mailbox = hashlib.sha256(marker.encode()).hexdigest()
    fixtures = {}; messages = []
    try:
        for key, project in PROJECTS.items():
            assert php(project, 'echo json_encode(accountMailEnabled());') is True
            fixtures[key] = fixture(project + '-web-1')
        tokens = {key: php(project, "echo json_encode(applicationInboundMarker(2000000000));")
                  for key, project in PROJECTS.items()}
        cases = [(key, token) for key, token in tokens.items()]
        cases += [(None, ''), (None, tokens['test-account'] + ' ' + tokens['shalom-in-messiah']),
                  (None, tokens['test-account'].replace('#E2000000000.', '#E1999999999.')),
                  (None, tokens['test-account'] + ' [MOED@unfinished')]
        legacy = php('dnr', "$p=deploymentConfig()->string('inbound_email.emitted_marker_prefix'); echo json_encode('['.$p.'#2000000000.'.applicationInboundMarkerTag(2000000000,$p).']');")
        cases.append(('shalom-in-messiah', legacy))
        for index, (key, token) in enumerate(cases):
            subject = marker + '-' + str(index)
            raw = ('From: Fixture <mail-test@example.invalid>\r\nTo: moed@beneliath.com\r\n'
                   'Message-ID: <' + subject + '@example.invalid>\r\nSubject: ' + subject + ' ' + token +
                   '\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n' + subject + ' private body <script>alert(1)</script>')
            encoded = base64.b64encode(raw.encode()).decode()
            code = "$m=parseInboundEmail(base64_decode('" + encoded + "')); echo json_encode(receiveAccountInboundMail($conn,'file','" + subject + "',$m));"
            stored = php('dnr', code, worker=True)
            duplicate = php('dnr', code, worker=True)
            assert stored['id'] == duplicate['id'] and not duplicate['inserted'], 'Mail import was not idempotent'
            messages.append((stored['id'], key, subject))

        # A first scan/UID reset must not expose uncertain sender/subject in the
        # ordinary Account's legacy reconciliation table.
        raw = ('From: mail-test@example.invalid\r\nTo: moed@beneliath.com\r\nMessage-ID: <' + marker +
               '-scan@example.invalid>\r\nSubject: ' + marker + '-scan\r\n\r\nUnclassified private mail')
        encoded = base64.b64encode(raw.encode()).decode()
        php('dnr', """$box=new class(base64_decode('%s')) implements \\Dnr\\Infrastructure\\InboundMailbox {
            public function __construct(private string $raw) {} public function uidValidity(): int {return 1;}
            public function uidNext(): int {return 2;} public function uidsBetween(int $first,int $last): array {return $first<=1&&$last>=1?[1]:[];}
            public function fetchRawMessage(int $uid): string {return $this->raw;} public function abort(): void {}
        }; echo json_encode(syncInboundMailbox($conn,$box,'%s','%s'));""" % (encoded, mailbox, marker), worker=True)
        assert sql('dnr', "SELECT COUNT(*) FROM inbound_mail_reconciliation WHERE mailbox_key='" + mailbox + "'") == '0'
        bounded = php('dnr', 'echo json_encode(deliverPlatformInboundMail($conn,100,1));', worker=True)
        assert bounded == 1, 'A byte-limited delivery batch must make progress on one message only'
        php('dnr', 'echo json_encode(deliverPlatformInboundMail($conn,100));', worker=True)
        php('dnr', 'echo json_encode(deliverPlatformInboundMail($conn,100));', worker=True)
        for mid, key, subject in messages:
            for account, project in PROJECTS.items():
                count = sql(project, "SELECT COUNT(*) FROM inbound_email_messages WHERE rfc_message_id='" + subject + "@example.invalid'")
                assert count == ('1' if key == account else '0'), 'Mail crossed an Account boundary or was not delivered'
            state = sql('dnr', 'SELECT CONCAT(status,\':\',IF(payload IS NULL,\'cleared\',\'retained\')) FROM platform_inbound_mail WHERE id=' + str(mid))
            assert state == ('review:retained' if key is None else 'delivered:cleared'), state

        primary = Client('http://localhost:8080/a/shalom-in-messiah')
        superuser = fixtures['shalom-in-messiah']['users']['superadmin']
        primary.login(superuser)
        page = primary.request('mail_review.php')
        assert page[0] == 200 and marker in page[2] and 'href="mail_review.php"' in page[2]
        assert '<script>alert(1)</script>' not in page[2], 'Mail body was not escaped'
        assert 'Array to string conversion' not in page[2] and re.search(r'Page 1 of \d+ · \d+ messages', page[2]), 'Pagination was overwritten by the shared header'
        assert 'Destination Account' in page[2] and 'value="test-account"' in page[2]
        review_id = next(mid for mid,key,_ in messages if key is None)
        assert primary.request('mail_review.php', {'action':'route','id':review_id,'account_key':'test-account'})[0] == 400
        locked = primary.request('mail_review.php', {'action':'route','id':review_id,'account_key':'test-account','csrf_token':csrf(page[2])})
        assert locked[0] == 302 and 'admin_elevation.php' in locked[1]['Location']
        primary.elevate(superuser)
        blocked_delete = primary.request('mail_review.php', {'action':'delete','id':review_id,'csrf_token':csrf(primary.request('mail_review.php')[2])})
        assert blocked_delete[0] == 200 and 'Only rejected messages can be deleted.' in blocked_delete[2]
        # Even a valid token for a real record must await human filing after a manual assignment.
        token = php(PROJECTS['test-account'], 'echo json_encode(applicationInboundMarker('+str(fixtures['test-account']['engagement_id'])+'));')
        subject = marker + '-real-record'
        raw = 'From: mail-test@example.invalid\r\nTo: moed@beneliath.com\r\nMessage-ID: <'+subject+'@example.invalid>\r\nSubject: '+subject+' '+token+'\r\n\r\nReview this message manually.'
        encoded = base64.b64encode(raw.encode()).decode()
        stored = php('dnr', "$m=parseInboundEmail(base64_decode('"+encoded+"')); echo json_encode(receiveAccountInboundMail($conn,'file','"+subject+"',$m));", worker=True)
        messages.append((stored['id'], 'test-account', subject))
        rejected = primary.request('mail_review.php', {'action':'route','id':review_id,'account_key':'unavailable-account','csrf_token':csrf(primary.request('mail_review.php')[2])})
        assert rejected[0] == 200 and 'Choose an available Account.' in rejected[2]
        assert sql('dnr','SELECT status FROM platform_inbound_mail WHERE id='+str(review_id)) == 'review'
        manual_ids = []
        for index, destination in zip([3,4,5,8], ['test-account','shalom-in-messiah','account-isolation-preview','test-account']):
            mid, _, subject = messages[index]
            result = primary.request('mail_review.php', {'action':'route','id':mid,'account_key':destination,'csrf_token':csrf(primary.request('mail_review.php')[2])})
            assert result[0] == 303, 'SuperAdmin could not assign unclassified mail'
            # Rechecking must retain the signed manual decision even though its tokens remain invalid.
            result = primary.request('mail_review.php', {'action':'retry','id':mid,'csrf_token':csrf(primary.request('mail_review.php')[2])})
            assert result[0] == 303
            assert sql('dnr', 'SELECT CONCAT(status,\':\',account_key) FROM platform_inbound_mail WHERE id='+str(mid)) == 'pending:'+destination
            messages[index] = (mid, destination, subject)
            manual_ids.append(mid)

        # Simulate a lost response: the destination accepts, but the central queue still says pending.
        replay_id = manual_ids[0]
        php('dnr', "$r=$conn->query('SELECT payload FROM platform_inbound_mail WHERE id="+str(replay_id)+"')->fetch_assoc(); $p=json_decode($r['payload'],true); $a=$conn->query(\"SELECT * FROM platform_accounts WHERE account_key='test-account'\")->fetch_assoc(); echo json_encode(platformMemberCall($a,'deliver_mail',$p));")
        sql('dnr', "UPDATE platform_inbound_mail SET payload=JSON_SET(payload,'$.delivery_account_key','test-account') WHERE id="+str(replay_id))
        denied = primary.request('mail_review.php', {'action':'route','id':replay_id,'account_key':'shalom-in-messiah','csrf_token':csrf(primary.request('mail_review.php')[2])})
        assert denied[0] == 200 and 'Delivery has already been attempted.' in denied[2]
        for _ in range(2): php('dnr', 'echo json_encode(deliverPlatformInboundMail($conn,100));', worker=True)
        for mid, destination, subject in messages:
            if mid not in manual_ids: continue
            for key, project in PROJECTS.items():
                rows = sql(project, "SELECT CONCAT(status,':',review_reason) FROM inbound_email_messages WHERE rfc_message_id='"+subject+"@example.invalid'")
                assert rows == ('review:Manually routed to this Account. Review before filing.' if key == destination else ''), 'Manual assignment escaped its Account or auto-filed'
            assert sql('dnr', 'SELECT CONCAT(status,\':\',IF(payload IS NULL,\'cleared\',\'retained\')) FROM platform_inbound_mail WHERE id='+str(mid)) == 'delivered:cleared'

        review_id = messages[6][0]
        for action in ['retry', 'reject']:
            result = primary.request('mail_review.php', {'action':action,'id':review_id,'csrf_token':csrf(primary.request('mail_review.php')[2])})
            assert result[0] == 303, result[0]
        assert sql('dnr','SELECT status FROM platform_inbound_mail WHERE id='+str(review_id)) == 'rejected'
        rejected_page = primary.request('mail_review.php?status=rejected&id='+str(review_id))
        assert 'aria-label="Delete rejected message"' in rejected_page[2] and 'data-confirm="Permanently delete' in rejected_page[2]
        assert primary.request('mail_review.php', {'action':'delete','id':review_id})[0] == 400
        deleted = primary.request('mail_review.php', {'action':'delete','id':review_id,'csrf_token':csrf(rejected_page[2])})
        assert deleted[0] == 303
        assert sql('dnr', 'SELECT CONCAT(status,\':\',IF(payload IS NULL,\'cleared\',\'retained\')) FROM platform_inbound_mail WHERE id='+str(review_id)) == 'rejected:cleared'
        assert messages[6][2] not in primary.request('mail_review.php?status=rejected&id='+str(review_id))[2], 'Deleted message still visible'
        duplicate = php('dnr', "$m=parseInboundEmail(\"From: Fixture <mail-test@example.invalid>\\r\\nTo: moed@beneliath.com\\r\\nMessage-ID: <"+messages[6][2]+"@example.invalid>\\r\\nSubject: replay\\r\\n\\r\\nreplay\"); echo json_encode(receiveAccountInboundMail($conn,'file','"+messages[6][2]+"',$m));", worker=True)
        assert duplicate['id'] == review_id and not duplicate['inserted'], 'Deleted mail reappeared on import'
        assert sql('dnr', 'SELECT payload IS NULL FROM platform_inbound_mail WHERE id='+str(review_id)) == '1'
        preview_administrator(primary)
        primary.request('role_preview.php', {'csrf_token':csrf(primary.request('accounts.php')[2]), 'role':'admin'})
        assert primary.request('mail_review.php', {'action':'route','id':review_id,'account_key':'test-account'})[0] == 403, 'Admin preview retained SuperAdmin mail authority'
        for key, data in fixtures.items():
            for role in ['admin','editor','reviewer']:
                client = Client('http://localhost:8080/a/' + key); client.login(data['users'][role])
                assert client.request('mail_review.php?id='+str(review_id))[0] == 403, 'Ordinary user accessed platform mail'
                assert client.request('mail_review.php', {'action':'route','id':review_id,'account_key':'test-account'})[0] == 403, 'Ordinary user could route platform mail'
                assert client.request('mail_review.php', {'action':'delete','id':review_id})[0] == 403, 'Ordinary user could delete platform mail'
                dashboard = client.request('dashboard.php')[2]
                assert 'href="mail_review.php"' not in dashboard
                if role != 'reviewer':
                    inbox = client.request('inbound_mail.php')[2]
                    for _, target, subject in messages:
                        if target != key: assert subject not in inbox, 'Inbox disclosed mail outside its Account'
        print('PASS: three Accounts; token isolation; first-scan privacy; SuperAdmin manual assignment into review only; lost-response retry/deduplication; reassignment blocked after delivery begins; payload cleanup; role/CSRF/unlock enforcement; retry/reject/delete; deleted-message reimport prevention; pagination rendering.')
    finally:
        for project in PROJECTS.values():
            sql(project,"DELETE FROM inbound_email_messages WHERE rfc_message_id LIKE '"+marker+"%@example.invalid';")
        sql('dnr', "DELETE r FROM inbound_email_import_receipts r JOIN platform_inbound_mail m ON m.deduplication_hash=r.deduplication_hash WHERE m.transport_key LIKE '"+marker+"%' OR m.transport_key LIKE '"+mailbox+":%'; DELETE FROM platform_inbound_mail WHERE transport_key LIKE '"+marker+"%' OR transport_key LIKE '"+mailbox+":%'; DELETE FROM inbound_mail_reconciliation WHERE mailbox_key='"+mailbox+"'; DELETE FROM inbound_mailbox_state WHERE mailbox_key='"+mailbox+"';")
        for key,data in fixtures.items(): cleanup(PROJECTS[key]+'-db-1',data)
        lock.close()


if __name__ == '__main__': main()
