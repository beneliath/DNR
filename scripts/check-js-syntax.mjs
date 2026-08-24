import { readdirSync } from 'node:fs';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';

const sourceDirectory = join(process.cwd(), 'src', 'assets', 'js');
const sourceFiles = readdirSync(sourceDirectory)
    .filter((file) => file.endsWith('.js') && !file.endsWith('.min.js'))
    .sort();

for (const sourceFile of sourceFiles) {
    const sourcePath = join(sourceDirectory, sourceFile);
    const result = spawnSync(process.execPath, ['--check', sourcePath], { stdio: 'inherit' });
    if (result.status !== 0) process.exit(result.status || 1);
}

console.log(`JavaScript syntax checks passed for ${sourceFiles.length} source files.`);
