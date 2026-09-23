#!/usr/bin/env python3
"""Read static application UI metadata, never live records or field values.

This inventory supplies labels and navigation evidence, not verified workflows.
Runtime visibility and form transitions still need browser verification.
"""
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
import re

ROOT = Path(__file__).resolve().parents[2]
OUTPUT = ROOT / 'src/data/ai-coach-application-map.json'

class Inventory(HTMLParser):
    def __init__(self):
        super().__init__()
        self.items = []
        self.active = None
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag in ['label', 'button', 'a', 'h1', 'h2', 'h3', 'legend']:
            self.active = {'kind': tag, 'label': '', **{k: attrs[k] for k in ['id', 'for', 'href', 'name'] if k in attrs}}
        if tag == 'input' and attrs.get('type') == 'submit':
            self.items.append({'kind': 'submit', 'label': attrs.get('value', ''), 'name': attrs.get('name', '')})
    def handle_data(self, data):
        if self.active is not None:
            self.active['label'] += data
    def handle_endtag(self, tag):
        if self.active is not None and self.active['kind'] == tag:
            item = self.active
            item['label'] = re.sub(r'\s+', ' ', item['label']).strip()
            if item['label'] and len(item['label']) <= 160:
                href = item.get('href', '')
                if href and not re.fullmatch(r'[a-z_]+\.php(?:#[a-z0-9_-]+)?', href):
                    item.pop('href', None)  # Never emit partial dynamic record URLs.
                self.items.append(item)
            self.active = None

def build():
    pages = {}
    for path in sorted((ROOT / 'src').glob('*.php')):
        source = path.read_text()
        if 'renderPageHead(' not in source or path.name in ['help.php', 'ai_coach_requests.php']:
            continue
        parser = Inventory()
        parser.feed(re.sub(r'<\?(?:php|=).*?\?>', '', source, flags=re.S))
        if parser.items:
            pages[path.name] = {'sha256': hashlib.sha256(path.read_bytes()).hexdigest(), 'controls': parser.items}
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(json.dumps({'index_version': 1, 'pages': pages}, indent=2) + '\n')
    print(f'Indexed static UI labels from {len(pages)} application pages.')

if __name__ == '__main__':
    build()
