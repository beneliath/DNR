"""Validate effective backup budgets and timeout ordering without starting services."""
import json
import os
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MIB = 1024 ** 2
GIB = 1024 ** 3


class BackupCapacityConfigurationTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        environment = {key: value for key, value in os.environ.items()
                       if not key.startswith(('DNR_', 'COMPOSE_', 'MYSQL_'))}
        result = subprocess.run([
            'docker', 'compose', '--env-file', '/dev/null', '-p', 'backup-capacity-contract',
            '-f', str(ROOT / 'docker-compose.yaml'), '--profile', 'maintenance',
            'config', '--format', 'json',
        ], env=environment, check=True, capture_output=True, text=True)
        cls.services = json.loads(result.stdout)['services']

    def temporary_bytes(self, name):
        mount = next(item for item in self.services[name]['tmpfs'] if item.startswith('/tmp:'))
        amount, suffix = re.search(r'size=(\d+)([mg])', mount).groups()
        return int(amount) * {'m': MIB, 'g': GIB}[suffix]

    def test_export_and_restore_have_consistent_limits(self):
        for name in ['web', 'backup', 'maintenance']:
            with self.subTest(service=name):
                self.assertEqual(int(self.services[name]['environment']['DNR_DATABASE_BACKUP_MAX_BYTES']), 2 * GIB)

    def test_resources_cover_archive_copies_and_process_overhead(self):
        for name, copies in [('backup', 2), ('web', 1), ('maintenance', 1)]:
            with self.subTest(service=name):
                temporary = self.temporary_bytes(name)
                self.assertGreaterEqual(temporary, copies * 2 * GIB + 512 * MIB)
                self.assertGreaterEqual(int(self.services[name]['mem_limit']), temporary + 512 * MIB)

    def test_outer_timeouts_outlast_inner_export(self):
        def number(path, pattern):
            return int(re.search(pattern, (ROOT / path).read_text()).group(1))
        exporter = number('scripts/backup_endpoint.php', r'set_time_limit\((\d+)\)')
        apache = number('docker/apache-backup.conf', r'max_execution_time (\d+)')
        client = number('src/database_backup_client.php', r'CURLOPT_TIMEOUT => (\d+)')
        web = number('src/database_maintenance.php', r'set_time_limit\((\d+)\)')
        ingress = number('docker/apache-ingress.conf', r'ProxyPass "/database_maintenance.php"[^\n]*? timeout=(\d+)')
        browser = number('src/assets/js/database-backup.js', r'Date.now\(\) - started >= (\d+)') / 1000
        self.assertGreaterEqual(exporter, 900)
        self.assertGreaterEqual(apache, exporter)
        self.assertTrue(exporter < client < web < ingress < browser)
        for path in ['scripts/account_lifecycle_local.py', 'scripts/configure_local_account_gateway.py']:
            with self.subTest(gateway=path):
                routes = [line for line in (ROOT / path).read_text().splitlines()
                          if 'ProxyPass ' in line and 'database_maintenance.php' in line]
                self.assertTrue(routes)
                for route in routes:
                    self.assertEqual(int(re.search(r' timeout=(\d+)', route).group(1)), ingress)


if __name__ == '__main__':
    unittest.main()
