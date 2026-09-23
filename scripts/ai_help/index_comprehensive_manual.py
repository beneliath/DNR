#!/usr/bin/env python3
"""Index the actual Comprehensive Manual PDF, including numbered walkthrough text.

Uses PDF bookmarks for topic boundaries and page citations. It never reads help.php.
Run again whenever the installed PDF changes. Requires pypdf.
"""
from __future__ import annotations
import hashlib
import json
import re
import unicodedata
from pathlib import Path
from pypdf import PdfReader

ROOT = Path(__file__).resolve().parents[2]
PDF = ROOT / 'src/assets/docs/moed-comprehensive-user-manual.pdf'
OUTPUT = ROOT / 'src/data/ai-coach-comprehensive-manual.json'
CHAPTERS = ['orientation', 'roles', 'dashboard', 'booking-pipeline', 'engagements',
            'organizations-contacts', 'work-queue', 'chron-mail', 'map-calendar',
            'profile-security', 'mattermost', 'administration', 'troubleshooting']


def compact(value: str) -> str:
    return re.sub(r'\s+', ' ', value).strip()


def slug(value: str) -> str:
    value = unicodedata.normalize('NFKD', value).encode('ascii', 'ignore').decode().lower()
    return re.sub(r'[^a-z0-9#]+', '-', value).strip('-')


def flatten(items):
    for item in items:
        if isinstance(item, list):
            yield from flatten(item)
        else:
            yield item


def build():
    reader = PdfReader(PDF)
    outlines = list(flatten(reader.outline))
    by_page = {}
    for item in outlines:
        page = reader.get_destination_page_number(item) + 1
        by_page.setdefault(page, []).append(compact(item.title))
    topics = {}
    current = None
    chapter = ''
    for number, page in enumerate(reader.pages, 1):
        raw = page.extract_text() or ''
        # Running navigation is drawn after page content in this authored PDF.
        body = re.split(r'\nMOED\n', raw, maxsplit=1)[0]
        match = re.match(r'CHAPTER\s+(\d+)', body)
        if match:
            chapter_number = int(match.group(1))
            chapter = CHAPTERS[chapter_number - 1] if 1 <= chapter_number <= len(CHAPTERS) else ''
        if 'APPENDIX A' in body[:100]:
            chapter = 'operator-appendix'
        if not chapter:
            continue
        body = compact(re.sub(r'^CHAPTER\s+\d+(?: / ILLUSTRATED WALKTHROUGH)?\s*', '', body))
        marks = []
        for title in by_page.get(number, []):
            position = body.find(title)
            if position >= 0:
                marks.append((position, title))
        marks = sorted(set(marks))
        if current and (not marks or marks[0][0] > 0):
            continuation = body[:marks[0][0]] if marks else body
            topics[current]['text'] = compact(topics[current]['text'] + ' ' + continuation)
            topics[current]['end_page'] = number
        for index, (start, title) in enumerate(marks):
            end = marks[index + 1][0] if index + 1 < len(marks) else len(body)
            base = 'manual-topic-' + chapter + '-' + slug(title)
            current = base
            suffix = 2
            while current in topics:
                current = base + '-' + str(suffix)
                suffix += 1
            topics[current] = {'id': current, 'chapter': chapter, 'title': title,
                               'page': number, 'end_page': number,
                               'text': body[start + len(title):end].strip()}
    data = {'document': {'title': str(reader.metadata.title), 'subject': str(reader.metadata.subject),
                         'path': 'assets/docs/moed-comprehensive-user-manual.pdf',
                         'sha256': hashlib.sha256(PDF.read_bytes()).hexdigest(),
                         'pages': len(reader.pages), 'index_version': 1},
            'topics': topics}
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    OUTPUT.write_text(json.dumps(data, ensure_ascii=False, indent=2) + '\n')
    print(f'Indexed {len(topics)} PDF topics across {len(reader.pages)} pages into {OUTPUT.relative_to(ROOT)}')


if __name__ == '__main__':
    build()
