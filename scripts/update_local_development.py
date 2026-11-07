#!/usr/bin/env python3
"""Check or synchronize canonical schemas and grants for source-bound local Accounts."""
import argparse
import datetime
import fcntl
import hashlib
import io
import json
from pathlib import Path
import re
import secrets
import subprocess
import sys
import tarfile
import time
from urllib.parse import urlsplit

from deployment_backup import create_verified_backup, fingerprint, query
from online_recovery_backup import create_online_backup

ROOT = Path(__file__).resolve().parent.parent
STATE = ROOT / 'var/deployment/local-development-state'
BACKUPS = ROOT / 'var/backups/local-development-updates'


def run(command, **kwargs):
    return subprocess.run(command, check=True, **kwargs)


def inspect(names):
    return json.loads(subprocess.check_output(['docker', 'inspect', *names]))


def environment(container):
    return dict(item.split('=', 1) for item in container['Config'].get('Env', []) if '=' in item)


def host_path(path):
    return Path(path.removeprefix('/host_mnt')).resolve()


def manifest(root=ROOT):
    directory = root / 'migrations'
    names = [line.strip() for line in (directory/'order.txt').read_text().splitlines()
             if line.strip() and not line.lstrip().startswith('#')]
    if len(names) != len(set(names)) or set(names) != {p.name for p in directory.glob('*.sql')}:
        raise ValueError('Canonical migration order is incomplete or contains duplicates')
    return {name: hashlib.sha256((directory/name).read_bytes()).hexdigest() for name in names}


def pending_migrations(expected, rows):
    for name, checksum, state in rows:
        if name not in expected or state != 'applied' or checksum != expected[name]:
            raise ValueError('Refusing an inconsistent migration ledger: '+name)
    applied = {row[0] for row in rows}
    return [name for name in expected if name not in applied]


def ledger(container):
    return [line.split('\t') for line in query(container,
        'SELECT migration_name,checksum,state FROM dnr.schema_migrations').splitlines()]


def local_source_web(container, root=ROOT):
    env = environment(container)
    url = urlsplit(env.get('DNR_PUBLIC_BASE_URL', ''))
    return (env.get('DNR_REQUIRE_HTTPS') == '0' and url.scheme == 'http'
            and url.hostname in ('localhost', '127.0.0.1', '::1')
            and any(m['Type']=='bind' and m['Destination']=='/var/www/html'
                    and host_path(m['Source'])==(root/'src').resolve() for m in container['Mounts']))


def discover(primary_project):
    primary = inspect([primary_project+'-web-1'])[0]
    if not local_source_web(primary): raise ValueError('Refusing a deployment without local HTTP and this checkout’s source bind')
    primary_env = environment(primary)
    inventory = [json.loads(row) for row in subprocess.check_output(
        ['docker','ps','-a','--no-trunc','--format','{{json .}}'], text=True).splitlines()]
    # Native backup helpers inherit the web image's Compose labels. They only
    # stream ciphertext/plaintext and have no connection to an account database.
    crypto_commands = {f'"php /opt/dnr/bin/native_backup_crypto.php {mode} - -"' for mode in ('encrypt','decrypt')}
    backup_helpers = {row['ID'] for row in inventory if row['Networks'] == 'none' and row['Command'] in crypto_commands}
    containers = inspect([row['ID'] for row in inventory if row['ID'] not in backup_helpers])
    ready = set()
    if primary_env.get('DNR_ACCOUNTS_ENABLED') == '1':
        ready = set(query(primary_project+'-db-1', "SELECT account_key FROM dnr.platform_accounts WHERE state='ready'").splitlines())
    primary_origin = urlsplit(primary_env['DNR_PUBLIC_BASE_URL'])
    accounts = []
    for web in containers:
        labels = web['Config'].get('Labels', {})
        if labels.get('com.docker.compose.service') != 'web' or labels.get('com.docker.compose.oneoff','False').lower() == 'true': continue
        project = labels['com.docker.compose.project']; env = environment(web)
        if web['Name'].lstrip('/') != project+'-web-1': continue
        key = env.get('DNR_ACCOUNT_KEY', '')
        member = env.get('DNR_ACCOUNT_MODE')=='member' and key in ready and project=='moed-account-'+key
        if project != primary_project and not member: continue
        if not re.fullmatch(r'[a-z0-9][a-z0-9_-]*', project) or not local_source_web(web):
            raise ValueError('Refusing a non-local or differently bound Account: '+project)
        origin = urlsplit(env['DNR_PUBLIC_BASE_URL'])
        if (origin.scheme,origin.netloc)!=(primary_origin.scheme,primary_origin.netloc):
            raise ValueError('Account origin differs from the primary local deployment: '+project)
        db = next((c for c in containers if c['Config'].get('Labels',{}).get('com.docker.compose.project')==project
                   and c['Config'].get('Labels',{}).get('com.docker.compose.service')=='db'), None)
        if db is None or db['State']['Status']!='running': raise ValueError('Account database is not running: '+project)
        db_env = environment(db)
        if db_env.get('MYSQL_DATABASE','dnr')!='dnr': raise ValueError('Unsupported local database name')
        if primary_env.get('DNR_ACCOUNTS_ENABLED')=='1' and query(db['Id'],'SELECT account_key FROM dnr.account_profile WHERE id=1')!=key:
            raise ValueError('Account/container database identity mismatch: '+project)
        scripts = {m['Destination']:host_path(m['Source']) for m in db['Mounts'] if m['Type']=='bind'}
        for destination, source in [('/opt/dnr/migrations',ROOT/'migrations')]:
            if scripts.get(destination)!=source.resolve(): raise ValueError('Database lacks canonical migration bindings: '+project)
        writers = [c for c in containers if c['Config'].get('Labels',{}).get('com.docker.compose.project')==project
                   and c['State']['Status'] in ('running','restarting') and c['Id']!=db['Id']
                   and environment(c).get('MYSQL_PASSWORD_FILE')]
        accounts.append(dict(project=project,key=key,web=web,db=db,writers=writers))
    if primary_project not in {a['project'] for a in accounts}: raise ValueError('Primary local Account was not found')
    missing = ready - {a['key'] for a in accounts}
    if missing: raise ValueError('Ready Accounts have no qualified local deployment: '+', '.join(sorted(missing)))
    owned = {a['project'] for a in accounts}
    backend = {name for a in accounts for name in a['db']['NetworkSettings']['Networks']}
    for c in containers:
        if c['State']['Status'] in ('running','restarting') and environment(c).get('MYSQL_PASSWORD_FILE'):
            if c['Config'].get('Labels',{}).get('com.docker.compose.project') not in owned and backend & set(c['NetworkSettings']['Networks']):
                raise ValueError('An unrelated writer shares an affected database network: '+c['Name'])
    previous_path=STATE/'last-update.json'
    if previous_path.is_file():
        previous=json.loads(previous_path.read_text())
        if previous.get('stage')=='failed' and previous.get('writers_paused'):
            paused_ids=previous.get('paused_writers')
            if not paused_ids: raise ValueError('Interrupted update has no recorded writer identities; reconcile its receipt before retrying')
            by_project={a['project']:a for a in accounts}
            by_id={c['Id']:c for c in containers}
            for identity in paused_ids:
                if identity in backup_helpers: continue
                c=by_id.get(identity); project=c['Config'].get('Labels',{}).get('com.docker.compose.project') if c else None
                if project not in by_project or not environment(c).get('MYSQL_PASSWORD_FILE') or c['Id']==by_project[project]['db']['Id']:
                    raise ValueError('Interrupted update writer ownership changed')
                if identity not in {w['Id'] for w in by_project[project]['writers']}: by_project[project]['writers'].append(c)
    network_ids = subprocess.check_output(['docker','network','ls','-q'],text=True).split()
    networks = json.loads(subprocess.check_output(['docker','network','inspect',*network_ids]))
    for network in networks:
        addresses = [c['IPv4Address'] for c in (network.get('Containers') or {}).values() if c.get('IPv4Address')]
        if len(addresses)!=len(set(addresses)): raise ValueError('Duplicate live network assignments: '+network['Name'])
    return sorted(accounts,key=lambda a:(a['project']!=primary_project,a['project']))


def grant_fingerprint(account):
    env = environment(account['db'])
    users = [env.get(name, default) for name,default in [('MYSQL_USER','dnruser'),('MYSQL_BACKUP_USER','dnrbackup'),
        ('MYSQL_MAINTENANCE_USER','dnrmaintenance'),('MYSQL_GEOCODER_USER','dnrgeocoder'),
        ('MYSQL_MAIL_INGEST_USER','dnrmailingest'),('MYSQL_MAIL_DISPATCH_USER','dnrmaildispatch')]]
    grants = []
    for user in users:
        if not re.fullmatch(r'[A-Za-z0-9_]+',user): raise ValueError('Invalid database role name')
        grants.extend(query(account['db']['Id'], "SHOW GRANTS FOR '"+user+"'@'%'").splitlines())
    return hashlib.sha256('\n'.join(sorted(grants)).encode()).hexdigest()


def assess(account, expected):
    pending = pending_migrations(expected, ledger(account['db']['Id']))
    path = STATE/(account['project']+'.json')
    previous = json.loads(path.read_text()) if path.is_file() else {}
    grants_hash = hashlib.sha256((ROOT/'scripts/configure_database_privileges.sh').read_bytes()).hexdigest()
    actual_grants = grant_fingerprint(account)
    refresh = previous.get('grants_source_sha256')!=grants_hash or previous.get('grants_sha256')!=actual_grants
    return dict(pending=pending,refresh_grants=refresh,grants_source_sha256=grants_hash,grants_sha256=actual_grants)


def qualify_restore(container, account, expected):
    """Apply the same canonical migrator to the verified backup copy before touching live DDL."""
    run(['docker','cp',str(ROOT/'migrations'),container+':/tmp/local-update-migrations'],stdout=subprocess.DEVNULL)
    for source,target in [('migrate.sh','local-update-migrate'),('configure_database_privileges.sh','local-update-grants')]:
        run(['docker','cp',str(ROOT/'scripts'/source),container+':/tmp/'+target],stdout=subprocess.DEVNULL)
    env = environment(account['db']); args = []
    mounts = {m['Destination']:host_path(m['Source']) for m in account['db']['Mounts'] if m['Type']=='bind'}
    for name,value in env.items():
        if not name.startswith('MYSQL_') or name=='MYSQL_ROOT_PASSWORD_FILE': continue
        if name.endswith('_PASSWORD_FILE') or name=='MYSQL_PASSWORD_FILE':
            source = mounts.get(value)
            if source is None: raise ValueError('Cannot qualify a database secret without its owned bind')
            target='/tmp/local-update-'+name.lower()
            run(['docker','cp',str(source),container+':'+target],stdout=subprocess.DEVNULL)
            args.extend(['-e',name+'='+target])
        elif name.endswith('_USER') or name in ('MYSQL_USER','MYSQL_DATABASE'):
            args.extend(['-e',name+'='+value])
    # Native backups contain the application schema, not mysql.user. Normal
    # fresh installs get this user from the image entrypoint before grants run.
    initialize_user = '''set -eu
app_password=$(cat "$MYSQL_PASSWORD_FILE")
case "$MYSQL_USER" in ''|*[!A-Za-z0-9_]*) exit 1;; esac
case "$app_password" in ''|*[!A-Za-z0-9]*) exit 1;; esac
MYSQL_PWD=$(cat "$MYSQL_ROOT_PASSWORD_FILE"); export MYSQL_PWD
printf "CREATE USER IF NOT EXISTS '%s'@'%%' IDENTIFIED BY '%s';\\n" "$MYSQL_USER" "$app_password" | mysql -uroot
'''
    run(['docker','exec','-i',*args,container,'sh'],input=initialize_user,text=True)
    run(['docker','exec',*args,'-e','DNR_MIGRATION_DIRECTORY=/tmp/local-update-migrations',
         '-e','DNR_PRIVILEGE_SCRIPT=/tmp/local-update-grants',container,'sh','/tmp/local-update-migrate'])
    if pending_migrations(expected,ledger(container)): raise ValueError('Isolated migration rehearsal is incomplete')
    return dict(canonical_migrations=len(expected),verified_at=datetime.datetime.now(datetime.timezone.utc).isoformat())


def wait_for_health(containers, timeout=150):
    deadline = time.monotonic()+timeout
    while True:
        bad = [c['Name'] for c in inspect([c['Id'] for c in containers])
               if c['State']['Status']!='running' or c['State'].get('Health',{}).get('Status','healthy')!='healthy']
        if not bad: return
        if time.monotonic()>=deadline: raise ValueError('Local services did not become healthy: '+', '.join(bad))
        time.sleep(3)


def migrate_database(account):
    """Fresh script bytes avoid stale individual-file mounts on Docker Desktop."""
    container=account['db']['Id']
    grant_path='/tmp/dnr-local-update-'+secrets.token_hex(8)+'.sh'
    run(['docker','exec','-i',container,'sh','-c','umask 077; cat > "$1"','local-update',grant_path],
        input=(ROOT/'scripts/configure_database_privileges.sh').read_text(),text=True)
    try:
        run(['docker','exec','-i','-e','DNR_PRIVILEGE_SCRIPT='+grant_path,
             '-e','DNR_MIGRATION_DIRECTORY=/opt/dnr/migrations',
             '-e','DNR_MIGRATION_ORDER_FILE=/opt/dnr/migrations/order.txt',container,'sh'],
            input=(ROOT/'scripts/migrate.sh').read_text(),text=True)
    finally:
        run(['docker','exec',container,'rm','-f',grant_path],stdout=subprocess.DEVNULL)


def can_snapshot_online(account):
    if account['web']['State']['Status'] != 'running':
        return False
    online_tables = query(account['db']['Id'], "SELECT COUNT(*) = COUNT(IF(ENGINE='InnoDB',1,NULL)) "
        "AND COUNT(IF(TABLE_NAME='stored_files',1,NULL)) = 1 FROM information_schema.tables "
        "WHERE TABLE_SCHEMA='dnr' AND TABLE_TYPE='BASE TABLE'")
    if online_tables != '1':
        return False
    return subprocess.run(['docker','exec',account['web']['Id'],'test','-f','/var/lib/dnr/files/.lifecycle.lock']).returncode == 0


def backup_for_update(account, expected, password_file, *, online):
    db, web = account['db'], account['web']
    version = subprocess.check_output(['docker','cp',web['Id']+':/opt/dnr/VERSION','-'])
    with tarfile.open(fileobj=io.BytesIO(version)) as archive:
        previous_version = archive.extractfile(archive.getmembers()[0]).read().decode().strip()
    commit = environment(web).get('DNR_BUILD_COMMIT','unknown-local-image')
    directory = BACKUPS/account['project']
    def verify(restored):
        return qualify_restore(restored,account,expected)
    if online:
        return create_online_backup(db['Id'],web['Id'],password_file,directory,commit,previous_version,restore_verifier=verify)
    return create_verified_backup(db['Id'],db['Image'],web['Image'],commit,previous_version,password_file,directory,restore_verifier=verify)


def synchronize(accounts, expected, password_file):
    STATE.mkdir(parents=True,exist_ok=True); BACKUPS.mkdir(parents=True,exist_ok=True)
    deployment_locks=ROOT/'.git/dnr-deploy'; deployment_locks.mkdir(parents=True,exist_ok=True)
    with (deployment_locks/'deploy.lock').open('a') as deployment_lock, (ROOT/'var/deployment/account-provisioner.lock').open('a') as provision_lock, (STATE/'update.lock').open('a') as lock:
        fcntl.flock(deployment_lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        fcntl.flock(provision_lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        # Complete all backup/rehearsal work before the first live schema change.
        changed = [(a,assess(a,expected)) for a in accounts]
        changed = [(a,s) for a,s in changed if s['pending'] or s['refresh_grants']]
        if not changed:
            active=[c for a in accounts for c in a['writers']]
            if active: wait_for_health(active)
            print('All active local Account schemas, grants, and services are current.'); return
        writers = [c for a,_ in changed for c in a['writers']]
        record = dict(started_at=datetime.datetime.now(datetime.timezone.utc).isoformat(),stage='preparing-backups',
                      paused_writers=[c['Id'] for c in writers],accounts={})
        receipt_path = STATE/'last-update.json'
        def save():
            receipt_path.write_text(json.dumps(record,indent=2)+'\n'); receipt_path.chmod(0o600)
        save(); live_changed=False
        paused=any(c.get('State',{}).get('Status','running') not in ('running','restarting') for c in writers)
        try:
            for account,state in changed:
                project = account['project']
                details = dict(pending=state['pending'])
                record['accounts'][project] = details
                if state['pending'] and not paused and can_snapshot_online(account):
                    print(project+': verifying an online backup and rehearsing migrations',flush=True)
                    details['backup'] = backup_for_update(account,expected,password_file,online=True)
                save()
            record['stage']='pausing'; save()
            paused=True
            if writers:
                run(['docker','stop','--time','30',*[c['Id'] for c in writers]],stdout=subprocess.DEVNULL)
            record['stage']='backing-up'; save()
            for account,state in changed:
                project=account['project']
                details = record['accounts'][project]
                if not state['pending']:
                    continue
                backup = details.get('backup')
                if backup is None or backup['database_state'] != fingerprint(account['db']['Id']):
                    print(project+': verifying the final paused recovery point',flush=True)
                    details['backup'] = backup_for_update(account,expected,password_file,online=False)
                    save()
            record['stage']='migrating'; save(); live_changed=True
            for account,_ in changed:
                print(account['project']+': applying canonical migrations and grants',flush=True)
                migrate_database(account)
                if pending_migrations(expected,ledger(account['db']['Id'])): raise ValueError('Migration verification is incomplete')
                state=assess(account,expected)
                state.update(project=account['project'],key=account['key'],database_id=account['db']['Id'],verified_at=datetime.datetime.now(datetime.timezone.utc).isoformat())
                state['refresh_grants']=False
                path=STATE/(account['project']+'.json'); path.write_text(json.dumps(state,indent=2)+'\n'); path.chmod(0o600)
            record['stage']='starting'; save()
            if writers: run(['docker','start',*[c['Id'] for c in writers]],stdout=subprocess.DEVNULL)
            paused=False
            if writers: wait_for_health(writers)
            record['stage']='complete'; save(); print('All affected local services are healthy; schemas and grants verified.',flush=True)
        except BaseException as error:
            record.update(stage='failed',error=type(error).__name__+': '+str(error),writers_paused=paused); save()
            if paused and not live_changed and writers:
                run(['docker','start',*[c['Id'] for c in writers]],stdout=subprocess.DEVNULL)
                record['writers_paused']=False; save()
            raise


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--apply',action='store_true',help='Back up, rehearse, synchronize, and verify all active local Accounts')
    parser.add_argument('--check',action='store_true',help='Read-only check (the default)')
    parser.add_argument('--primary-project',default='dnr')
    parser.add_argument('--backup-password-file',type=Path,default=ROOT/'secrets/deployment_backup_password')
    args=parser.parse_args()
    if args.apply and args.check: parser.error('Choose --check or --apply')
    if not re.fullmatch(r'[a-z0-9][a-z0-9_-]*',args.primary_project): parser.error('Invalid primary project name')
    expected=manifest(); accounts=discover(args.primary_project)
    problems=False
    for account in accounts:
        state=assess(account,expected)
        unhealthy=[c['Name'].lstrip('/') for c in account['writers']
                   if c['State']['Status']!='running' or c['State'].get('Health',{}).get('Status','healthy')!='healthy']
        state['unhealthy_services']=unhealthy
        problems |= bool(state['pending'] or state['refresh_grants'] or unhealthy)
        print(json.dumps(dict(project=account['project'],account=account['key'],**state)),flush=True)
    if args.apply: synchronize(accounts,expected,args.backup_password_file)
    elif problems: raise SystemExit(1)


if __name__=='__main__': main()
