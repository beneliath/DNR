#!/usr/bin/env python3
"""s1-only deployment transaction, invoked over SSH with a qualified manifest."""
import datetime
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import signal
import subprocess
import sys
from deployment_backup import create_verified_backup
from deployment_notice import DeploymentNotice
from release_timestamp import timestamp_utc


def run(args, **kwargs):
    return subprocess.check_output(args, text=True, **kwargs).strip()


def main():
    project, expected, manifest_path, backup_password_file, public_url, notice_id = sys.argv[1:]
    root = Path(project)
    os.chdir(root)
    records = root / '.git/dnr-deploy'
    records.mkdir(mode=0o700, exist_ok=True)
    # Covers preflight, checkout, migration, internal and public health, and final record.
    with (records / 'deploy.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        release = json.loads(Path(manifest_path).read_text())
        if release.get('commit') != expected or not re.fullmatch('[0-9a-f]{40}', expected):
            raise ValueError('Invalid requested release')
        if release.get('migration_mode') != 'pause-writers':
            raise ValueError('Unknown migration compatibility policy')
        images = release['images']
        if set(images) != {'app', 'ingress', 'bridge', 'database'} or not all(re.fullmatch(r'ghcr\.io/[a-z0-9._/-]+@sha256:[0-9a-f]{64}', image) for image in images.values()):
            raise ValueError('Release images must be immutable GHCR digests')
        # The first rollout may publish a notice before the old checkout has its
        # .gitignore entry. Exclude only these runtime files, not source changes.
        clean_status = ['git', 'status', '--porcelain', '--untracked-files=normal', '--', '.',
                        ':!var/deployment/notice.json', ':!var/deployment/notice.lock',
                        ':!var/deployment/notice.tmp']
        if run(['git', 'branch', '--show-current']) != 'main' or run(clean_status):
            raise ValueError('s1 must have a clean main checkout')
        previous_commit = run(['git', 'rev-parse', 'HEAD'])
        run(['git', 'fetch', 'origin', 'main'])
        # Verify BEFORE changing any active file; merge the exact SHA, never a moving ref.
        if run(['git', 'rev-parse', 'origin/main']) != expected:
            raise ValueError('Requested main moved; checkout remains unchanged')
        subprocess.run(['git', 'merge-base', '--is-ancestor', previous_commit, expected], check=True)
        if release['version'] != run(['git', 'show', expected + ':VERSION']):
            raise ValueError('Release version differs from requested source')
        names = run(['git', 'show', expected + ':migrations/order.txt']).splitlines()
        checksums = {n: hashlib.sha256(subprocess.check_output(['git', 'show', expected + ':migrations/' + n])).hexdigest()
                     for n in names if n and not n.startswith('#')}
        if checksums != release['migrations']:
            raise ValueError('Release migration manifest differs from requested source')
        env = os.environ.copy()
        env.update({f'DNR_{kind.upper()}_IMAGE': image for kind, image in images.items()})
        # Set explicit provenance during the transition; the image itself contains this same SHA.
        env['DNR_BUILD_COMMIT'] = expected
        env['DNR_BUILD_TIMESTAMP'] = timestamp_utc(run(['git', 'show', '-s', '--format=%cI', expected]))
        mode = 'production-ubuntu-proton-mattermost'
        def compose(*args):
            return run(['sh', 'scripts/compose_with_provenance.sh', mode, *args], env=env)
        def container(service):
            return compose('ps', '-aq', service).splitlines()[-1]
        def inspect(identifier):
            return json.loads(run(['docker', 'inspect', identifier]))[0]
        previous = {service: inspect(container(service))['Config']['Image']
                    for service in ('db', 'web', 'ingress', 'geocoder', 'mail-ingest', 'mail-dispatch', 'proton-bridge')}
        previous_database_image_id = inspect(container('db'))['Image']
        for image in images.values():
            run(['docker', 'pull', image])
            if inspect(image)['Config']['Labels'].get('org.opencontainers.image.revision') != expected:
                raise ValueError('Image provenance mismatch')
        record = dict(commit=expected, previous_commit=previous_commit, previous_images=previous,
                      backup=None, manifest=release,
                      mirrors=json.loads(Path(manifest_path).with_name('mirrors.json').read_text()),
                      phase='preflight', outcome='running')
        record_path = records / (expected + '.json')
        def save(phase):
            record['phase'] = phase
            temporary = record_path.with_suffix('.tmp')
            temporary.write_text(json.dumps(record, indent=2) + '\n')
            temporary.replace(record_path)
        save('preflight')
        notice = DeploymentNotice(root)
        save('awaiting-save-window')
        # CI and image downloads finish before starting the five-minute save window.
        # Cancellation or interruption here must not stop application writers.
        try:
            notice.begin_countdown(notice_id, expected)
            record['notice'] = notice.wait_and_claim(notice_id, expected)
        except BaseException as error:
            record.update(outcome='failed', error=str(error))
            save('awaiting-save-window')
            raise
        try:
            compose('stop', 'web', 'geocoder', 'mail-ingest', 'mail-dispatch')
            save('backing-up')
            # Standing deployment directive: finish and verify a NEW backup before
            # changing the checkout, database version, schema, or application image.
            record['backup'] = create_verified_backup(
                container('db'), previous_database_image_id, images['app'], previous_commit,
                run(['git', 'show', previous_commit + ':VERSION']), backup_password_file, records / 'backups'
            )
            save('backup-verified')
            run(['git', 'merge', '--ff-only', expected])
            # DDL and a possible database upgrade happen with every application writer stopped.
            compose('up', '-d', '--no-build', '--no-deps', '--wait', 'db')
            save('migrating')
            compose('run', '--rm', '--no-deps', 'migrator')
            save('starting')
            compose('up', '-d', '--no-build', '--wait', '--wait-timeout', '180')
            for service in ('web', 'geocoder', 'mail-ingest', 'mail-dispatch'):
                state = inspect(container(service))
                if state['Image'] != inspect(images['app'])['Id']:
                    raise ValueError(service + ' is running a different image')
                if state['State'].get('Health', {}).get('Status') != 'healthy':
                    raise ValueError(service + ' failed its health/progress check')
            version = run(['docker', 'exec', container('web'), 'cat', '/opt/dnr/VERSION'])
            if version != release['version']:
                raise ValueError('Running application version differs from the release')
            ready = json.loads(run(['curl', '--fail', '--silent', '--show-error', '--max-time', '20', public_url + '/ready.php']))
            health = json.loads(run(['curl', '--fail', '--silent', '--show-error', '--max-time', '20', public_url + '/health.php']))
            if ready.get('status') != 'ready' or ready.get('version') != version or health.get('status') != 'ok':
                raise ValueError('Public readiness failed')
            record['outcome'] = 'success'
            save('verified')
            (records / 'current.json').write_text(json.dumps(record, indent=2) + '\n')
            print('Deployment verified: ' + expected + ', version ' + version)
        except BaseException as error:
            record['outcome'] = 'failed'
            record['error'] = str(error)
            # Schema may have advanced. Do not restart old writers or attempt a MySQL downgrade.
            try: compose('stop', 'web', 'geocoder', 'mail-ingest', 'mail-dispatch')
            except subprocess.CalledProcessError: pass
            save(record['phase'])
            notice.finish(notice_id, 'failed')
            print('Deployment failed; writers remain paused. Recovery record: ' + str(record_path), file=sys.stderr)
            raise
        notice.finish(notice_id, 'complete')

if __name__ == '__main__':
    def interrupted(signum, frame):
        raise RuntimeError('Deployment interrupted by signal ' + str(signum))
    for signum in (signal.SIGHUP, signal.SIGINT, signal.SIGTERM):
        signal.signal(signum, interrupted)
    try: main()
    except (ValueError, OSError, RuntimeError, subprocess.CalledProcessError) as error: sys.exit(str(error))
