#!/usr/bin/env python3
"""Plan or explicitly apply the primary Amazon browser map to existing Accounts."""
import argparse
import fcntl
import json
from pathlib import Path
import re
import secrets

import account_production as production


def refresh(config, key, apply=False):
    if not re.fullmatch(r'[a-z][a-z0-9-]{2,63}', key) or key == config['primary_key']:
        raise ValueError('Choose a member Account')
    production.assert_primary(config)
    account = next((a for a in production.control(config['primary_web'], 'directory')
                    if a['account_key'] == key), None)
    if not account or account['state'] not in ['ready', 'disabled']:
        raise ValueError('Only a ready or archived Account can receive map configuration')
    directory = production.ROOT / 'var/accounts' / key
    if directory.is_symlink():
        raise ValueError('Unsafe Account directory')
    runtime = directory / 'runtime.compose.json'
    metadata_path = directory / 'deployment.json'
    previous_metadata = metadata_path.read_text()
    metadata = json.loads(previous_metadata)
    project = config['project_prefix'] + '-' + key
    if metadata.get('mode') != 'production' or metadata.get('account_key') != key \
            or metadata.get('project') != project:
        raise ValueError('Account ownership mismatch')
    dc = production.saved_compose(directory, project, metadata)
    previous_runtime = runtime.read_text()
    settings = production.primary_amazon_map(config)
    if settings is None:
        raise ValueError('The primary Account does not use Amazon maps')
    document = production.with_amazon_map(json.loads(previous_runtime), settings)
    plan = {'account': key, 'provider': 'amazon', 'region': settings['environment']['DNR_MAP_AMAZON_REGION'],
            'style': settings['environment']['DNR_MAP_AMAZON_STYLE'], 'service': 'web',
            'release_commit': metadata['commit'], 'changed': document != json.loads(previous_runtime),
            'restart_web': account['state'] == 'ready', 'applied': False}
    if not apply or not plan['changed']:
        return plan
    # Retain a recovery pair before changing either checksum-protected file.
    # No database, migration, worker or network configuration is changed.
    recovery = directory / ('map-recovery-' + secrets.token_hex(8))
    recovery.mkdir(mode=0o700)
    production.private_file(recovery / runtime.name, previous_runtime)
    production.private_file(recovery / metadata_path.name, previous_metadata)
    def start_web():
        production.run(dc + ['up', '-d', '--no-build', '--no-deps', '--wait', '--wait-timeout', '120', 'web'],
                       env=production.clean_environment(), cwd=production.ROOT, capture_output=True)
    try:
        production.qualify_runtime(directory, document, metadata)
        production.private_file(runtime, json.dumps(document, indent=2))
        metadata['runtime_sha256'] = production.sha(runtime)
        production.private_file(metadata_path, json.dumps(metadata, indent=2))
        production.saved_compose(directory, project, metadata)
        production.run(dc + ['config', '--quiet'], env=production.clean_environment(), capture_output=True)
        if plan['restart_web']:
            start_web()
            production.ready_probe(config, key, document['services']['web']['environment']['DNR_PUBLIC_BASE_URL'])
    except BaseException:
        production.private_file(runtime, previous_runtime)
        production.private_file(metadata_path, previous_metadata)
        if plan['restart_web']:
            start_web()
        raise
    return {**plan, 'applied': True, 'recovery': str(recovery)}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('config', type=Path)
    target = parser.add_mutually_exclusive_group(required=True)
    target.add_argument('--account')
    target.add_argument('--all', action='store_true', help='Update every ready or archived member Account')
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    config = production.validate_config(json.loads(args.config.read_text()))
    # Serialize with release migrations, provisioning and account lifecycle work.
    with (production.ROOT / '.git/dnr-deploy/deploy.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        if args.all:
            keys = [a['account_key'] for a in production.control(config['primary_web'], 'directory')
                    if a['account_key'] != config['primary_key'] and a['state'] in ['ready', 'disabled']]
        else:
            keys = [args.account]
        for key in keys:
            print(json.dumps(refresh(config, key, args.apply)), flush=True)


if __name__ == '__main__':
    main()
