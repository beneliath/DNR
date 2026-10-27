import importlib.util
import json
from pathlib import Path
import subprocess
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('production_reader', ROOT/'scripts/ai_help/read_production_feedback.py')
reader = importlib.util.module_from_spec(spec)
spec.loader.exec_module(reader)


class ProductionReaderTest(unittest.TestCase):
    def test_historical_packet_and_provenance(self):
        fixture = subprocess.run(['php', '-r',
            'ob_start(); require $argv[1]; ob_end_clean(); echo json_encode([historicalRow(7,"audience.php","retired-step","editor"),historicalRow(8,"tasks.php","","reviewer")]);',
            str(ROOT/'tests/ai_coach_historical_feedback_test.php')], text=True, capture_output=True, check=True)
        data = {'source': {'host': '192.168.1.150', 'container': 'moed-web-1',
                          'environment': 'production', 'application_version': 'fixture'},
                'production_guidance_revision': 'a'*64, 'pending_rows': json.loads(fixture.stdout),
                'recent_reviews': []}
        packet = reader.build_packet(data)['packet']
        self.assertEqual(packet['pending_in_batch'], 2)
        self.assertEqual(packet['guidance_revision'], 'a'*64)
        self.assertEqual(packet['builder_source'], 'local_worktree')
        self.assertNotEqual(packet['builder_guidance_revision'], packet['guidance_revision'])
        rows = {r['id']: r for g in packet['groups'] for r in g['requests']}
        self.assertEqual(rows[7]['original_page'], 'audience.php')
        self.assertFalse(rows[7]['context_compatibility']['page_supported'])
        self.assertTrue(rows[8]['context_compatibility']['page_supported'])
        self.assertNotIn('user_id', json.dumps(packet))
        data['source']['environment'] = 'development'
        with self.assertRaises(ValueError):
            reader.build_packet(data)

    def test_ssh_failure_does_not_substitute_local_data(self):
        failure = subprocess.CalledProcessError(255, ['ssh'], stderr='fixture authentication failure')
        with patch.object(reader.subprocess, 'run', side_effect=failure) as run, patch('sys.argv', ['read_production_feedback.py']):
            with self.assertRaisesRegex(SystemExit, 'authentication failure'):
                reader.main()
        self.assertEqual(run.call_count, 1)
        self.assertEqual(run.call_args.args[0][0], 'ssh')


if __name__ == '__main__':
    unittest.main()
