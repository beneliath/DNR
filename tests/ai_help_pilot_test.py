import io
import json
from pathlib import Path
import sys
import unittest

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts/ai_help"))
import pilot


class FakeOllama:
    """Stream fixture only: never used to report model quality or performance."""

    def __init__(self, records):
        self.records = records

    def open(self, request, timeout):
        return io.BytesIO(b"\n".join(json.dumps(r).encode() for r in self.records))


class CoachingPilotTests(unittest.TestCase):
    def test_manual_topics_match_browser_anchor_rules_and_ignore_hidden_text(self):
        parser = pilot.ManualParser()
        parser.feed('''<section id="testing" data-manual-section>
          <h2>Testing</h2><p>Introduction.</p>
          <details><summary><span>Where’s Save?</span><i aria-hidden="true">+</i></summary>
          <p>Use <strong>Save Changes</strong>.</p></details>
          <h3>Where’s Save?</h3><p>Another topic.</p>
          <script>secret</script></section><p>Outside manual.</p>''')
        self.assertEqual([t["id"] for t in parser.topics], [
            "manual-topic-testing-testing", "manual-topic-testing-where-s-save",
            "manual-topic-testing-where-s-save-2"])
        self.assertEqual(parser.topics[1]["title"], "Where’s Save?")
        self.assertEqual(parser.topics[1]["text"], "Use Save Changes .")
        self.assertEqual(parser.topics[2]["text"], "Another topic.")

    def test_current_manual_supplies_each_grounded_scenario(self):
        topics, _ = pilot.read_manual()
        cases = json.loads((pilot.HERE / "scenarios.json").read_text())
        for case in cases:
            with self.subTest(case=case["id"]):
                payload, selected = pilot.request_payload(case, topics, "fixture-model")
                expected = set(case.get("expected_sources", []))
                if expected:
                    self.assertTrue(expected & {t["id"] for t in selected})
                # No callable application tools exist in the request.
                self.assertNotIn("tools", payload)

    def test_stream_preserves_answer_and_server_metrics(self):
        opener = FakeOllama([
            {"message": {"content": '{"message":"Use '}},
            {"message": {"content": 'Save Changes"}'}, "done": True,
             "done_reason": "stop", "eval_count": 20, "eval_duration": 2_000_000_000},
        ])
        result = pilot.call_model(opener, "http://127.0.0.1:11434", {}, 1)
        self.assertEqual(result["reply"]["message"], "Use Save Changes")
        self.assertEqual(result["tokens_per_second"], 10)
        self.assertIsNone(result["parse_error"])

    def test_incomplete_stream_is_not_a_successful_answer(self):
        with self.assertRaisesRegex(ValueError, "without a completion"):
            pilot.call_model(FakeOllama([{"message": {"content": "partial"}}]),
                             "http://127.0.0.1:11434", {}, 1)

    def test_invalid_json_keeps_raw_answer_for_review(self):
        result = pilot.call_model(FakeOllama([
            {"message": {"content": "not json"}, "done": True, "done_reason": "length"}
        ]), "http://127.0.0.1:11434", {}, 1)
        self.assertEqual(result["raw"], "not json")
        self.assertIsNotNone(result["parse_error"])
        self.assertIsNone(result["reply"])

    def test_checks_flag_fabricated_target_citation_and_completed_action(self):
        case = {"context": {"controls": []}, "expected_targets": [""]}
        result = {"done_reason": "stop", "tool_calls": [], "reply": {
            "message": "I have deleted the engagement successfully and removed its related records.",
            "target": "delete-button", "question": "", "sources": ["invented-topic"]}}
        checks = pilot.checks(case, result, [])
        self.assertFalse(checks["allowed_target"])
        self.assertFalse(checks["allowed_sources"])
        self.assertFalse(checks["no_claimed_action"])


if __name__ == "__main__":
    unittest.main()
