import { copyFile, mkdir, readFile } from 'node:fs/promises';
import { join } from 'node:path';

const source = 'node_modules/pdfjs-dist';
const packageInfo = JSON.parse(await readFile(join(source, 'package.json'), 'utf8'));
const target = join('src/assets/js/vendor', `pdfjs-${packageInfo.version}`);
await mkdir(target, { recursive: true });
for (const filename of ['pdf.min.mjs', 'pdf.worker.min.mjs']) {
    await copyFile(join(source, 'build', filename), join(target, filename));
}
await copyFile(join(source, 'LICENSE'), join(target, 'LICENSE'));
