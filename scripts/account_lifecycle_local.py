"""Local Account lifecycle worker. Only acts on authenticated, queued requests.

The web process cannot access Docker. Each operation is bounded to one validated
Compose project, and deletion requires a verified, encrypted recovery copy.
"""
from __future__ import annotations
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess
import tarfile

from provision_local_account import ROOT, clean_environment, control, private_file, run


def inspect(name):
    return json.loads(run(['docker', 'inspect', name], capture_output=True).stdout)[0]


def project_resources(project, kind):
    command = ['docker', 'ps', '-aq'] if kind == 'container' else ['docker', kind, 'ls', '-q']
    return run(command + ['--filter', 'label=com.docker.compose.project=' + project], capture_output=True).stdout.split()


def set_route(key, enabled):
    path = ROOT/'var/deployment/account-gateway/routes.conf'
    # Exact account boundary prevents prefix collisions (e.g. alpha / alpha-two).
    lines = [line for line in path.read_text().splitlines()
             if not re.search(r'/a/' + re.escape(key) + r'(?=/|\$|\(\?:)', line)]
    prefix = '/a/' + key + '/'
    target = 'http://moed-member-' + key + '/'
    if enabled:
        lines += [f'RedirectMatch 302 "^/a/{key}$" "{prefix}"',
                  f'ProxyPass "{prefix}database_maintenance.php" "{target}database_maintenance.php" connectiontimeout=5 timeout=300 retry=0 disablereuse=On',
                  f'ProxyPass "{prefix}" "{target}" connectiontimeout=5 timeout=120 retry=0 disablereuse=On',
                  f'ProxyPassReverse "{prefix}" "{target}"']
    else:
        lines.append(f'RedirectMatch 404 "^/a/{key}(?:/|$)"')
    previous = path.read_text()
    temporary = path.with_suffix('.tmp')
    private_file(temporary, '\n'.join(lines) + '\n'); temporary.replace(path)
    try:
        run(['docker', 'exec', 'dnr-ingress-1', 'apache2ctl', '-t'], capture_output=True)
        run(['docker', 'exec', 'dnr-ingress-1', 'apache2ctl', '-k', 'graceful'], capture_output=True)
    except Exception:
        private_file(path, previous)
        raise


def compose(directory, project):
    return ['docker', 'compose', '--env-file', str(directory/'account.env'), '-p', project,
            '-f', str(ROOT/'docker-compose.yaml'), '-f', str(ROOT/'docker-compose.dev.yaml'),
            '-f', str(ROOT/'docker-compose.smtp.yaml'), '-f', str(directory/'compose.json'),
            '-f', str(directory/'gateway-only.yaml')]


def backup_configuration(directory, receipt, image, password):
    from deployment_backup import crypto_command, encrypt_command, sha, stop_process
    files = {}
    for path in directory.rglob('*'):
        if path.is_symlink(): raise ValueError('Account configuration cannot contain symbolic links')
        if path.is_file(): files[path.relative_to(directory).as_posix()] = sha(path)
    archive = Path(receipt['backup_path']).parent/'deployment.tar.gz.dnrenc'
    encrypt_command(['env', 'COPYFILE_DISABLE=1', 'tar', '--no-xattrs', '-czf', '-', '-C', str(directory), '.'], archive, image, password)
    seen = set()
    with archive.open('rb') as ciphertext:
        process = subprocess.Popen(crypto_command(image, password, 'decrypt'), stdin=ciphertext, stdout=subprocess.PIPE)
        try:
            with tarfile.open(fileobj=process.stdout, mode='r|gz') as contents:
                for member in contents:
                    if member.isdir(): continue
                    name = member.name.removeprefix('./')
                    if not member.isfile() or name not in files or name in seen:
                        raise ValueError('Invalid configuration backup entry')
                    with contents.extractfile(member) as data:
                        if hashlib.file_digest(data, 'sha256').hexdigest() != files[name]:
                            raise ValueError('Configuration backup checksum mismatch')
                    seen.add(name)
            while process.stdout.read(1048576): pass
            if process.wait() != 0 or seen != set(files): raise ValueError('Configuration backup verification failed')
        finally:
            process.stdout.close(); stop_process(process)
    return {'backup_path': str(archive), 'backup_sha256': sha(archive)}


def delete_account(args, key, directory, project):
    from deployment_backup import create_verified_backup, root_command, sha
    # A durable receipt allows interrupted removal to resume without recreating
    # a deleted database or silently accepting an unverified backup.
    recovery = ROOT/'var/backups/account-deletion'/key
    recovery.mkdir(mode=0o700, parents=True, exist_ok=True)
    marker = recovery/'deletion-receipt.json'
    if marker.exists():
        receipt = json.loads(marker.read_text())
        if receipt.get('account_key') != key or receipt.get('project') != project or not receipt.get('restore_verified'):
            raise ValueError('Invalid deletion recovery receipt')
    else:
        db = project + '-db-1'; web = project + '-web-1'
        run(compose(directory, project) + ['up', '-d', '--no-deps', '--no-build', '--wait', 'db'], env=clean_environment(), capture_output=True)
        try:
            if run(root_command(db, 'mysql', '-uroot', 'dnr', '-NBe', 'SELECT account_key FROM account_profile WHERE id=1'), capture_output=True).stdout.strip() != key:
                raise ValueError('Account database identity mismatch')
            password = ROOT/'secrets/deployment_backup_password'
            app_image = inspect(web)['Image']
            receipt = create_verified_backup(db, inspect(db)['Image'], app_image,
                run(['git', 'rev-parse', 'HEAD'], capture_output=True).stdout.strip(), 'local-preview', password, recovery)
            receipt['configuration'] = backup_configuration(directory, receipt, app_image, password)
            receipt.update(account_key=key, project=project)
            private_file(marker, json.dumps(receipt, indent=2))
        finally:
            run(['docker', 'stop', db], capture_output=True)
    for backup in [receipt, receipt['configuration'], receipt.get('persistent_files')]:
        if backup and sha(Path(backup['backup_path'])) != backup['backup_sha256']:
            raise ValueError('Recovery archive no longer matches its verified receipt')
    containers = project_resources(project, 'container')
    if containers: run(['docker', 'rm', '-f', *containers], capture_output=True)
    volumes = project_resources(project, 'volume')
    if volumes: run(['docker', 'volume', 'rm', *volumes], capture_output=True)
    networks = project_resources(project, 'network')
    if networks: run(['docker', 'network', 'rm', *networks], capture_output=True)
    if directory.exists(): shutil.rmtree(directory)


def process_lifecycle(args, account):
    key, state = account['account_key'], account['state']
    if not re.fullmatch(r'[a-z][a-z0-9-]{2,63}', key) or state not in ('archiving', 'restoring', 'deleting'):
        raise ValueError('Invalid Account operation')
    primary = inspect(args.primary_web)
    primary_key = next(v.split('=', 1)[1] for v in primary['Config']['Env'] if v.startswith('DNR_ACCOUNT_KEY='))
    bindings = inspect(args.primary_ingress)['NetworkSettings']['Ports'].get('80/tcp') or []
    if key == primary_key or not any(b['HostIp'] == '127.0.0.1' and b['HostPort'] == str(args.primary_port) for b in bindings):
        raise ValueError('Only member Accounts in the local preview may be changed')
    current = next((a for a in control(args.primary_web, 'lifecycle') if a['account_key'] == key), None)
    if not current or current['state'] != state: raise ValueError('Account operation changed')
    directory = ROOT/'var/accounts'/key
    if directory.is_symlink() or directory.resolve().parent != (ROOT/'var/accounts').resolve():
        raise ValueError('Unsafe Account directory')
    project = 'moed-account-' + key
    if directory.exists() and json.loads((directory/'deployment.json').read_text())['project'] != project:
        raise ValueError('Account project mismatch')
    if state != 'restoring':
        set_route(key, False)
        running = run(['docker', 'ps', '-q', '--filter', 'label=com.docker.compose.project=' + project], capture_output=True).stdout.split()
        if state == 'archiving' and running:
            services = sorted({inspect(cid)['Config']['Labels']['com.docker.compose.service'] for cid in running
                               if inspect(cid)['Config']['Labels'].get('com.docker.compose.oneoff') == 'False'})
            private_file(directory/'lifecycle-runtime.json', json.dumps({'services': services}))
        if running: run(['docker', 'stop', *running], capture_output=True)
    if state == 'archiving':
        # Revocation is performed on restore, before the route is reopened.
        pass
    elif state == 'restoring':
        from deployment_backup import root_command
        dc = compose(directory, project)
        run(dc + ['up', '-d', '--no-deps', '--no-build', '--wait', 'db'], env=clean_environment(), capture_output=True)
        sql = "UPDATE users SET auth_version=auth_version+1; DELETE FROM account_login_handoffs;"
        run(root_command(project + '-db-1', 'mysql', '-uroot', 'dnr'), input=sql, capture_output=True)
        saved = directory/'lifecycle-runtime.json'
        services = json.loads(saved.read_text())['services'] if saved.exists() else ['web', 'downloads', 'backup', 'ingress', 'geocoder', 'mail-dispatch']
        allowed = set(run(dc + ['config', '--services'], env=clean_environment(), capture_output=True).stdout.split())
        if not services or any(s not in allowed for s in services):
            raise ValueError('Invalid saved Account services')
        run(dc + ['up', '-d', '--no-deps', '--no-build', '--wait', *services], env=clean_environment(), capture_output=True)
        set_route(key, True)
    else:
        delete_account(args, key, directory, project)
    control(args.primary_web, 'lifecycle_complete', key, state)
    print(f'Account {key}: {state} completed.', flush=True)
