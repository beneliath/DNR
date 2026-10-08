#!/usr/bin/env python3
"""Run the authorized protected release and s1 deployment as one bounded process."""

import argparse
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import tempfile
import time


ROOT = Path(__file__).resolve().parent.parent
STATE_DIRECTORY = ROOT / '.git/dnr-deploy'
EXPECTED_REMOTES = {
    'origin': {'git@github.com:beneliath/DNR.git', 'https://github.com/beneliath/DNR.git'},
    'gitlab': {'https://gitlab.beneliath.com/SiMM/dnr.git'},
}
GUIDE_PATHS = (
    'docs/user-manual',
    'scripts/manual',
    'src/assets/docs/moed-comprehensive-user-manual.pdf',
)


def run(args, *, capture=False, check=True):
    try:
        result = subprocess.run(
            [str(value) for value in args], cwd=ROOT, check=check, text=True,
            stdout=subprocess.PIPE if capture else None,
            stderr=subprocess.PIPE if capture else None,
        )
    except subprocess.CalledProcessError as error:
        if capture:
            if error.stdout:
                print(error.stdout.rstrip(), file=sys.stderr)
            if error.stderr:
                print(error.stderr.rstrip(), file=sys.stderr)
        raise
    return result.stdout.strip() if capture else result


def succeeds(args):
    return subprocess.run(
        [str(value) for value in args], cwd=ROOT,
        stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
    ).returncode == 0


def git(*args):
    return run(['git', *args], capture=True)


def milestone(message):
    print(f'RELEASE: {message}', flush=True)


def require_tools():
    missing = [name for name in ('git', 'gh', 'ssh', 'scp', 'python3') if not shutil.which(name)]
    if missing:
        raise ValueError('Missing release tools: ' + ', '.join(missing))


def validate_checkout():
    branch = git('branch', '--show-current')
    if not branch or branch == 'main':
        raise ValueError('Run the release from its non-main working branch')
    for remote, allowed in EXPECTED_REMOTES.items():
        actual = git('remote', 'get-url', '--push', remote)
        if actual not in allowed:
            raise ValueError(f'{remote} has unexpected push URL: {actual}')
    configured = set(git('remote').splitlines())
    unexpected = configured - set(EXPECTED_REMOTES)
    if unexpected:
        raise ValueError('Review additional remotes before release: ' + ', '.join(sorted(unexpected)))
    return branch


def deployed_commit():
    user = os.environ.get('DNR_S1_USER', 'dgilmore')
    host = os.environ.get('DNR_S1_HOST', '192.168.1.150')
    directory = os.environ.get('DNR_S1_PROJECT_DIR', '/home/dgilmore/moed')
    if not re.fullmatch(r'[A-Za-z0-9._-]+', user) or not re.fullmatch(r'[A-Za-z0-9.:-]+', host):
        raise ValueError('Invalid s1 connection identity')
    if not re.fullmatch(r'/[A-Za-z0-9._/-]+', directory):
        raise ValueError('Invalid s1 project directory')
    commit = run([
        'ssh', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=10', f'{user}@{host}',
        'git', '-C', directory, 'rev-parse', 'HEAD',
    ], capture=True)
    if not re.fullmatch(r'[0-9a-f]{40}', commit):
        raise ValueError('s1 did not return a deployed commit')
    return commit


def guide_changed_since(commit):
    run(['git', 'cat-file', '-e', commit + '^{commit}'])
    result = subprocess.run(
        ['git', 'diff', '--quiet', commit, '--', *GUIDE_PATHS], cwd=ROOT,
    )
    if result.returncode not in (0, 1):
        raise subprocess.CalledProcessError(result.returncode, result.args)
    untracked_or_modified = git('status', '--porcelain', '--untracked-files=all', '--', *GUIDE_PATHS)
    return result.returncode == 1 or bool(untracked_or_modified)


def prepare_version(args, deployed):
    if not args.resume_version:
        command = ['scripts/prepare_release', args.bump, '--base-ref', 'origin/main']
        if args.plugin_bump:
            command += ['--plugin-bump', args.plugin_bump]
        run(command)
        return
    # A paused release may already be merged but not tagged or deployed. Keep
    # its version while qualifying a follow-up fix through the full PR workflow.
    version = (ROOT / 'VERSION').read_text().strip()
    if args.plugin_bump or version != args.resume_version or git('show', 'origin/main:VERSION') != version:
        raise ValueError('Resume requires the same prepared version on main, without a plugin bump')
    previous = git('show', deployed + ':VERSION')
    parts = list(map(int, previous.split('.')))
    index = {'super': 0, 'major': 1, 'minor': 2}[args.bump]
    parts[index] += 1
    for following in range(index + 1, 3):
        parts[following] = 0
    if version != '.'.join(map(str, parts)):
        raise ValueError('Resume version must be the requested bump from the deployed release')
    for remote in EXPECTED_REMOTES:
        if git('ls-remote', '--tags', remote, 'refs/tags/v' + version):
            raise ValueError('Cannot resume an already published version: ' + version)
    run(['scripts/prepare_release', 'check', '--base-ref', deployed])


def verify_guide_if_changed(commit):
    if not guide_changed_since(commit):
        milestone('Comprehensive Guide unchanged since the deployed revision')
        return
    milestone('Verifying the changed Comprehensive Guide')
    run(['python3', 'scripts/manual/verify.py'])
    version = (ROOT / 'VERSION').read_text().strip()
    report = json.loads((ROOT / 'docs/user-manual/verification.json').read_text())
    if report.get('release', {}).get('version') != version:
        raise ValueError('Changed Comprehensive Guide does not identify release ' + version)


def release_body(version, summary):
    return (
        f'{summary.rstrip(".")}.\n\n'
        f'This PR prepares application release {version}. Release metadata and migration '
        'integrity are checked locally. Protected PR checks must pass before the squash merge; '
        'the exact merged main commit must then pass final-main CI and image qualification '
        'before publication and the guarded s1 deployment.'
    )


def finish_qualified_release(directory, commit, qualify_only=False):
    if not qualify_only:
        run(['scripts/deploy_s1.sh', commit])
        return None
    saved = STATE_DIRECTORY / ('qualified-' + commit)
    saved.mkdir(parents=True, exist_ok=True, mode=0o700)
    for name in ('manifest.json', 'mirrors.json'):
        shutil.copyfile(directory / name, saved / name)
    milestone('Qualified release retained for an isolated rollout rehearsal: ' + str(saved))
    return saved


def open_or_update_pr(branch, title, body):
    existing = json.loads(run([
        'gh', 'pr', 'list', '--head', branch, '--base', 'main', '--state', 'open',
        '--limit', '1', '--json', 'number,url',
    ], capture=True))
    if existing:
        pr = existing[0]
        run(['gh', 'pr', 'edit', str(pr['number']), '--title', title, '--body', body])
        return pr
    url = run([
        'gh', 'pr', 'create', '--base', 'main', '--head', branch,
        '--title', title, '--body', body,
    ], capture=True)
    number = run(['gh', 'pr', 'view', url, '--json', 'number', '--jq', '.number'], capture=True)
    return {'number': int(number), 'url': url}


def wait_for_pr(pr, head, timeout_minutes):
    milestone(f'Waiting for protected checks on PR #{pr}')
    deadline = time.monotonic() + timeout_minutes * 60
    next_update = time.monotonic() + 300
    while time.monotonic() < deadline:
        result = subprocess.run(
            ['gh', 'pr', 'checks', str(pr), '--json', 'name,state,bucket,link'], cwd=ROOT,
            text=True, capture_output=True,
        )
        checks = json.loads(result.stdout) if result.stdout.strip() else []
        failed = [check for check in checks if check['bucket'] in ('fail', 'cancel')]
        if failed:
            summary = ', '.join(check['name'] for check in failed)
            raise ValueError(f'Protected PR checks failed: {summary} ({failed[0]["link"]})')
        if checks and all(check['bucket'] in ('pass', 'skipping') for check in checks):
            break
        if time.monotonic() >= next_update:
            pending = [check['name'] for check in checks if check['bucket'] == 'pending']
            milestone('PR checks still running' + (': ' + ', '.join(pending) if pending else ''))
            next_update = time.monotonic() + 300
        time.sleep(30)
    else:
        raise TimeoutError(f'Protected PR checks exceeded {timeout_minutes} minutes')
    current = run(['gh', 'pr', 'view', str(pr), '--json', 'headRefOid', '--jq', '.headRefOid'], capture=True)
    if current != head:
        raise ValueError('PR head changed while checks were running')
    run(['gh', 'pr', 'merge', str(pr), '--squash', '--match-head-commit', head])
    for _ in range(30):
        state = json.loads(run(['gh', 'pr', 'view', str(pr), '--json', 'state,mergeCommit'], capture=True))
        commit = (state.get('mergeCommit') or {}).get('oid')
        if state.get('state') == 'MERGED' and re.fullmatch(r'[0-9a-f]{40}', commit or ''):
            return commit
        time.sleep(2)
    raise TimeoutError('PR did not report its merge commit')


def find_main_ci(commit):
    for _ in range(60):
        runs = json.loads(run([
            'gh', 'run', 'list', '--commit', commit, '--branch', 'main', '--event', 'push',
            '--workflow', 'CI', '--limit', '1', '--json', 'databaseId,headSha,status,conclusion,url',
        ], capture=True))
        if runs and runs[0]['headSha'] == commit:
            return runs[0]
        time.sleep(5)
    raise TimeoutError('Final-main CI did not start within five minutes')


def wait_for_main_ci(run_id, timeout_minutes):
    milestone(f'Waiting internally for final-main CI run {run_id}')
    deadline = time.monotonic() + timeout_minutes * 60
    next_update = time.monotonic() + 300
    while time.monotonic() < deadline:
        state = json.loads(run([
            'gh', 'run', 'view', str(run_id), '--json', 'status,conclusion,url,jobs',
        ], capture=True))
        if state['status'] == 'completed':
            if state['conclusion'] != 'success':
                failed = [job['name'] for job in state['jobs'] if job.get('conclusion') not in ('success', 'skipped')]
                raise ValueError('Final-main CI failed: ' + ', '.join(failed) + f" ({state['url']})")
            return state
        if time.monotonic() >= next_update:
            active = [job['name'] for job in state['jobs'] if job['status'] == 'in_progress']
            milestone('CI still running' + (': ' + ', '.join(active) if active else ''))
            next_update = time.monotonic() + 300
        time.sleep(30)
    raise TimeoutError(f'Final-main CI exceeded {timeout_minutes} minutes')


def write_receipt(data):
    STATE_DIRECTORY.mkdir(parents=True, exist_ok=True)
    path = STATE_DIRECTORY / 'last-release-run.json'
    path.write_text(json.dumps(data, indent=2, sort_keys=True) + '\n')
    return path


def parse_args(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('bump', choices=('minor', 'major', 'super'))
    parser.add_argument('--summary', required=True, help='Concise release behavior for the commit and PR')
    parser.add_argument('--plugin-bump', choices=('minor', 'major', 'super'))
    parser.add_argument('--resume-version', help='Continue an already merged, untagged version after an interruption')
    parser.add_argument('--ci-timeout-minutes', type=int, default=90)
    parser.add_argument('--plan', action='store_true', help='Validate local configuration and print the bounded stages')
    parser.add_argument('--qualify-only', action='store_true',
                        help='Retain qualified images/manifest for a first-rollout rehearsal; do not deploy to s1')
    args = parser.parse_args(argv)
    if not 10 <= args.ci_timeout_minutes <= 180:
        parser.error('--ci-timeout-minutes must be between 10 and 180')
    if not args.summary.strip() or '\n' in args.summary:
        parser.error('--summary must be one non-empty line')
    if args.resume_version and not re.fullmatch(r'(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)', args.resume_version):
        parser.error('--resume-version must be x.y.z')
    return args


def main(argv=None):
    args = parse_args(argv)
    require_tools()
    branch = validate_checkout()
    run(['python3', 'scripts/ai_help/check_source_hashes.py'], capture=True)
    if args.plan:
        print(json.dumps({
            'branch': branch,
            'bump': args.bump,
            'stages': ['AI Coach source hashes', 'notice', 'prepare', 'commit', 'publish branch', 'protected PR checks',
                       'merge', 'publish main', 'final-main CI', 'publish tag',
                       'retain qualified release' if args.qualify_only else 'verified backup and s1 deployment', 'receipt'],
            'polling': 'internal; one progress line every five minutes',
        }, indent=2))
        return

    milestone('AI Coach source hashes match')

    notice_started = False
    completed = False
    receipt = {}
    stage = 'starting deployment notice'
    try:
        notice_id = run(['scripts/deployment_notice.sh', 'start'], capture=True)
        notice_started = True
        milestone('Preparation notice active')
        stage = 'preparing release metadata'
        run(['git', 'fetch', 'origin'])
        if not succeeds(['git', 'merge-base', '--is-ancestor', 'origin/main', 'HEAD']):
            raise ValueError('Update the release branch to include current origin/main')
        deployed = deployed_commit()
        prepare_version(args, deployed)
        verify_guide_if_changed(deployed)
        run(['git', 'diff', '--check'])
        version = (ROOT / 'VERSION').read_text().strip()
        title = f'Release {version}: {args.summary.strip().rstrip(".")}'
        run(['git', 'add', '-A'])
        staged = not succeeds(['git', 'diff', '--cached', '--quiet'])
        if staged:
            run(['git', 'commit', '-m', title])
        head = git('rev-parse', 'HEAD')
        if not succeeds(['git', 'merge-base', '--is-ancestor', 'origin/main', head]):
            raise ValueError('Release branch must contain current origin/main')
        milestone(f'Publishing release branch {branch} at {head[:12]}')
        stage = 'publishing release branch'
        for remote in EXPECTED_REMOTES:
            run(['git', 'push', remote, f'{head}:refs/heads/{branch}'])
        stage = 'protected PR checks and merge'
        pr = open_or_update_pr(branch, title, release_body(version, args.summary.strip()))
        milestone('Release PR: ' + pr['url'])
        merged = wait_for_pr(pr['number'], head, args.ci_timeout_minutes)
        milestone(f'PR #{pr["number"]} merged as {merged[:12]}')
        run(['git', 'fetch', 'origin'])
        run(['git', 'switch', 'main'])
        run(['git', 'merge', '--ff-only', 'origin/main'])
        if git('rev-parse', 'HEAD') != merged:
            raise ValueError('Local main does not match the protected merge commit')
        stage = 'publishing merged main'
        for remote in EXPECTED_REMOTES:
            run(['git', 'push', remote, f'{merged}:refs/heads/main'])
        stage = 'final-main CI and image qualification'
        ci = find_main_ci(merged)
        wait_for_main_ci(ci['databaseId'], args.ci_timeout_minutes)
        milestone('Final-main CI and immutable image qualification passed')
        with tempfile.TemporaryDirectory(prefix='dnr-release-') as directory:
            release_directory = Path(directory)
            run(['gh', 'run', 'download', str(ci['databaseId']), '--name', f'release-{merged}', '--dir', release_directory])
            mirrors = release_directory / 'mirrors.json'
            stage = 'publishing release tag and mirrors'
            run(['python3', 'scripts/mirror_release.py', release_directory / 'manifest.json',
                 '--publish', '--output', mirrors])
            stage = 'retaining qualified release' if args.qualify_only else 'verified backup and s1 deployment'
            qualified_directory = finish_qualified_release(release_directory, merged, args.qualify_only)
            mirror_state = json.loads(mirrors.read_text())
        receipt = {
            'status': 'qualified' if args.qualify_only else 'success', 'version': version, 'commit': merged,
            'pull_request': pr['url'], 'ci_run_id': ci['databaseId'],
            'remotes': mirror_state['remotes'], 'notice_id': notice_id,
        }
        if qualified_directory:
            receipt['qualified_directory'] = str(qualified_directory)
        receipt_path = write_receipt(receipt)
        completed = True
        milestone(f'{"Release qualified; s1 unchanged" if args.qualify_only else "Deployment verified"}; receipt {receipt_path}')
        print(json.dumps(receipt, indent=2, sort_keys=True))
    except (ValueError, TimeoutError, subprocess.CalledProcessError) as error:
        write_receipt({'status': 'failed', 'stage': stage, 'error': str(error)})
        raise
    finally:
        if notice_started and not completed:
            subprocess.run(['scripts/deployment_notice.sh', 'cancel'], cwd=ROOT)


if __name__ == '__main__':
    try:
        main()
    except (ValueError, TimeoutError, subprocess.CalledProcessError) as error:
        raise SystemExit(str(error))
