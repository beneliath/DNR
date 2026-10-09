"""Verify dialog authentication against explicitly selected local Account previews."""
import argparse
import json
from account_isolation_http_test import Client, fixture, cleanup


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--local-preview', action='store_true', required=True)
    parser.parse_args()
    headers = {'Accept': 'application/json'}
    for project, key in [('dnr', 'shalom-in-messiah'), ('moed-account-test-account', 'test-account')]:
        data = fixture(project + '-web-1')
        try:
            base = 'http://localhost:8080/a/' + key
            assert Client(base).request('admin_elevation.php', headers=headers)[0] == 302
            for role in ['editor', 'reviewer']:
                client = Client(base); client.login(data['users'][role])
                assert client.request('admin_elevation.php', headers=headers)[0] == 403
            user = data['users']['admin']
            client = Client(base); client.login(user)
            other = Client(base); other.login(user)
            status, _, body = client.request('admin_elevation.php', headers=headers)
            state = json.loads(body)
            assert status == 200 and state['unlocked'] is False and state['csrf_token']
            fields = {'csrf_token': 'invalid', 'admin_password': user['password'], 'admin_code': user['codes'][0]}
            assert client.request('admin_elevation.php', fields, headers)[0] == 400
            fields['csrf_token'] = state['csrf_token']; fields['admin_password'] = 'incorrect'
            status, _, body = client.request('admin_elevation.php', fields, headers)
            assert status == 422 and json.loads(body)['unlocked'] is False and json.loads(body)['error']
            fields['admin_password'] = user['password']; fields['admin_code'] = user['codes'].pop(0)
            status, response_headers, body = client.request('admin_elevation.php', fields, headers)
            active = json.loads(body)
            assert status == 200 and 'Location' not in response_headers and active['unlocked'] is True
            assert active['csrf_token'] != state['csrf_token'] and active['expires_at'] > active['server_now']
            assert user['password'] not in body and fields['admin_code'] not in body
            assert json.loads(other.request('admin_unlock_status.php')[2])['unlocked'] is False
            assert client.request('admin_lock.php', {'csrf_token': active['csrf_token']}, headers)[0] == 200
            locked = json.loads(client.request('admin_elevation.php', headers=headers)[2])
            assert locked['unlocked'] is False
            fields['csrf_token'] = locked['csrf_token']
            assert client.request('admin_elevation.php', fields, headers)[0] == 422, 'Used recovery code must not unlock again'
            print('PASS: ' + key + ' dialog authentication, CSRF, role restrictions, token rotation, session isolation, lock, and recovery-code replay')
        finally:
            cleanup(project + '-db-1', data)


if __name__ == '__main__':
    main()
