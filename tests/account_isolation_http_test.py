"""Bounded HTTP verification of two explicitly selected localhost Account previews.

Creates uniquely tagged synthetic fixtures; cleanup SQL names only their exact
IDs and nonce. It never resets passwords, edits, or deletes pre-existing data.
"""
import argparse
import hashlib
import html
import http.cookiejar
import json
from pathlib import Path
import re
import secrets
import subprocess
import urllib.error
import urllib.parse
import urllib.request

ROOT = Path(__file__).resolve().parent.parent


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self, base):
        self.base = base
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(self.jar), NoRedirect())

    def request(self, route, fields=None, headers=None):
        data = urllib.parse.urlencode(fields).encode() if fields is not None else None
        url = urllib.parse.urljoin(self.base + '/', route)
        assert urllib.parse.urlsplit(url).netloc == urllib.parse.urlsplit(self.base).netloc, 'Test requests must stay on the common local origin'
        req = urllib.request.Request(url, data=data, headers=headers or {})
        try:
            response = self.opener.open(req, timeout=30)
        except urllib.error.HTTPError as error:
            response = error
        return response.status, response.headers, response.read().decode('utf8', errors='replace')

    def login(self, user):
        page = self.request('/login.php')
        result = self.request('/login.php', {'username': user['username'], 'password': user['password'], 'csrf_token': csrf(page[2])})
        if result[0] == 302 and '/account_login.php?ticket=' in result[1].get('Location', ''):
            ticket = result[1]['Location']
            result = self.request(ticket)
            assert self.request(ticket)[0] == 403, 'Password handoff ticket replayed'
        assert result[0] == 302 and 'verify_2fa.php' in result[1].get('Location', ''), f"Password sign-in should require MFA: {user['username']} returned {result[0]} {result[1].get('Location', '')}"
        form = self.request('verify_2fa.php')
        result = self.request('verify_2fa.php', {'csrf_token': csrf(form[2]), 'authentication_code': user['codes'].pop(0)})
        assert result[0] == 302 and 'dashboard.php' in result[1].get('Location', ''), 'MFA sign-in failed'

    def elevate(self, user):
        form = self.request('admin_elevation.php')
        result = self.request('admin_elevation.php', {'csrf_token': csrf(form[2]), 'admin_password': user['password'],
            'admin_code': user['codes'].pop(0), 'return': 'accounts.php'})
        assert result[0] == 302, 'Fresh-factor elevation failed'


def csrf(body):
    match = re.search(r'name="csrf_token"[^>]*value="([^"]+)"', body)
    assert match, 'CSRF field missing'
    return html.unescape(match[1])


def fixture(container):
    nonce = secrets.token_hex(5)
    result = subprocess.run(['docker', 'exec', '-i', '-e', 'DNR_ACCOUNT_PREVIEW_FIXTURE=1', container,
                             'php', '/dev/stdin', nonce], input=(ROOT/'tests/account_preview_fixture.php').read_text(),
                            text=True, capture_output=True, check=True)
    return json.loads(result.stdout)


def cleanup(db, data):
    if not data: return
    ids = ','.join(str(v['id']) for v in data['users'].values())
    org, engagement, presentation, speaker = [int(data[k]) for k in ('organization_id', 'engagement_id', 'presentation_id', 'speaker_id')]
    # Root is used only by the local test harness for cleanup of these exact
    # synthetic records, including speakers whose web role has no DELETE grant.
    sql = f"""DELETE FROM platform_access_tickets WHERE user_id IN ({ids});
DELETE FROM short_links WHERE presentation_id={presentation};
DELETE FROM presentation_notes WHERE presentation_id={presentation};
DELETE FROM presentations WHERE id={presentation};
DELETE FROM engagements WHERE id={engagement};
DELETE FROM organizations WHERE id={org} AND organization_name='AccountTest-{data['nonce']}';
DELETE FROM speakers WHERE id={speaker} AND name='AccountTest-{data['nonce']}';
DELETE FROM users WHERE id IN ({ids}) AND username LIKE 'isolation-{data['nonce']}-%';
"""
    subprocess.run(['docker', 'exec', '-i', db, 'sh', '-c',
                    'MYSQL_PWD=$(cat /run/secrets/dnr_mysql_root_password) mysql -uroot dnr'],
                   input=sql, text=True, check=True, capture_output=True)
    key = data.get('account_key', 'shalom-in-messiah')
    assert re.fullmatch(r'[a-z][a-z0-9-]{2,63}', key)
    subprocess.run(['docker', 'exec', '-i', 'dnr-db-1', 'sh', '-c',
                    'MYSQL_PWD=$(cat /run/secrets/dnr_mysql_root_password) mysql -uroot dnr'],
                   input=f"DELETE FROM platform_login_claims WHERE account_key='{key}' AND user_id IN ({ids}); DELETE FROM platform_login_reservations WHERE account_key='{key}' AND user_id IN ({ids}); DELETE FROM platform_login_routes WHERE account_key='{key}' AND user_id IN ({ids}) AND username LIKE 'isolation-{data['nonce']}-%';",
                   text=True, check=True, capture_output=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--local-preview', action='store_true', required=True)
    parser.add_argument('--primary-web', default='dnr-web-1')
    parser.add_argument('--member-web', default='moed-account-account-isolation-preview-web-1')
    parser.add_argument('--primary-port', type=int, default=8080)
    parser.add_argument('--primary-key', default='shalom-in-messiah')
    parser.add_argument('--member-key', default='account-isolation-preview')
    parser.add_argument('--keep-fixtures', action='store_true')
    args = parser.parse_args()
    left = right = None
    try:
        left = fixture(args.primary_web); right = fixture(args.member_web)
        fixture_path = ROOT/'var/deployment/account-test-fixtures.json'
        fixture_path.write_text(json.dumps({'primary': left, 'member': right}, indent=2)); fixture_path.chmod(0o600)
        primary_url = f'http://localhost:{args.primary_port}/a/{args.primary_key}'
        member_url = f'http://localhost:{args.primary_port}/a/{args.member_key}'
        pairs = [(left, right, primary_url), (right, left, member_url)]
        clients = {}
        for own, other, base in pairs:
            for role in ('admin', 'editor', 'reviewer'):
                client = Client(base); client.login(own['users'][role]); clients[base, role] = client
                dashboard = client.request('dashboard.php')
                assert dashboard[0] == 200 and 'Switch Account' not in dashboard[2] and 'account-identity-banner' not in dashboard[2], f'{role} should have no switcher'
                assert 'role-preview-control' not in dashboard[2], 'Ordinary users must not see Preview Access'
                assert 'href="network_diagnostics.php"' not in dashboard[2] and 'href="ai_coach_requests.php"' not in dashboard[2]
                for preview_role in ('superadmin', 'admin', 'editor', 'reviewer'):
                    forged = client.request('role_preview.php', {'csrf_token': csrf(dashboard[2]), 'role': preview_role})
                    assert forged[0] == 403, f'{role} could change Preview Access to {preview_role}'
                assert_superadmin_views_denied(client, csrf(dashboard[2]))
                assert client.request('accounts.php')[0] == 403, f'{role} reached platform directory'
                assert client.request('accounts.php', {'action': 'switch', 'account_key': 'shalom-in-messiah', 'csrf_token': csrf(dashboard[2])})[0] == 403
                for route in ('organizations.php', 'search.php?q=AccountTest', 'view_calendar.php'):
                    result = client.request(route)
                    assert result[0] == 200 and other['marker'] not in result[2], f'Cross-Account content in {route}'
                assert own['marker'] in client.request(f"view_organization.php?id={own['organization_id']}")[2]
                assert other['marker'] not in client.request(f"view_organization.php?id={other['organization_id']}")[2]
                assert other['marker'] not in client.request(f"presentation_asset.php?id={other['presentation_id']}&type=speaker_notes")[2]
                assert client.request('account_settings.php')[0] == (200 if role == 'admin' else 403)
                if role == 'admin':
                    users = client.request('users.php')
                    assert users[0] == 200 and other['users']['admin']['username'] not in users[2], 'User roster leaked'
                    settings = client.request('account_settings.php')
                    account_name = re.search(r'name="name"[^>]*value="([^"]+)"', settings[2])
                    setup = client.request('reimbursement_setup.php')
                    assert setup[0] == 200 and account_name, 'Reimbursement setup or Account name is unavailable'
                    assert account_name[1] in setup[2] and 'Edit in Account Settings' in setup[2], 'Reimbursement setup must use Account Settings'
                    assert 'name="organization_name"' not in setup[2], 'Reimbursement setup still has a separate organization name'
                    if base == primary_url:
                        assert client.request('database_maintenance.php')[0] == 403, 'Primary platform backup exposed'
                        assert client.request(f"edit_user.php?id={left['users']['superadmin']['id']}")[0] == 403, 'SuperAdmin identity editable by Account Admin'
                else:
                    assert client.request('users.php')[0] == 403
            anonymous = Client(base)
            assert anonymous.request('calendar.php?token=' + other['calendar']['token'])[0] == 404, 'Foreign calendar token resolved'
            own_calendar = anonymous.request('calendar.php?token=' + own['calendar']['token'])
            assert own_calendar[0] == 200 and own['marker'] in own_calendar[2] and other['marker'] not in own_calendar[2], 'Calendar escaped its owner Account'
            assert anonymous.request('short_link.php?code=' + other['short_code'])[0] == 404, 'Foreign shared link resolved'
            notes = anonymous.request('short_link.php?code=' + own['short_code'] + '&download=1')
            assert notes[0] == 200 and own['marker'] in notes[2] and other['marker'] not in notes[2], 'Shared notes escaped their owner Account'
            assert anonymous.request('presentation_asset.php?id=' + str(own['presentation_id']) + '&type=speaker_notes')[0] == 302
        # Primary session cookies copied to the other deployment cannot authenticate.
        copied = '; '.join(c.name + '=' + c.value for c in clients[primary_url, 'admin'].jar)
        assert Client(member_url).request('dashboard.php', headers={'Cookie': copied})[0] == 302
        superuser = left['users']['superadmin']
        admin = Client(primary_url); admin.login(superuser)
        directory = admin.request('accounts.php')
        assert directory[0] == 200 and 'Account Isolation Preview' in directory[2]
        preview_administrator(admin)
        directory = admin.request('accounts.php')
        assert admin.request('accounts.php', {'action': 'switch', 'account_key': args.member_key})[0] == 400, 'Switch accepted without CSRF'
        switched = admin.request('accounts.php', {'csrf_token': csrf(directory[2]), 'action': 'switch', 'account_key': args.member_key})
        target = switched[1].get('Location', '')
        assert switched[0] == 302 and target.startswith(member_url + '/account_signin.php?ticket=')
        member = Client(member_url)
        route = target
        assert member.request(route)[0] == 302, 'SuperAdmin handoff failed'
        assert member.request('dashboard.php')[0] == 200
        assert right['marker'] in member.request(f"view_organization.php?id={right['organization_id']}")[2]
        assert member.request(route)[0] == 403, 'Consumed handoff ticket replayed'
        preview_administrator(member)
        member.elevate(superuser)
        assert member.request('users.php')[0] == 200, 'SuperAdmin cannot administer member Account'
        # Revocation at the primary must invalidate the member on its next request.
        command = ['docker', 'exec', '-i', args.primary_web, 'php']
        subprocess.run(command, input="<?php require '/var/www/html/config.php'; $conn->query('UPDATE users SET auth_version=auth_version+1 WHERE id=" + str(superuser['id']) + "');", text=True, check=True, capture_output=True)
        assert member.request('dashboard.php')[0] == 403, 'Revoked platform session remained usable'
        print('PASS: both directions; Admin/Editor/Reviewer; rosters; list/search/calendar; record/download guesses; shared links; cookies; CSRF; ticket replay; SuperAdmin handoff/elevation/revocation.')
        if args.keep_fixtures:
            path = ROOT/'var/deployment/account-test-fixtures.json'
            path.write_text(json.dumps({'primary': left, 'member': right}, indent=2)); path.chmod(0o600)
    finally:
        if not args.keep_fixtures:
            if right and left:
                # The member's SuperAdmin actor refers to the primary fixture.
                primary_ids = ','.join(str(u['id']) for u in left['users'].values())
                subprocess.run(['docker', 'exec', args.member_web.replace('-web-', '-db-'), 'sh', '-c',
                    'MYSQL_PWD=$(cat /run/secrets/dnr_mysql_root_password) mysql -uroot dnr -e "DELETE FROM users WHERE platform_identity_id IN (' + primary_ids + ')"'], check=True, capture_output=True)
            cleanup(args.member_web.replace('-web-', '-db-'), right)
            cleanup(args.primary_web.replace('-web-', '-db-'), left)


def preview_administrator(client):
    page = client.request('accounts.php')
    assert '<option value="superadmin" selected>SuperAdmin</option>' in page[2]
    assert client.request('database_maintenance.php')[0] == 200, 'SuperAdmin Database Maintenance must load'
    assert 'href="network_diagnostics.php"' in page[2], 'SuperAdmin is missing Network navigation'
    for route in SUPERADMIN_VIEWS:
        allowed_statuses = (200, 404) if '?id=' in route or '?source=' in route else (200,)
        assert client.request(route)[0] in allowed_statuses, f'SuperAdmin cannot view {route}'
    assert client.request('role_preview.php', {'role': 'editor'})[0] == 400, 'Preview requires CSRF'
    for role, label, origin in [('admin', 'Administrator', 'network_diagnostics.php?days=7'),
                                ('editor', 'Editor', 'ai_coach_requests.php'),
                                ('reviewer', 'Reviewer', 'ai_coach_improvements.php')]:
        result = client.request('role_preview.php', {'csrf_token': csrf(page[2]), 'role': role, 'return_to': origin})
        assert result[0] == 302 and result[1]['Location'] == 'dashboard.php', 'Preview returned to a restricted view'
        preview = client.request('dashboard.php')
        assert preview[0] == 200 and f'Viewing as {label}' in preview[2]
        assert 'account-identity-banner' not in preview[2] and 'href="accounts.php"' not in preview[2]
        assert 'href="network_diagnostics.php"' not in preview[2] and 'href="ai_coach_requests.php"' not in preview[2]
        assert f'<option value="{role}" selected>{label}</option>' in preview[2]
        if role == 'admin':
            assert '<option value="superadmin"' not in preview[2], 'Administrator preview must not offer SuperAdmin in the dropdown'
            assert '>Return to SuperAdmin</button>' in preview[2], 'Administrator preview must retain its exit button'
        assert client.request('accounts.php')[0] == 403, 'Preview retained platform authority'
        assert_superadmin_views_denied(client, csrf(preview[2]))
        assert client.request('account_settings.php')[0] == (200 if role == 'admin' else 403)
        restored = client.request('role_preview.php', {'csrf_token': csrf(preview[2]), 'role': 'superadmin', 'return_to': origin})
        assert restored[0] == 302 and restored[1]['Location'] == origin, 'SuperAdmin restoration failed'
        assert client.request(origin)[0] == 200
        page = client.request('accounts.php')


SUPERADMIN_VIEWS = ('network_diagnostics.php', 'network_performance.php?days=7',
                    'ai_coach_requests.php', 'ai_coach_requests.php?id=1',
                    'ai_coach_improvements.php', 'ai_coach_improvements.php?source=1',
                    'ai_coach_improvements.php?export=1')


def assert_superadmin_views_denied(client, token):
    for route in SUPERADMIN_VIEWS:
        assert client.request(route)[0] == 403, f'Restricted view exposed: {route}'
    for route, action in [('network_diagnostics.php', 'reset_statistics'),
                          ('ai_coach_requests.php', 'prepare_clear'),
                          ('ai_coach_requests.php', 'review'),
                          ('ai_coach_improvements.php', 'save')]:
        assert client.request(route, {'csrf_token': token, 'action': action})[0] == 403, f'Restricted write exposed: {route}'
    sample = dict.fromkeys(('ttfb_ms', 'dom_content_loaded_ms', 'load_ms', 'image_total_ms', 'image_max_ms',
                            'contact_image_total_ms', 'contact_image_max_ms', 'image_count', 'contact_image_count'), '0')
    sample.update(csrf_token=token, page_path='dashboard.php')
    assert client.request('network_performance.php', sample)[0] == 204, 'Signed-in telemetry collection was blocked'


if __name__ == '__main__': main()
