#!/usr/bin/env python3
"""Extend the existing s1 Gatus collector; preserve unrelated checks and schedules."""
import argparse
import datetime
import fcntl
import json
import os
from pathlib import Path
import shutil

import gatus_moed


def patch_collector(source):
    if 'import gatus_moed\n' in source:
        return source
    replacements = {
        'import urllib.request\n': 'import urllib.request\n\nimport gatus_moed\n',
        'def probe(target, container):\n': "def probe(target, container):\n    if target.get('probe') == 'moed-host':\n        return gatus_moed.probe_host(target)\n",
        "        config = json.loads((ROOT/'targets.json').read_text())\n": "        config = json.loads((ROOT/'targets.json').read_text())\n        config = gatus_moed.reconcile(ROOT, config)\n",
        "*[t['container'] for t in targets]": "*[t['container'] for t in targets if t['probe'] != 'moed-host']",
    }
    for old, new in replacements.items():
        if source.count(old) != 1:
            raise ValueError('The existing Gatus collector changed; review it before updating')
        source = source.replace(old, new)
    compile(source, 'push_container_health.py', 'exec')
    return source


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--directory', type=Path, default=Path('/home/dgilmore/gatus'))
    parser.add_argument('--apply', action='store_true')
    args = parser.parse_args()
    os.umask(0o077)
    root = args.directory / 'monitoring'
    with (root / 'collector.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        path = root / 'push_container_health.py'
        source = path.read_text()
        patched = patch_collector(source)
        config = json.loads((root / 'targets.json').read_text())
        active = gatus_moed.reconcile(root, config, write=False)
        planned = {t['container'] for t in active['targets']}
        current = {t['container'] for t in config['targets'] if not t.get('awaiting_first_deployment')}
        print('New active checks:', ', '.join(sorted(planned - current)) or 'none')
        print('Active collector checks:', len(planned))
        for target in active['targets']:
            if target['probe'] == 'moed-host':
                healthy, error = gatus_moed.probe_host(target)
                print(target['container'] + ': ' + ('healthy' if healthy else error))
        if not args.apply:
            print('Read-only plan; no configuration or schedule changed.')
            return
        backup = Path.home() / '.local/state/moed-gatus/config-backups' / datetime.datetime.now(datetime.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
        backup.mkdir(parents=True, mode=0o700)
        helper_names = ('deployment_monitoring.py', 'gatus_deployment.py', 'gatus_moed.py')
        for original in (path, root / 'targets.json', args.directory / 'config/config.yaml',
                         root / 'deployment-alert-state.json', *(root / name for name in helper_names)):
            if original.exists():
                saved = backup / original.name
                shutil.copy2(original, saved); saved.chmod(0o600)
        if path.read_text() != source or json.loads((root / 'targets.json').read_text()) != config:
            raise ValueError('Collector configuration changed during preparation')
        for name in helper_names:
            gatus_moed.atomic_write(root / name, Path(__file__).with_name(name).read_text())
        gatus_moed.reconcile(root, config)
        gatus_moed.atomic_write(path, patched)
        print('Gatus MOED monitoring installed. Existing minute schedule retained.')
        print('Recovery copies:', backup)


if __name__ == '__main__':
    main()
