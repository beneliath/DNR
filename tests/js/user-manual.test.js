const test = require('node:test');
const assert = require('node:assert/strict');
const { queryTerms, matchingRanges, searchTopics, snippet } = require('../../src/assets/js/user-manual.js');

const checklist = {
    title: 'Every New Event Includes Tasks We Do Not Need',
    text: 'Standard Event Tasks are a customizable starting checklist. Archive a standard task for future events.'
};

test('the reported checklist query finds the specific topic without chapter-wide substring matches', () => {
    const unrelated = { title: 'New Tasks', text: 'New events include tasks. Do not archive records you need.' };
    assert.deepEqual(searchTopics([unrelated, checklist], 'Tasks we do not need'), [checklist]);
});

test('exact titles rank ahead of heading phrases and body mentions', () => {
    const body = { title: 'Standard Tasks', text: 'Financial closeout is required.' };
    const heading = { title: 'Review Financial Closeouts', text: 'Review ended events.' };
    const exact = { title: 'Financial Closeout', text: 'Finish the report.' };
    assert.deepEqual(searchTopics([body, heading, exact], 'financial closeout'), [exact, heading, body]);
});

test('all query words must be present in one topic, with word-prefix matching', () => {
    const archive = { title: 'Archived Tasks', text: 'Restore the checklist item.' };
    assert.deepEqual(searchTopics([archive], 'archive task'), [archive]);
    assert.deepEqual(searchTopics([archive], 'hive'), []);
    assert.deepEqual(searchTopics([{ title: 'Tasks', text: 'Work' }, { title: 'Restore', text: 'Records' }], 'restore tasks'), []);
    assert.deepEqual(searchTopics([archive], 'missing'), []);
    assert.deepEqual(searchTopics([archive], '  !!! '), []);
});

test('matching is case-insensitive and tolerates accents, repeated words, and punctuation', () => {
    assert.deepEqual(queryTerms('Café, CAFÉ / follow-up'), ['cafe', 'follow', 'up']);
    const topic = { title: 'Café follow-up', text: 'Résumé' };
    assert.deepEqual(searchTopics([topic], 'CAFE follow up resume'), [topic]);
});

test('highlight offsets preserve original spelling and punctuation and do not use query regexes', () => {
    const text = 'Café tasks, archived tasks; new events';
    const ranges = matchingRanges(text, queryTerms('CAFE task archive we'));
    assert.deepEqual(ranges.map(([start, end]) => text.slice(start, end)), ['Café', 'tasks', 'archived', 'tasks']);
    assert.deepEqual(matchingRanges(text, queryTerms('[] .*')), []);
    const shortWords = 'We do not need weekly notes';
    assert.deepEqual(matchingRanges(shortWords, queryTerms('we do not')).map(([start, end]) => shortWords.slice(start, end)), ['We', 'do', 'not']);
});

test('snippets show the matching passage without cutting words', () => {
    const text = 'Opening guidance. '.repeat(30) + 'Recovery codes protect your account. ' + 'More advice. '.repeat(30);
    const excerpt = snippet(text, ['recovery']);
    assert.ok(excerpt.includes('Recovery codes'));
    assert.ok(excerpt.startsWith('…'));
    assert.ok(excerpt.endsWith('…'));
    assert.ok(excerpt.length <= 222);
    assert.equal(snippet('Short complete paragraph.', ['short']), 'Short complete paragraph.');
});
