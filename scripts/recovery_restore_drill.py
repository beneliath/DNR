#!/usr/bin/env python3
"""Restore a verified NAS recovery set to an isolated replacement database and app."""
import argparse
import gzip
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import tarfile
import tempfile
import time
from deployment_backup import crypto_command, fingerprint, query, sha, stop_process
from online_recovery_backup import restored_database


def decrypt_archive(path, image, password, destination, compressed):
    destination.mkdir(parents=True, exist_ok=True, mode=0o700)
    with path.open('rb') as ciphertext:
        process = subprocess.Popen(crypto_command(image, password, 'decrypt'), stdin=ciphertext, stdout=subprocess.PIPE)
        try:
            with tarfile.open(fileobj=process.stdout, mode='r|gz' if compressed else 'r|') as archive:
                for member in archive:
                    # Restore regular files/directories only. Never follow links or
                    # replay devices, ownership, setuid bits or absolute paths.
                    if member.issym() or member.islnk() or not (member.isfile() or member.isdir()):
                        raise ValueError('Unsafe recovery archive entry')
                    archive.extract(member, destination, filter='data')
            while process.stdout.read(1048576):
                pass
            if process.wait() != 0:
                raise ValueError('Recovery archive authentication failed')
        finally:
            process.stdout.close(); stop_process(process)


def drill(directory, password, app_image=None, database_image=None):
    directory = Path(directory).resolve(); password = Path(password).resolve()
    receipt = json.loads((directory / 'receipt.json').read_text())
    if receipt.get('restore_verified') is not True or receipt.get('format') != 'native-sql-gzip-secretstream-v2':
        raise ValueError('A verified native recovery set is required')
    image = app_image or receipt['app_image']; database = database_image or receipt['database_image']
    archives = {'database.sql.gz.dnrenc': receipt}
    if receipt.get('persistent_files'):
        archives['uploaded-files.tar.gz.dnrenc'] = receipt['persistent_files']
    recovery = receipt.get('recovery_files', {})
    if set(recovery) != {'application-source.tar.dnrenc', 'recovery-config.tar.gz.dnrenc'}:
        raise ValueError('Application source and runtime configuration are required')
    archives.update(recovery)
    for name, metadata in archives.items():
        path = directory / name
        if path.is_symlink() or not path.is_file() or sha(path) != metadata['backup_sha256']:
            raise ValueError('Recovery set checksum mismatch')
    token = secrets.token_hex(8); label = 'org.dnr.restore-drill=' + token
    network = 'dnr-drill-' + token; web = network + '-web'
    volumes = [network + '-files', network + '-sessions']
    started = time.monotonic()
    with tempfile.TemporaryDirectory(prefix='dnr-replacement-drill-') as temporary:
        working = Path(temporary); working.chmod(0o700)
        try:
            # Let Docker allocate this internal bridge only after overlap checks.
            from integration_environment import allocate_test_networks
            ids = subprocess.check_output(['docker', 'network', 'ls', '-q'], text=True).split()
            existing = json.loads(subprocess.check_output(['docker', 'network', 'inspect', *ids])) if ids else []
            subnet = str(allocate_test_networks(existing)['backend'])
            subprocess.run(['docker', 'network', 'create', '--internal', '--subnet', subnet, '--label', label, network], check=True, stdout=subprocess.DEVNULL)
            for volume in volumes:
                subprocess.run(['docker', 'volume', 'create', '--label', label, volume], check=True, stdout=subprocess.DEVNULL)
            config = working / 'config'; source = working / 'source'
            decrypt_archive(directory/'recovery-config.tar.gz.dnrenc', image, password, config, True)
            decrypt_archive(directory/'application-source.tar.dnrenc', image, password, source, False)
            if not (source/'src/login.php').is_file():
                raise ValueError('Application source is incomplete')
            with restored_database(directory/'database.sql.gz.dnrenc', database, image, password, working, token, network=network) as db:
                if fingerprint(db) != receipt['database_state']:
                    raise ValueError('Restored database differs from the recovery snapshot')
                app_password = secrets.token_hex(32)
                secret = working / 'app-password'; secret.write_text(app_password); secret.chmod(0o644)
                query(db, f"CREATE USER 'dnrdrill'@'%' IDENTIFIED BY '{app_password}'; GRANT SELECT,INSERT,UPDATE,DELETE ON dnr.* TO 'dnrdrill'@'%';")
                files = working/'files'
                files.mkdir()
                if receipt.get('persistent_files'):
                    decrypt_archive(directory/'uploaded-files.tar.gz.dnrenc', image, password, files, True)
                expected = {}
                for line in query(db, 'SELECT storage_key,size,checksum FROM dnr.stored_files ORDER BY storage_key').splitlines():
                    key, size, checksum = line.split('\t')
                    if not re.fullmatch('[a-f0-9]{64}', key): raise ValueError('Invalid persistent file identity')
                    path = files/key
                    if not path.is_file() or path.stat().st_size != int(size) or sha(path) != checksum:
                        raise ValueError('A restored file differs from its database metadata')
                    expected[key] = checksum
                # Verify all restored encryption dependencies, rather than replacing
                # original users' keys or reusing production accounts for the drill.
                key = config/'secrets/dnr_2fa_encryption_key'
                routing = config/'secrets/dnr_inbound_routing_key'
                if not key.is_file() or not routing.is_file():
                    raise ValueError('Required application encryption secrets are missing')
                for path in (key, routing): path.chmod(0o644)
                command = ['docker','run','-d','--name',web,'--label',label,'--network',network,
                    '--read-only','--cap-drop','ALL','--cap-add','CHOWN','--cap-add','DAC_OVERRIDE','--cap-add','NET_BIND_SERVICE','--cap-add','SETUID','--cap-add','SETGID','--security-opt','no-new-privileges',
                    '--memory','768m','--pids-limit','128','--tmpfs','/tmp:rw,nosuid,size=128m','--tmpfs','/var/lock/apache2:rw,nosuid,size=1m','--tmpfs','/var/run/apache2:rw,nosuid,size=1m',
                    '--mount',f'type=bind,src={source / "src"},dst=/var/www/html,readonly',
                    '--mount',f'type=bind,src={source / "migrations"},dst=/opt/dnr/migrations,readonly',
                    '--mount',f'type=bind,src={Path(__file__).with_name("recovery_drill_application.php")},dst=/opt/dnr/drill.php,readonly',
                    '--mount',f'type=bind,src={secret},dst=/run/secrets/drill-db,readonly',
                    '--mount',f'type=bind,src={key},dst=/run/secrets/drill-key,readonly',
                    '--mount',f'type=bind,src={routing},dst=/run/secrets/drill-routing,readonly',
                    '--mount',f'type=volume,src={volumes[0]},dst=/var/lib/dnr/files',
                    '--mount',f'type=volume,src={volumes[1]},dst=/var/lib/php/sessions',
                    '-e','DB_HOST=db','-e','MYSQL_USER=dnrdrill','-e','MYSQL_PASSWORD_FILE=/run/secrets/drill-db',
                    '-e','MYSQL_DATABASE=dnr','-e','DNR_2FA_ENCRYPTION_KEY_FILE=/run/secrets/drill-key',
                    '-e','DNR_INBOUND_ROUTING_KEY_FILE=/run/secrets/drill-routing',
                    '-e','DNR_MAIL_TRANSPORT=disabled','-e','DNR_REQUIRE_HTTPS=0',
                    '-e','DNR_PUBLIC_BASE_URL=http://127.0.0.1','-e','DNR_AI_COACH_ENABLED=0',
                    '-e','DNR_RESTORE_DRILL=isolated',
                    image]
                application = config/'deployments/moed/application.yaml'
                if application.is_file():
                    application.chmod(0o644)
                    command[-1:-1] = ['--mount',f'type=bind,src={application},dst=/run/dnr/config/application.yaml,readonly','-e','DNR_CONFIG_FILE=/run/dnr/config/application.yaml']
                subprocess.run(command, check=True, stdout=subprocess.DEVNULL)
                # Populate a separate volume; production uploads never mount here.
                subprocess.run(['docker','cp',str(files) + '/.',web+':/var/lib/dnr/files'],check=True,stdout=subprocess.DEVNULL)
                subprocess.run(['docker','exec',web,'sh','-c','chown -R www-data:www-data /var/lib/dnr/files /var/lib/php/sessions; touch /var/lib/dnr/files/.lifecycle.lock; chown www-data:www-data /var/lib/dnr/files/.lifecycle.lock'],check=True,stdout=subprocess.DEVNULL)
                subprocess.run(['docker','exec','-u','www-data',web,'php','/opt/dnr/bin/maintain_file_storage.php'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
                for _ in range(30):
                    result = subprocess.run(['docker','exec',web,'curl','-fsS','http://127.0.0.1/ready.php'],capture_output=True,text=True)
                    if result.returncode == 0 and json.loads(result.stdout).get('status') == 'ready': break
                    time.sleep(1)
                else: raise ValueError('Replacement application did not become ready')
                result = subprocess.run(['docker','exec',web,'curl','-fsS','http://127.0.0.1/login.php'],capture_output=True,text=True,check=True)
                if 'name="password"' not in result.stdout: raise ValueError('Replacement sign-in page is unavailable')
                result = subprocess.run(['docker','exec','-u','www-data',web,'php','/opt/dnr/drill.php'],capture_output=True,text=True)
                if result.returncode:
                    raise ValueError('Replacement encryption or authenticated application checks failed')
                application_checks = json.loads(result.stdout)
                return dict(**application_checks, verified_at=__import__('datetime').datetime.now(__import__('datetime').timezone.utc).isoformat(),
                            database_matches=True, persistent_files=len(expected), source_restored=True,
                            configuration_restored=True, application_ready=True,
                            sign_in_page_verified=True, outbound_network_blocked=True,
                            elapsed_seconds=round(time.monotonic()-started,1))
        finally:
            subprocess.run(['docker','rm','-fv',web],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            for volume in volumes: subprocess.run(['docker','volume','rm',volume],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
            subprocess.run(['docker','network','rm',network],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)


def main():
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory',type=Path); parser.add_argument('--password-file',type=Path,required=True)
    parser.add_argument('--app-image'); parser.add_argument('--database-image'); parser.add_argument('--report',type=Path,required=True)
    args=parser.parse_args(); os.umask(0o077)
    report=drill(args.directory,args.password_file,args.app_image,args.database_image)
    args.report.write_text(json.dumps(report,indent=2)+'\n'); print(json.dumps(report))


if __name__=='__main__': main()
