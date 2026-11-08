#!/usr/bin/env python3
"""Maintain free space on the local MOED Docker Desktop filesystem."""
import argparse
import json
from contextlib import contextmanager
from datetime import datetime, timedelta, timezone
import os
from pathlib import Path
import plistlib
import re
import shutil
import subprocess
import sys
import tempfile
from urllib.parse import urlencode

TEST_IMAGE = re.compile(r'(dnr-test-[0-9a-f]{12})-(?:app|ingress|database):latest')
RESERVE = 16 * 1024**3
MIN_AGE = timedelta(days=2)
AGENT = 'com.moed.local-docker-storage'


def docker(*args, docker_bin='docker'):
    return subprocess.check_output([docker_bin, *args], text=True, timeout=180).strip()


@contextmanager
def image_lock(cleanup=False):
    # Concurrent test runs share this lock. A guard cannot select even an old
    # explicitly reused tag between a test build and its first container.
    import fcntl
    path = Path(tempfile.gettempdir()) / f'moed-integration-images-{os.getuid()}.lock'
    with os.fdopen(os.open(path, os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW, 0o600), 'r+') as stream:
        try:
            fcntl.flock(stream, fcntl.LOCK_EX | fcntl.LOCK_NB if cleanup else fcntl.LOCK_SH)
        except BlockingIOError:
            yield False
        else:
            yield True


def remove_run_images(project, owned_images):
    """Remove only this run's generated tags, including builds left by a failed test."""
    if not owned_images:
        return
    if not re.fullmatch(r'dnr-test-[0-9a-f]{12}', project):
        raise ValueError('Expected a generated MOED integration project.')
    existing = set(docker('image', 'ls', '--filter', 'reference=' + project + '-*',
                          '--format', '{{.Repository}}:{{.Tag}}').splitlines())
    refs = sorted(existing.intersection(owned_images))
    if any(not TEST_IMAGE.fullmatch(ref) or not ref.startswith(project + '-') for ref in refs):
        raise ValueError('Unexpected image in integration cleanup.')
    if refs:
        subprocess.run(['docker', 'image', 'rm', '--no-prune', *refs], check=True)


def eligible_images(rows, containers, now):
    ids = {item[0] for item in containers}
    refs = {item[1] for item in containers}
    projects = {item[2] for item in containers}
    eligible = set()
    for ref, image_id, created in rows:
        match = TEST_IMAGE.fullmatch(ref)
        if not match or ref in refs or image_id in ids or match[1] in projects:
            continue
        timestamp = datetime.fromtimestamp(created, timezone.utc)
        if now - timestamp >= MIN_AGE:
            eligible.add(ref)
    return sorted(eligible)


def old_open_webui_images(images, containers):
    used = {image_id for image_id, _, _ in containers}
    unused = []
    for image in images:
        labels = image.get('Labels') or {}
        if (labels.get('org.opencontainers.image.source') == 'https://github.com/open-webui/open-webui'
                and labels.get('org.opencontainers.image.title') == 'open-webui' and image['Id'] not in used
                and not image.get('RepoTags') and not image.get('RepoDigests')):
            unused.append((image['Created'], image['Id']))
    return [image_id for _, image_id in sorted(unused)[:-2]]


def maintain(apply, docker_bin):
    def run(*args):
        return docker(*args, docker_bin=docker_bin)

    endpoint = run('context', 'inspect', '--format', '{{.Endpoints.docker.Host}}')
    if os.environ.get('DOCKER_HOST') or not endpoint.startswith('unix://') or run('info', '--format', '{{.OperatingSystem}}') != 'Docker Desktop':
        raise ValueError('This guard requires a local Docker Desktop Unix socket.')
    if '127.0.0.1:8080' not in run('port', 'dnr-ingress-1', '80/tcp').splitlines():
        raise ValueError('Expected the local MOED ingress on loopback port 8080.')

    def free_bytes():
        return int(run('exec', 'dnr-db-1', 'df', '-Pk', '/var/lib/mysql').splitlines()[-1].split()[3]) * 1024

    free = free_bytes()
    if free >= RESERVE:
        print(f'Docker database filesystem has {free / 1024**3:.1f} GiB available.')
        return True
    # The Engine summaries include image IDs for stopped containers in one snapshot.
    def summary(resource, params=None):
        url = 'http://localhost/' + resource + '/json?' + urlencode(params or {})
        output = subprocess.check_output(['/usr/bin/curl', '--silent', '--show-error', '--fail', '--max-time', '45',
                                          '--unix-socket', endpoint.removeprefix('unix://'), url])
        return json.loads(output)

    with image_lock(cleanup=True) as acquired:
        if not acquired:
            print('Integration tests are active; the next storage check will retry.', flush=True)
            return True
        def container_images():
            return [(item['ImageID'], item['Image'], item['Labels'].get('com.docker.compose.project', ''))
                    for item in summary('containers', {'all': 1})]
        containers = container_images()
        images = summary('images', {'filters': json.dumps({'reference': ['dnr-test-*']})})
        rows = [(ref, image['Id'], image['Created']) for image in images for ref in image.get('RepoTags') or []]
        refs = eligible_images(rows, containers, datetime.now(timezone.utc))
        superseded = old_open_webui_images(summary('images', {'all': 1}), containers)
        print(f'Docker has {free / 1024**3:.1f} GiB available. Eligible: {len(refs)} completed test tags and '
              f'{len(superseded)} older untagged Open WebUI images.', flush=True)
        if not apply:
            return False
        for offset in range(0, len(refs), 24):
            run('image', 'rm', '--no-prune', *refs[offset:offset + 24])
        if free_bytes() < RESERVE:
            run('buildx', 'prune', '--force', '--filter', 'until=24h')
        for image_id in superseded:
            if free_bytes() >= RESERVE:
                break
            # Re-read tags and users before each non-forced removal by ID.
            current = summary('images/' + image_id)
            if current.get('RepoTags') or current.get('RepoDigests') or image_id in {row[0] for row in container_images()}:
                continue
            run('image', 'rm', '--no-prune', image_id)
            print('Removed unused Open WebUI image ' + image_id[7:19], flush=True)
    free = free_bytes()
    print(f'Docker database filesystem now has {free / 1024**3:.1f} GiB available.', flush=True)
    return free >= RESERVE


def install(docker_bin):
    if sys.platform != 'darwin':
        raise ValueError('The scheduled guard uses macOS launchd.')
    support = Path.home() / 'Library' / 'Application Support' / 'MOED'
    support.mkdir(parents=True, exist_ok=True)
    script = support / 'local_docker_storage.py'
    shutil.copyfile(__file__, script)
    agents = Path.home() / 'Library' / 'LaunchAgents'
    agents.mkdir(exist_ok=True)
    plist = agents / (AGENT + '.plist')
    with plist.open('wb') as stream:
        plistlib.dump({'Label': AGENT,
                       'ProgramArguments': [shutil.which('python3') or sys.executable, str(script), '--apply', '--docker', docker_bin],
                       'EnvironmentVariables': {'PATH': str(Path(docker_bin).parent) + ':/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin'},
                       'StartInterval': 900, 'RunAtLoad': True, 'ProcessType': 'Background',
                       'StandardOutPath': str(support / 'docker-storage.log'),
                       'StandardErrorPath': str(support / 'docker-storage-error.log')}, stream)
    domain = 'gui/' + str(os.getuid())
    if subprocess.run(['launchctl', 'print', domain + '/' + AGENT], capture_output=True).returncode == 0:
        subprocess.run(['launchctl', 'bootout', domain + '/' + AGENT], check=True)
    subprocess.run(['launchctl', 'bootstrap', domain, str(plist)], check=True)
    print(f'Installed {AGENT} with a 15-minute storage check.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group()
    mode.add_argument('--apply', action='store_true', help='Clean only when free space is below 16 GiB')
    mode.add_argument('--install', action='store_true', help='Install the current-user macOS LaunchAgent')
    parser.add_argument('--docker', default=shutil.which('docker'))
    args = parser.parse_args()
    if not args.docker:
        parser.error('Docker CLI is required.')
    if args.install:
        install(args.docker)
    elif not maintain(args.apply, args.docker):
        raise SystemExit('Free space is below 16 GiB. Review Docker Desktop storage usage.')


if __name__ == '__main__':
    main()
