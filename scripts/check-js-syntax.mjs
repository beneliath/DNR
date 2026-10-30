import { readdirSync } from 'node:fs';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';

const sourceDirectory = join(process.cwd(), 'src', 'assets', 'js');
function sourceFilesIn(directory) {
    return readdirSync(directory, {withFileTypes: true}).flatMap(entry => {
        const path = join(directory, entry.name);
        if (entry.isDirectory()) return entry.name === 'vendor' ? [] : sourceFilesIn(path);
        return entry.isFile() && /\.(?:mjs|js)$/.test(entry.name) && !/\.min\.(?:mjs|js)$/.test(entry.name) ? [path] : [];
    });
}
const sourceFiles = sourceFilesIn(sourceDirectory).sort();

for (const sourceFile of sourceFiles) {
    const result = spawnSync(process.execPath, ['--check', sourceFile], { stdio: 'inherit' });
    if (result.status !== 0) process.exit(result.status || 1);
}

console.log(`JavaScript syntax checks passed for ${sourceFiles.length} source files.`);
