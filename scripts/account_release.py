"""Upgrade active member Accounts inside the primary's guarded release transaction."""
import copy
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import sys

from deployment_backup import create_verified_backup, query, sha


def protected_identity(project):
    profile = query(project + '-db-1', 'SELECT * FROM dnr.account_profile WHERE id=1')
    users = query(project + '-db-1', 'SELECT id,username,password,role,email,account_status,two_factor_enabled,'
        'totp_secret_encrypted,auth_version,is_superadmin,platform_identity_id FROM dnr.users ORDER BY id')
    return hashlib.sha256((profile + '\n' + users).encode()).hexdigest()


def discover_members(root, config):
    sys.path.insert(0, str(root / 'scripts'))
    import account_production as production
    from account_lifecycle_local import inspect
    members = []
    for account in production.control(config['primary_web'], 'directory'):
        key = account['account_key']
        if account['state'] != 'ready' or key == config['primary_key']:
            continue
        if not production.re.fullmatch(r'[a-z][a-z0-9-]{2,63}', key):
            raise ValueError('Invalid active Account identity')
        directory = root / 'var/accounts' / key
        if directory.is_symlink():
            raise ValueError('Unsafe Account release directory')
        metadata = json.loads((directory / 'deployment.json').read_text())
        project = config['project_prefix'] + '-' + key
        if metadata.get('mode') != 'production' or metadata.get('project') != project:
            raise ValueError('Active Account deployment ownership mismatch')
        command = production.saved_compose(directory, project, metadata)
        document = json.loads((directory / 'runtime.compose.json').read_text())
        if query(project + '-db-1', 'SELECT account_key FROM dnr.account_profile WHERE id=1') != key:
            raise ValueError('Account database identity mismatch')
        previous_version = subprocess.check_output(['docker', 'exec', project + '-web-1',
            'cat', '/opt/dnr/VERSION'], text=True).strip()
        members.append(dict(key=key, directory=directory, project=project, metadata=metadata,
            document=document, command=command, active=production.validate_active_services(document),
            previous_version=previous_version, previous_database=inspect(project + '-db-1')['Image']))
    return members


def compose(member, *arguments):
    from provision_local_account import clean_environment
    return subprocess.check_output(member['command'] + list(arguments),
        env=clean_environment(), text=True).strip()


def pause_members(members):
    for member in members:
        compose(member, 'stop', *member['active'])


def backup_members(members, app_image, password_file, records):
    from account_lifecycle_local import backup_configuration
    receipts = []
    for member in members:
        member['protected_identity'] = protected_identity(member['project'])
        receipt = create_verified_backup(member['project'] + '-db-1', member['previous_database'],
            app_image, member['metadata']['commit'], member['previous_version'], password_file,
            records / 'accounts' / member['key'] / 'backups')
        receipt['configuration'] = backup_configuration(member['directory'], receipt, app_image, Path(password_file))
        member['backup'] = receipt
        from account_production import private_file
        private_file(records / 'accounts' / member['key'] / 'receipt.json', json.dumps(receipt, indent=2))
        receipts.append(dict(account_key=member['key'], previous_commit=member['metadata']['commit'], backup=receipt))
    return receipts


def stage_runtime(root, directory, document, release, timestamp):
    """Preserve topology/settings; refresh qualified code binds into a new frozen tree."""
    from account_production import configuration_digest
    updated = copy.deepcopy(document)
    original_images = {kind:document['services'][service]['image'] for kind, service in
        [('app', 'web'), ('database', 'db'), ('ingress', 'ingress')]}
    replacements = {value:release['images'][kind] for kind, value in original_images.items()}
    code_binds = {'/opt/dnr/migrations': root / 'migrations',
        '/opt/dnr/bin/migrate': root / 'scripts/migrate.sh',
        '/opt/dnr/bin/configure_database_privileges': root / 'scripts/configure_database_privileges.sh'}
    manifest = {}
    sources = {}
    qualified_sources = {volume['source']: code_binds[volume['target']]
        for service in document['services'].values() for volume in service.get('volumes', [])
        if volume['type'] == 'bind' and volume['target'] in code_binds}
    for relative in document['x-moed-frozen-configuration']:
        original = directory / relative
        source = qualified_sources.get(str(original), original)
        target = directory / 'runtime-config' / ('release-' + release['commit']) / Path(relative).name
        if target.exists():
            if configuration_digest(target) != configuration_digest(source):
                raise ValueError('Existing Account release staging differs from the qualified configuration')
        else:
            target.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
            if source.is_dir(): shutil.copytree(source, target)
            else: shutil.copy2(source, target)
        sources[str(original)] = target
    for service in updated['services'].values():
        service.pop('build', None)
        if service.get('image') in replacements:
            service['image'] = replacements[service['image']]
            environment = service.setdefault('environment', {})
            environment.update(DNR_BUILD_COMMIT=release['commit'], DNR_BUILD_TIMESTAMP=timestamp)
        for volume in service.get('volumes', []):
            if volume['type'] != 'bind' or volume['source'] not in sources:
                continue
            target = sources[volume['source']]
            volume['source'] = str(target)
    for target in sources.values():
        manifest[str(target.relative_to(directory))] = configuration_digest(target)
    updated['x-moed-frozen-configuration'] = manifest
    return updated


def apply_members(root, members, release, timestamp):
    from account_production import private_file, qualify_runtime
    for member in members:
        if not member.get('backup', {}).get('restore_verified'):
            raise ValueError('A fresh restore-verified Account backup is required')
        document = stage_runtime(root, member['directory'], member['document'], release, timestamp)
        metadata = {**member['metadata'], 'commit': release['commit']}
        qualify_runtime(member['directory'], document, metadata)
        private_file(member['directory'] / 'runtime.compose.json', json.dumps(document, indent=2))
        metadata['runtime_sha256'] = sha(member['directory'] / 'runtime.compose.json')
        private_file(member['directory'] / 'deployment.json', json.dumps(metadata, indent=2))
        member['document'] = document
        member['metadata'] = metadata
        compose(member, 'up', '-d', '--no-build', '--no-deps', '--wait', 'db')
        compose(member, 'run', '--rm', '--no-deps', 'migrator')
        compose(member, 'run', '--rm', '--no-deps', 'file-migrator')
        if protected_identity(member['project']) != member['protected_identity']:
            raise ValueError('Account settings or authentication identities changed during release')


def start_and_verify_members(members, config, release):
    from account_production import control
    from account_lifecycle_local import inspect
    results = []
    for member in members:
        compose(member, 'up', '-d', '--no-build', '--no-deps', '--wait', '--wait-timeout', '180', *member['active'])
        for service in ['db', *member['active']]:
            image = member['document']['services'][service]['image']
            expected_id = inspect(image)['Id']
            identifiers = compose(member, 'ps', '-aq', service).splitlines()
            if not identifiers: raise ValueError('Account service did not start: ' + service)
            for identifier in identifiers:
                state = inspect(identifier)
                if state['Image'] != expected_id or state['State'].get('Health', {}).get('Status') != 'healthy':
                    raise ValueError('Account service failed release/image verification: ' + service)
        url = member['document']['services']['web']['environment']['DNR_PUBLIC_BASE_URL']
        control(config['primary_web'], 'probe', member['key'], url, release['commit'])
        ready = json.loads(subprocess.check_output(['curl', '--fail', '--silent', '--show-error',
            '--max-time', '20', url.rstrip('/') + '/ready.php'], text=True))
        if ready.get('status') != 'ready' or ready.get('version') != release['version']:
            raise ValueError('Account public readiness/version differs from the qualified release')
        results.append(dict(account_key=member['key'], commit=release['commit'], version=release['version'], status='ready'))
    return results
