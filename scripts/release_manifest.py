#!/usr/bin/env python3
"""Create/validate immutable release identities. Never executes manifest content."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parent.parent
DIGEST = re.compile(r'ghcr\.io/[a-z0-9._/-]+@sha256:[0-9a-f]{64}')

def sha(path):
    with open(path, 'rb') as handle:
        return hashlib.file_digest(handle, 'sha256').hexdigest()

def migration_manifest(root):
    names = [n for n in (root / 'migrations/order.txt').read_text().splitlines() if n and not n.startswith('#')]
    return {name: sha(root / 'migrations' / name) for name in names}

def validate(manifest, root=ROOT, expected=None):
    if manifest.get('format') != 1 or not re.fullmatch(r'[0-9a-f]{40}', manifest.get('commit', '')):
        raise ValueError('Invalid release identity')
    if expected and manifest['commit'] != expected:
        raise ValueError('Release commit does not match requested commit')
    if manifest.get('version') != (root / 'VERSION').read_text().strip():
        raise ValueError('Release version differs from checkout')
    if manifest.get('migrations') != migration_manifest(root):
        raise ValueError('Release migration checksums differ from checkout')
    images = manifest.get('images', {})
    if set(images) != {'app', 'ingress', 'bridge', 'database'} or not all(DIGEST.fullmatch(v) for v in images.values()):
        raise ValueError('Every release image must use a qualified GHCR digest')
    if not re.fullmatch(r'[0-9a-f]{64}', manifest.get('plugin_sha256', '')):
        raise ValueError('Missing plugin artifact checksum')
    if manifest.get('plugin_version') != json.loads((root / 'mattermost-plugin/plugin.json').read_text())['version']:
        raise ValueError('Plugin version differs from checkout')
    if manifest.get('migration_mode') != 'pause-writers':
        raise ValueError('This release requires a controlled writer pause')
    if not str(manifest.get('ci_run_id', '')).isdigit():
        raise ValueError('Missing qualifying CI run')
    return manifest

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    sub = parser.add_subparsers(dest='command', required=True)
    create = sub.add_parser('create')
    create.add_argument('--images', type=Path, required=True)
    create.add_argument('--plugin', type=Path, required=True)
    create.add_argument('--run-id', required=True)
    create.add_argument('--output', type=Path, required=True)
    check = sub.add_parser('check')
    check.add_argument('manifest', type=Path)
    check.add_argument('--commit')
    args = parser.parse_args()
    if args.command == 'check':
        validate(json.loads(args.manifest.read_text()), expected=args.commit)
        print('Release manifest verified')
        return
    commit = subprocess.check_output(['git', 'rev-parse', 'HEAD'], cwd=ROOT, text=True).strip()
    result = dict(format=1, commit=commit, version=(ROOT / 'VERSION').read_text().strip(),
                  migrations=migration_manifest(ROOT), images=json.loads(args.images.read_text()),
                  plugin_version=json.loads((ROOT / 'mattermost-plugin/plugin.json').read_text())['version'],
                  plugin_sha256=sha(args.plugin), migration_mode='pause-writers', ci_run_id=args.run_id)
    validate(result)
    args.output.write_text(json.dumps(result, indent=2, sort_keys=True) + '\n')

if __name__ == '__main__':
    try: main()
    except (ValueError, KeyError) as error: raise SystemExit(str(error))
