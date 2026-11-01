import {build} from 'esbuild';
import {readdir, readFile, writeFile} from 'node:fs/promises';
import {javascriptAssets, stylesheetAssets} from './frontend-assets.mjs';

const kind = process.argv[2];
if (!['js', 'css'].includes(kind)) {
    throw new Error('Usage: node scripts/build-frontend-assets.mjs js|css');
}

const options = (directory, extension, {name, ...settings}) => ({
    entryPoints: [`${directory}/${name}.${extension}`],
    outfile: `${directory}/${name}.min.${extension}`,
    minify: true,
    legalComments: 'none',
    ...settings,
});

if (kind === 'css') {
    for (const asset of stylesheetAssets) {
        await build(options('src/assets/css', 'css', asset));
    }
    const directory = 'src/assets/css/pages';
    const pages = (await readdir(directory)).filter(file => file.endsWith('.css') && !file.endsWith('.min.css')).sort();
    await Promise.all(pages.map(file => build(options(directory, 'css', {name: file.slice(0, -4)}))));
    console.log(`Built ${stylesheetAssets.length + pages.length} stylesheets.`);
} else {
    for (const asset of javascriptAssets) {
        await build(options('src/assets/js', 'js', asset));
    }
    await import('./build-map-assets.mjs');
    // Keep the generated map files free of trailing whitespace, including
    // whitespace inherited from bundled dependency string literals.
    for (const name of ['map', 'map-pin', 'audience-interaction-map']) {
        const path = `src/assets/js/${name}.min.js`;
        const content = await readFile(path, 'utf8');
        const normalized = content.replace(/[\t ]+$/gm, '');
        if (normalized !== content) await writeFile(path, normalized);
    }
    console.log(`Built ${javascriptAssets.length} page scripts and the shared map bundles.`);
}
