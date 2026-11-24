"""Qualify a candidate's migrations against each account's verified restore."""
from contextlib import contextmanager
import datetime
import importlib.util
import io
from pathlib import Path
import subprocess
import tarfile
import tempfile

from deployment_backup import migrate_isolated_restore, query, sha

LEDGER_SQL = 'SELECT migration_name, checksum, state FROM dnr.schema_migrations ORDER BY migration_name'


def parse_migration_ledger(rows):
    applied = {}
    for row in rows.splitlines():
        name, checksum, state = row.split('\t')
        if state != 'applied' or name in applied:
            raise ValueError('Restore contains an incomplete or duplicate migration: ' + name)
        applied[name] = checksum
    return applied


@contextmanager
def candidate_migration_verifier(root, previous_commit, release):
    """Load immutable candidate inputs without changing the deployed checkout."""
    commit = release['commit']
    expected = release['migrations']
    changed = subprocess.check_output([
        'git', 'diff', '--name-only', previous_commit, commit, '--',
        'migrations', 'scripts/migrate.sh', 'docker/mysql.Dockerfile',
        'docker/mysql-encryption-entrypoint.sh', 'docker/mysql-encryption.cnf',
        'docker/component_keyring_file.cnf', 'docker/mysqld.my',
    ], cwd=root, text=True)
    baseline_changed = subprocess.check_output([
        'git', 'diff', '--name-only', previous_commit, commit, '--', 'scripts/test_account_*.php',
        'scripts/test_account_baseline_refresh.py',
    ], cwd=root, text=True)

    with tempfile.TemporaryDirectory(prefix='dnr-release-schema-') as temporary:
        candidate = Path(temporary)
        archive = subprocess.check_output(['git', 'archive', commit, 'migrations', 'scripts'], cwd=root)
        with tarfile.open(fileobj=io.BytesIO(archive)) as contents:
            contents.extractall(candidate, filter='data')
        runner = candidate / 'scripts/migrate.sh'
        migrations = candidate / 'migrations'
        ordered = [line for line in (migrations / 'order.txt').read_text().splitlines()
                   if line and not line.startswith('#')]
        if len(ordered) != len(expected) or set(ordered) != set(expected) or any(
                sha(migrations / name) != expected[name] for name in ordered):
            raise ValueError('Staged migration sources differ from the qualified release manifest')
        image = release['images']['app']

        def verify(restored, key):
            applied = parse_migration_ledger(query(restored, LEDGER_SQL))
            # The migrator supports sealing the original pre-ledger baseline.
            legacy = '20260813_baseline.sql'
            if any(expected.get(name) != checksum and not
                   (name == legacy and name in expected and checksum == '0' * 64)
                   for name, checksum in applied.items()):
                raise ValueError('Restore migration ledger conflicts with the candidate for ' + key)
            schema_rehearsed = bool(changed) or applied != expected
            if schema_rehearsed:
                migrate_isolated_restore(restored, migrations, runner, require_encryption=True)
                if parse_migration_ledger(query(restored, LEDGER_SQL)) != expected:
                    raise ValueError('Candidate migration rehearsal is incomplete for ' + key)
            result = dict(account_key=key, application_image=image, commit=commit,
                          migration_checksums=expected, migration_runner_sha256=sha(runner), schema_rehearsed=schema_rehearsed,
                          verified_at=datetime.datetime.now(datetime.timezone.utc).isoformat())
            if key == 'test-account' and (schema_rehearsed or baseline_changed):
                spec = importlib.util.spec_from_file_location(
                    '_candidate_test_account_baseline', candidate / 'scripts/test_account_baseline_refresh.py')
                operator = importlib.util.module_from_spec(spec)
                spec.loader.exec_module(operator)
                result['approved_baseline'] = operator.qualify_release_baseline(
                    restored, root, image, release['images']['database'], (migrations, runner))
            return result

        yield verify
