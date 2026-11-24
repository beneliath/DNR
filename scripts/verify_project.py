#!/usr/bin/env python3
"""Run the local quality gate, including disposable database and browser workflows.

Install Composer/npm dependencies and Playwright Chromium/WebKit first. This
command never targets an existing database. Use --quick to omit Docker checks.
"""
import argparse
import hashlib
from pathlib import Path
import subprocess
import sys

ROOT = Path(__file__).resolve().parent.parent


def asset_hashes():
    paths = subprocess.check_output(['git', 'ls-files', '-z', '--', 'src/assets',
                                     'mattermost-plugin/webapp/dist'], cwd=ROOT).decode().split('\0')
    return {p: hashlib.sha256((ROOT / p).read_bytes()).hexdigest()
            if (ROOT / p).is_file() else None for p in paths if p}


def run(label, command, cwd=ROOT):
    print('\nChecking ' + label, flush=True)
    subprocess.run(command, cwd=cwd, check=True)


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--quick', action='store_true', help='omit disposable Docker integration/workflow checks')
    args = parser.parse_args()
    before = asset_hashes()
    run('PHP lint, analysis and unit tests', ['composer', 'check'])
    run('frontend build, syntax and unit tests', ['npm', 'test'])
    run('Mattermost frontend build', ['make', '-C', 'mattermost-plugin', 'webapp'])
    after = asset_hashes()
    changed = sorted(p for p in before.keys() | after.keys() if before.get(p) != after.get(p))
    if changed:
        raise ValueError('Generated assets were stale and have been rebuilt. Review them and rerun:\n' + '\n'.join(changed))
    run('Python tests', [sys.executable, '-m', 'unittest', 'discover', '-s', 'tests', '-p', '*_test.py'])
    run('Mattermost tests', ['go', 'test', './...'], ROOT / 'mattermost-plugin')
    run('Mattermost vet', ['go', 'vet', './...'], ROOT / 'mattermost-plugin')
    run('browser layout and interaction gate', ['npm', 'run', 'test:browser'])
    if args.quick:
        print('Quick checks passed. Disposable integration and packaged browser workflows were not run.')
    else:
        run('disposable integration suites', [sys.executable, 'scripts/integration_environment.py'])
        run('packaged browser workflows', [sys.executable, 'scripts/integration_environment.py', 'release-browser'])
        print('Project verification passed, including disposable integration and packaged browser workflows.')


if __name__ == '__main__':
    try:
        main()
    except (subprocess.CalledProcessError, ValueError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(1)
