#!/usr/bin/env node
// Refresh the Markdown rendering snapshot without fetching any dependencies.
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const { marked } = require(process.env.MARKED_MODULE || 'marked');
const root = path.resolve(__dirname, '../..');
const source = fs.readFileSync(path.join(root, 'README.md'));
const rendered = {
  source: 'README.md',
  sha256: crypto.createHash('sha256').update(source).digest('hex'),
  bytes: source.length,
  html: marked.parse(source.toString('utf8'), { gfm: true }),
};
fs.writeFileSync(path.join(root, 'docs/user-manual/readme-appendix.json'), JSON.stringify(rendered, null, 2) + '\n');
console.log(`Rendered complete README.md (${source.length} bytes; SHA-256 ${rendered.sha256})`);
