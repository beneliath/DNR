#!/usr/bin/env python3
"""Fail release preparation when reviewed AI Coach source hashes are stale."""

import hashlib
import json
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / 'src'


def check_source_hashes(source: Path) -> list[str]:
    failures = []
    catalogs = (
        ('ai-coach-procedures.json', 'procedures'),
        ('ai-coach-forms.json', 'forms'),
    )
    for filename, key in catalogs:
        entries = json.loads((source / 'data' / filename).read_text())[key]
        for entry in entries.values() if isinstance(entries, dict) else entries:
            for relative_path, expected in entry['source_files'].items():
                check_file(source, relative_path, expected, filename, failures)

    application_map = json.loads((source / 'data/ai-coach-application-map.json').read_text())
    for relative_path, page in application_map['pages'].items():
        check_file(source, relative_path, page['sha256'], 'ai-coach-application-map.json', failures)
    return sorted(set(failures))


def check_file(source: Path, relative_path: str, expected: str, catalog: str, failures: list[str]) -> None:
    path = (source / relative_path).resolve()
    if not path.is_relative_to(source.resolve()) or not path.is_file():
        failures.append(f'{catalog}: missing source {relative_path}')
        return
    actual = hashlib.sha256(path.read_bytes()).hexdigest()
    if actual != expected:
        failures.append(f'{catalog}: stale hash for {relative_path}')


def main() -> int:
    failures = check_source_hashes(SOURCE)
    if failures:
        print('AI Coach source hash preflight failed:', file=sys.stderr)
        for failure in failures:
            print('  ' + failure, file=sys.stderr)
        print('Refresh reviewed procedure/form hashes and regenerate the application map before release.', file=sys.stderr)
        return 1
    print('AI Coach procedure, form, and application-map source hashes match.')
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
