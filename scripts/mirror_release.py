#!/usr/bin/env python3
"""Idempotently publish/verify a final merged release on both configured remotes."""
import argparse
import json
from pathlib import Path
import subprocess
import sys
from release_manifest import ROOT, validate

def git(*args):
    return subprocess.check_output(['git', '-C', str(ROOT), *args], text=True).strip()

def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('manifest', type=Path)
    parser.add_argument('--publish', action='store_true', help='Create final tag and push main/tag without force')
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    release = validate(json.loads(args.manifest.read_text()))
    commit = release['commit']
    if git('rev-parse', 'refs/heads/main') != commit:
        raise ValueError('Release must identify the final merged local main commit')
    tag = 'v' + release['version']
    if args.publish:
        # Never create a tag for an unqualified build or a PR-only CI run.
        run = json.loads(subprocess.check_output(['gh', 'run', 'view', str(release['ci_run_id']),
            '--json', 'headSha,headBranch,event,conclusion,workflowName'], cwd=ROOT, text=True))
        if (run['headSha'], run['headBranch'], run['event'], run['conclusion'], run['workflowName']) != (commit, 'main', 'push', 'success', 'CI'):
            raise ValueError('Final-main CI must succeed before publishing a release')
        existing = subprocess.run(['git', 'rev-parse', '--verify', f'refs/tags/{tag}^{{commit}}'], cwd=ROOT, text=True, capture_output=True)
        if existing.returncode == 0 and existing.stdout.strip() != commit:
            raise ValueError('Release version already identifies another commit')
        if existing.returncode != 0:
            subprocess.run(['git', 'tag', '-a', tag, commit, '-m', 'Release ' + release['version']], cwd=ROOT, check=True)
    states = {}
    errors = []
    for remote in ('origin', 'gitlab'):
        try:
            if args.publish:
                # A retry keeps already completed pushes; no force and no version allocation.
                subprocess.run(['git', 'push', remote, f'{commit}:refs/heads/main', f'refs/tags/{tag}:refs/tags/{tag}'], cwd=ROOT, check=True)
            refs = dict((line.split()[1], line.split()[0]) for line in git('ls-remote', '--exit-code', remote,
                'refs/heads/main', 'refs/tags/' + tag, 'refs/tags/' + tag + '^{}').splitlines())
            branch = refs.get('refs/heads/main')
            tagged = refs.get('refs/tags/' + tag + '^{}', refs.get('refs/tags/' + tag))
            if branch != commit or tagged != commit:
                raise ValueError(remote + ' main/tag does not identify the release')
            states[remote] = dict(main=branch, tag=tagged)
        except (ValueError, subprocess.CalledProcessError) as error:
            errors.append(str(error))
    args.output.write_text(json.dumps(dict(commit=commit, tag=tag, remotes=states, errors=errors), indent=2) + '\n')
    if errors:
        raise ValueError('; '.join(errors) + '. Retry this release after resolving the mirror; do not allocate another version.')

if __name__ == '__main__':
    try: main()
    except (ValueError, subprocess.CalledProcessError) as error: sys.exit(str(error))
