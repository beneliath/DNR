import {readFile, writeFile} from 'node:fs/promises';

const files = process.argv.slice(2);
if (files.length === 0) {
    throw new Error('Provide at least one generated asset path.');
}

for (const file of files) {
    const contents = await readFile(file, 'utf8');
    const normalized = contents.replace(/[\t ]+$/gm, '');
    if (normalized !== contents) {
        await writeFile(file, normalized, 'utf8');
    }
}
