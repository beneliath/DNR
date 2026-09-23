#!/usr/bin/env python3
"""Offline MOED coaching pilot. Reads manual source; calls only a local Ollama API.

Uses Python's standard library. Does not render PHP, connect to MOED, or perform
UI actions. Page contexts are synthetic fixtures, not live observations.
"""

import argparse
from collections import Counter
from datetime import datetime, timezone
from html.parser import HTMLParser
import hashlib
import json
import math
from pathlib import Path
import re
import time
import unicodedata
from urllib.error import URLError
from urllib.parse import urlparse
from urllib.request import ProxyHandler, Request, build_opener


ROOT = Path(__file__).resolve().parents[2]
HERE = Path(__file__).resolve().parent
STOP = set("a an and are as at be but by can do for from how i in is it me my of on or our that the this to up us we what when where which with would you your please help walk through one time already then get ready done next yet".split())


def normalize(text):
    text = unicodedata.normalize("NFKD", text.lower())
    text = "".join(c for c in text if not unicodedata.combining(c))
    return re.sub(r"[^a-z0-9#]+", " ", text).strip()


def terms(text):
    return [word[:-1] if len(word) > 4 and word.endswith("s") else word
            for word in normalize(text).split() if word not in STOP]


class ManualParser(HTMLParser):
    """Mirror help.php's heading/FAQ boundaries and user-manual.js topic IDs."""

    def __init__(self):
        super().__init__()
        self.stack = []
        self.chapter = None
        self.current = None
        self.heading_depth = None
        self.topics = []
        self.ids = set()

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        ignored = (self.stack and self.stack[-1][1]) or (
            attrs.get("aria-hidden") == "true" or tag in {"script", "style", "svg", "button"}
            or "manual-current-role" in attrs.get("class", ""))
        self.stack.append((tag, bool(ignored)))
        if "data-manual-section" in attrs:
            self.chapter = (attrs["id"], len(self.stack))
        if self.chapter and not ignored and tag in {"h2", "h3", "h4", "summary"}:
            self.finish_topic()
            self.current = {"chapter": self.chapter[0], "title_parts": [], "parts": [], "id": attrs.get("id", "")}
            self.heading_depth = len(self.stack)
        if tag in {"br", "hr", "input", "img", "meta", "link", "source", "wbr"}:
            self.stack.pop()

    def handle_endtag(self, tag):
        indices = [i for i, item in enumerate(self.stack) if item[0] == tag]
        if not indices:
            return
        depth = indices[-1] + 1
        if depth == self.heading_depth:
            self.heading_depth = None
        if self.chapter and depth == self.chapter[1]:
            self.finish_topic()
            self.chapter = None
        self.stack = self.stack[:depth - 1]

    def handle_data(self, data):
        if not self.chapter or not self.current or (self.stack and self.stack[-1][1]):
            return
        if data.strip():
            key = "title_parts" if self.heading_depth else "parts"
            self.current[key].append(data.strip())

    def finish_topic(self):
        if not self.current:
            return
        topic = self.current
        topic["title"] = " ".join(topic.pop("title_parts"))
        topic["text"] = " ".join(topic.pop("parts"))
        base = topic["id"] or "manual-topic-" + topic["chapter"] + "-" + normalize(topic["title"]).replace(" ", "-")
        topic["id"] = base
        suffix = 2
        while topic["id"] in self.ids:
            topic["id"] = base + "-" + str(suffix)
            suffix += 1
        self.ids.add(topic["id"])
        topic["url"] = "help.php#" + topic["id"]
        self.topics.append(topic)
        self.current = None


def read_manual():
    raw = (ROOT / "src/help.php").read_text()
    # Use current checked-in manual prose without executing PHP/auth/database code.
    # Role-dependent static prose is retained; each fixture explicitly supplies role.
    source = re.sub(r"<\?php.*?\?>", lambda m: "MOED" if "echo htmlspecialchars($manual_brand," in m[0] else "", raw, flags=re.S)
    parser = ManualParser()
    parser.feed(source)
    parser.finish_topic()
    if len(parser.topics) < 50:
        raise ValueError("Manual extraction unexpectedly produced fewer than 50 topics")
    return parser.topics, hashlib.sha256(raw.encode()).hexdigest()


def retrieve(topics, query, count=4):
    # Transparent lexical baseline; no embedding service or vector database.
    documents = [Counter(terms(t["title"] + " " + t["text"])) for t in topics]
    query_terms = set(terms(query))
    average = sum(sum(d.values()) for d in documents) / len(documents)
    frequencies = {q: sum(q in d for d in documents) for q in query_terms}
    ranked = []
    for topic, document in zip(topics, documents):
        score = 0
        title_terms = set(terms(topic["title"]))
        for q in query_terms:
            tf = document[q]
            idf = math.log(1 + (len(documents) - frequencies[q] + 0.5) / (frequencies[q] + 0.5))
            score += idf * tf * 2.2 / (tf + 1.2 * (0.25 + 0.75 * sum(document.values()) / average))
            if q in title_terms:
                score += idf * 1.5
        if score:
            ranked.append((score, topic))
    return [t for _, t in sorted(ranked, key=lambda pair: pair[0], reverse=True)[:count]]


def request_payload(case, topics, model):
    history_query = " ".join(m["content"] for m in case.get("history", []) if m["role"] == "user")
    selected = retrieve(topics, history_query + " " + case["question"])
    if case['context'].get('role') == 'reviewer':
        # Authenticated role evidence must survive competing upload keywords.
        access = next((topic for topic in topics if topic['id'] == 'manual-topic-roles-roles-and-access'), None)
        if access:
            selected = [access] + [topic for topic in selected if topic['id'] != access['id']][:3]
    controls = case["context"].get("controls", [])
    schema = {
        "type": "object", "additionalProperties": False,
        "required": ["message", "target", "question", "sources"],
        "properties": {
            "message": {"type": "string"},
            "target": {"type": "string", "enum": [""] + [c["id"] for c in controls]},
            "question": {"type": "string"},
            "sources": {"type": "array", "items": {"type": "string", "enum": [t["id"] for t in selected] or [""]}, **({"maxItems": 0} if not selected else {})},
        },
    }
    message = {"question": case["question"], "current_page": case["context"], "manual_excerpts": selected}
    return {
        "model": model, "stream": True, "think": False, "keep_alive": "5m",
        "options": {"temperature": 0, "seed": 42, "num_ctx": 8192, "num_predict": 512},
        "format": schema,
        "messages": [{"role": "system", "content": (HERE / "coach-system.txt").read_text()}]
        + case.get("history", []) + [{"role": "user", "content": json.dumps(message)}],
    }, selected


def call_model(opener, endpoint, payload, timeout):
    request = Request(endpoint + "/api/chat", data=json.dumps(payload).encode(), headers={"Content-Type": "application/json"})
    start = time.monotonic()
    first = None
    pieces = []
    final = {}
    tool_calls = []
    with opener.open(request, timeout=timeout) as response:
        for line in response:
            item = json.loads(line)
            if item.get("error"):
                raise ValueError(item["error"])
            content = item.get("message", {}).get("content", "")
            tool_calls.extend(item.get("message", {}).get("tool_calls", []))
            if content and first is None:
                first = time.monotonic() - start
            pieces.append(content)
            if item.get("done"):
                final = item
    raw = "".join(pieces)
    if not final:
        raise ValueError("Ollama stream ended without a completion record")
    try:
        reply = json.loads(raw)
        parse_error = None
    except ValueError as error:
        reply = None
        parse_error = str(error)
    return {
        "raw": raw, "reply": reply, "parse_error": parse_error, "tool_calls": tool_calls,
        "seconds": round(time.monotonic() - start, 3),
        "first_token_seconds": round(first, 3) if first is not None else None,
        "done_reason": final.get("done_reason"),
        "load_seconds": round(final.get("load_duration", 0) / 1e9, 3),
        "prompt_tokens": final.get("prompt_eval_count"),
        "output_tokens": final.get("eval_count"),
        "tokens_per_second": round(final.get("eval_count", 0) * 1e9 / final["eval_duration"], 2) if final.get("eval_duration") else None,
    }


def checks(case, result, selected):
    reply = result["reply"]
    allowed_targets = {""} | {c["id"] for c in case["context"].get("controls", [])}
    allowed_sources = {t["id"] for t in selected}
    tests = {
        "response_shape": isinstance(reply, dict) and set(reply) == {"message", "target", "question", "sources"},
        "complete_response": result["done_reason"] == "stop",
        "no_tool_calls": not result["tool_calls"],
    }
    if not tests["response_shape"]:
        return tests
    tests["field_types"] = all(isinstance(reply[k], str) for k in ("message", "target", "question")) and isinstance(reply["sources"], list) and all(isinstance(s, str) for s in reply["sources"])
    if not tests["field_types"]:
        return tests
    tests["allowed_target"] = reply["target"] in allowed_targets
    tests["allowed_sources"] = set(reply["sources"]) <= allowed_sources
    tests["expected_target"] = reply["target"] in case["expected_targets"]
    if reply["target"]:
        labels = {c["id"]: c["label"] for c in case["context"].get("controls", [])}
        tests["names_highlighted_control"] = labels.get(reply["target"], "\x00").lower() in reply["message"].lower()
    tests["has_explanation"] = len(reply["message"].split()) >= 8
    tests["concise"] = len(reply["message"].split()) <= 130
    if case.get("expected_sources"):
        tests["retrieval_hit"] = bool(allowed_sources & set(case["expected_sources"]))
        if not case.get("clarification_only"):
            tests["cites_expected_topic"] = bool(set(reply["sources"]) & set(case["expected_sources"]))
    if case.get("requires_question"):
        tests["continues_walkthrough"] = bool(reply["question"].strip())
    text = reply["message"] + " " + reply["question"]
    for name, pattern in case.get("text_checks", {}).items():
        tests[name] = bool(re.search(pattern, text, re.I))
    for name, pattern in case.get("forbidden_text", {}).items():
        tests[name] = not re.search(pattern, text, re.I)
    tests["no_claimed_action"] = not re.search(r"\bI(?:'ve| have)?\s+(?:successfully\s+)?(?:saved|sent|deleted|archived|clicked|updated|changed|uploaded)\b", text, re.I)
    return tests


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--endpoint", default="http://127.0.0.1:11434")
    parser.add_argument("--model", default="qwen3:8b")
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--prepare-only", action="store_true")
    parser.add_argument("--case", action="append", default=[])
    parser.add_argument("--timeout", type=int, default=180)
    args = parser.parse_args()
    url = urlparse(args.endpoint)
    if url.scheme != "http" or url.hostname not in {"localhost", "127.0.0.1", "::1"} or url.username or url.password or url.path not in {"", "/"} or url.query or url.fragment:
        parser.error("Use a loopback Ollama endpoint (or an SSH forward to the test Mac)")
    topics, manual_hash = read_manual()
    cases = json.loads((HERE / "scenarios.json").read_text())
    known_ids = {t["id"] for t in topics}
    for case in cases:
        unknown = set(case.get("expected_sources", [])) - known_ids
        if unknown:
            raise ValueError(f"Stale manual source IDs in {case['id']}: {unknown}")
    if args.case:
        unknown = set(args.case) - {c["id"] for c in cases}
        if unknown:
            parser.error(f"Unknown cases: {unknown}")
        cases = [c for c in cases if c["id"] in args.case]
    report = {
        "created_at": datetime.now(timezone.utc).isoformat(), "model": args.model,
        "manual_sha256": manual_hash, "manual_topic_count": len(topics),
        "prompt_sha256": hashlib.sha256((HERE / "coach-system.txt").read_bytes()).hexdigest(),
        "scenarios_sha256": hashlib.sha256((HERE / "scenarios.json").read_bytes()).hexdigest(),
        "runner_sha256": hashlib.sha256(Path(__file__).read_bytes()).hexdigest(),
        "app_version": (ROOT / "VERSION").read_text().strip(),
        "synthetic_contexts": True, "prepare_only": args.prepare_only,
        "limitations": "Automated checks are smoke tests, not a factual-accuracy score. Review every answer. No live MOED UI integration; sequential requests only. First streamed JSON token is not time to a complete user-visible answer.",
        "cases": [],
    }
    args.output.parent.mkdir(parents=True, exist_ok=True)
    opener = build_opener(ProxyHandler({}))
    if not args.prepare_only:
        for api in ("version", "tags"):
            with opener.open(args.endpoint.rstrip("/") + "/api/" + api, timeout=10) as response:
                report["ollama_" + api] = json.load(response)
    for case in cases:
        payload, selected = request_payload(case, topics, args.model)
        record = {"id": case["id"], "question": case["question"], "context": case["context"], "review_for": case["review_for"], "retrieved": selected}
        if args.prepare_only:
            record["retrieval_hit"] = bool({t["id"] for t in selected} & set(case.get("expected_sources", []))) if case.get("expected_sources") else None
        else:
            try:
                record.update(call_model(opener, args.endpoint.rstrip("/"), payload, args.timeout))
                record["checks"] = checks(case, record, selected)
                record["smoke_checks_pass"] = all(record["checks"].values())
            except (URLError, TimeoutError, ValueError, KeyError, TypeError) as error:
                record.update(error=str(error), smoke_checks_pass=False)
        report["cases"].append(record)
        args.output.write_text(json.dumps(report, indent=2) + "\n")
        print(json.dumps({"case": case["id"], "prepared": args.prepare_only, "seconds": record.get("seconds"), "smoke_checks_pass": record.get("smoke_checks_pass"), "retrieval_hit": record.get("retrieval_hit"), "error": record.get("error")}), flush=True)
    if not args.prepare_only and not all(c.get("smoke_checks_pass") for c in report["cases"]):
        raise SystemExit(1)


if __name__ == "__main__":
    main()
