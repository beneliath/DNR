import { readdir } from 'node:fs/promises';
import { join } from 'node:path';
import { build } from 'esbuild';

const directory = 'src/assets/css/pages';
const sourceFiles = (await readdir(directory))
    .filter((file) => file.endsWith('.css') && !file.endsWith('.min.css'));

await Promise.all(sourceFiles.map((file) => build({
    entryPoints: [join(directory, file)],
    outfile: join(directory, file.replace(/\.css$/, '.min.css')),
    minify: true,
    legalComments: 'none',
})));
