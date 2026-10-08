"""Local synthetic invitation: legacy root entry -> canonical form -> Account MFA."""
import argparse
import base64
import hashlib
import hmac
import re
import secrets
import struct
import time
import urllib.parse
from account_isolation_http_test import Client, csrf, fixture, cleanup
from account_mail_http_test import sql


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--local-preview',action='store_true',required=True)
    parser.parse_args()
    data=None
    try:
        data=fixture('dnr-web-1')
        # Test both a password-only invitation and an invited identity with MFA.
        for role,two_factor in [('editor',False),('reviewer',True)]:
            user=data['users'][role];token=secrets.token_urlsafe(32)
            user_id=int(user['id']);digest=hashlib.sha256(token.encode()).hexdigest()
            sql('dnr',f"UPDATE users SET account_status='invited',email='invite-{user_id}@example.invalid',two_factor_enabled={int(two_factor)} WHERE id={user_id}; "
                f"INSERT INTO user_email_tokens(user_id,purpose,email,auth_version,token_hash,expires_at) "
                f"SELECT id,'invitation',email,auth_version,UNHEX('{digest}'),DATE_ADD(UTC_TIMESTAMP(),INTERVAL 1 HOUR) FROM users WHERE id={user_id}")
            client=Client('http://localhost:8080')
            page=client.request('accept_invitation.php?token='+token)
            assert page[0]==200 and 'action="/a/shalom-in-messiah/accept_invitation.php"' in page[2],page[0]
            canonical='/a/shalom-in-messiah/accept_invitation.php'
            # Also cover a legacy form already open in a browser at rollout.
            destination=canonical if not two_factor else '/accept_invitation.php'
            response=client.request(destination,{'token':token,'csrf_token':csrf(page[2]),
                'password':user['password'],'password_confirmation':user['password']})
            expected='/a/shalom-in-messiah/'+('verify_2fa.php' if two_factor else 'setup_2fa.php')
            assert response[0]==302 and response[1].get('Location')==expected,(response[0],response[1].get('Location'))
            continuation=client.request(expected)
            assert continuation[0]==200 and ('authentication_code' in continuation[2] or 'totp_code' in continuation[2]),'MFA state lost'
            fields={'csrf_token':csrf(continuation[2])}
            if two_factor:
                fields['authentication_code']=user['codes'].pop(0)
            else:
                match=re.search(r'<code class="manual-secret">([A-Z2-7]+)</code>',continuation[2])
                assert match,'MFA enrollment secret missing'
                secret=match[1]
                key=base64.b32decode(secret+'='*((-len(secret))%8))
                digest=hmac.new(key,struct.pack('>Q',int(time.time())//30),hashlib.sha1).digest()
                offset=digest[-1]&15
                code=(struct.unpack('>I',digest[offset:offset+4])[0]&0x7fffffff)%1000000
                fields.update(action='confirm',authentication_code=f'{code:06d}')
            verified=client.request(expected,fields)
            target=urllib.parse.urljoin(expected,verified[1].get('Location',''))
            expected_target='/a/shalom-in-messiah/'+('dashboard.php' if two_factor else 'two_factor_recovery_codes.php')
            assert verified[0]==302 and target==expected_target,'MFA completion did not retain Account path'
            assert client.request(target)[0]==200,'MFA destination unavailable'
            assert client.request('/a/shalom-in-messiah/dashboard.php')[0]==200,'Invitation did not establish authenticated Account session'
        print('PASS: old root GET and POST invitations retain tokens/CSRF and complete canonical setup/verify MFA into the Account dashboard.')
    finally:
        cleanup('dnr-db-1',data)


if __name__=='__main__':main()
