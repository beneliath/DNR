"""Keep release preflight aligned with AI Coach's reviewed source contracts."""

import hashlib
import importlib.util
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

ROOT = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('check_source_hashes', ROOT / 'scripts/ai_help/check_source_hashes.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class AiCoachSourceHashesTest(unittest.TestCase):
    def test_current_source_hashes_match(self):
        self.assertEqual(module.check_source_hashes(ROOT / 'src'), [])

    def test_stale_source_is_reported_in_each_catalog(self):
        with TemporaryDirectory() as directory:
            source = Path(directory)
            (source / 'data').mkdir()
            page = source / 'reimbursements.php'
            page.write_text('before')
            digest = hashlib.sha256(page.read_bytes()).hexdigest()
            (source / 'data/ai-coach-procedures.json').write_text(json.dumps({
                'procedures': [{'source_files': {'reimbursements.php': digest}}],
            }))
            (source / 'data/ai-coach-forms.json').write_text(json.dumps({
                'forms': {'draft': {'source_files': {'reimbursements.php': digest}}},
            }))
            (source / 'data/ai-coach-application-map.json').write_text(json.dumps({
                'pages': {'reimbursements.php': {'sha256': digest}},
            }))
            self.assertEqual(module.check_source_hashes(source), [])
            page.write_text('after')
            self.assertEqual(len(module.check_source_hashes(source)), 3)


if __name__ == '__main__':
    unittest.main()
