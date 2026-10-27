"""Discover every MOED account from the primary directory; never silently skip one."""
import json
from pathlib import Path
import re
import subprocess
from online_recovery_backup import inspect
from deployment_backup import query


class AccountCoverageError(ValueError):
    pass


def resolve_service(project, service):
    ids = subprocess.check_output(['docker', 'ps', '-q', '--filter', 'label=com.docker.compose.project=' + project,
        '--filter', 'label=com.docker.compose.service=' + service, '--filter', 'label=com.docker.compose.oneoff=False'], text=True).split()
    if len(ids) != 1:
        raise AccountCoverageError('Expected exactly one running ' + service + ' service')
    return ids[0]


def account_directory(root, project):
    primary = inspect(resolve_service(project, 'web'))
    env = dict(value.split('=', 1) for value in primary['Config']['Env'])
    primary_key = env.get('DNR_ACCOUNT_KEY') or 'primary'
    rows = []
    prefix = project + '-account'
    if env.get('DNR_ACCOUNTS_ENABLED') == '1':
        if env.get('DNR_ACCOUNT_MODE') != 'primary':
            raise AccountCoverageError('Account discovery requires the primary deployment')
        config = json.loads((root / 'var/deployment/account-production/config.json').read_text())
        if config['primary_key'] != primary_key:
            raise AccountCoverageError('Primary account configuration differs from the running deployment')
        prefix = config['project_prefix']
        # Only a directory SELECT, using the running primary's existing credentials.
        php = "require '/var/www/html/bootstrap.php'; echo json_encode($conn->query(\"SELECT account_key,state FROM platform_accounts WHERE state <> 'deleted'\")->fetch_all(MYSQLI_ASSOC), JSON_THROW_ON_ERROR);"
        rows = json.loads(subprocess.check_output(['docker', 'exec', primary['Id'], 'php', '-r', php], text=True, timeout=30))
    if not re.fullmatch(r'[a-z0-9][a-z0-9-]*', prefix):
        raise AccountCoverageError('Unsafe account project prefix')
    accounts = {primary_key: dict(key=primary_key, project=project, primary=True, state='ready')}
    for row in rows:
        key = row['account_key']
        if key == primary_key:
            if row['state'] != 'ready':
                raise AccountCoverageError('Primary account is not ready')
            continue
        if key in accounts:
            raise AccountCoverageError('Duplicate account directory entry')
        accounts[key] = dict(key=key, project=prefix + '-' + key, primary=False, state=row['state'])
    for key, account in accounts.items():
        if not re.fullmatch(r'[a-z0-9][a-z0-9-]{0,62}', key):
            raise AccountCoverageError('Unsafe account identity')
        if account['state'] != 'ready':
            # Retain its previous copies and flag incomplete coverage rather than
            # starting a disabled account or claiming it was included.
            raise AccountCoverageError('An account is not online and ready for backup')
    return [accounts[key] for key in sorted(accounts)]


def resolve_account(account, root, version):
    account = dict(account)
    account['db'] = resolve_service(account['project'], 'db')
    account['web'] = resolve_service(account['project'], 'web')
    web = inspect(account['web'])
    env = dict(value.split('=', 1) for value in web['Config']['Env'])
    if env.get('DNR_ACCOUNT_KEY', 'primary') != account['key'] or query(account['db'], 'SELECT account_key FROM dnr.account_profile WHERE id=1') != account['key']:
        raise AccountCoverageError('Database and deployment account identities differ')
    if subprocess.check_output(['docker', 'exec', account['web'], 'cat', '/opt/dnr/VERSION'], text=True).strip() != version:
        raise AccountCoverageError('Account is not on the qualified release')
    mounts = {m['Destination']: m['Source'] for m in web['Mounts'] if m['Type'] == 'bind'}
    ids = subprocess.check_output(['docker', 'ps', '-aq', '--filter',
        'label=com.docker.compose.project=' + account['project']], text=True).split()
    containers = json.loads(subprocess.check_output(['docker', 'inspect', *ids]))
    required = sorted({m['Source'] for container in containers for m in container['Mounts']
        if m['Type'] == 'bind' and m['Destination'].startswith(('/run/secrets/', '/run/dnr/config/'))})
    if not account['primary']:
        directory = root / 'var/accounts' / account['key']
        required += [str(directory / name) for name in ('deployment.json', 'runtime.compose.json')]
        if (directory / 'account.env').is_file():
            required.append(str(directory / 'account.env'))
    account['required_files'] = required
    account['runtime_files'] = {name: str(Path(mounts[env[name]]).relative_to(root)) for name in
        ('DNR_2FA_ENCRYPTION_KEY_FILE', 'DNR_INBOUND_ROUTING_KEY_FILE', 'DNR_CONFIG_FILE') if env.get(name)}
    account['image'] = web['Image']
    return account
