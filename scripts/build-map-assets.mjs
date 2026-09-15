import {build} from 'esbuild';
import {createHash} from 'node:crypto';
import {readFile, readdir, unlink} from 'node:fs/promises';

// MapLibre 6 publishes a separate module worker. Bundle its shared imports
// locally and fingerprint the URL so the worker always matches the map code.
const worker = 'src/assets/js/maplibre-worker.min.js';
await build({entryPoints: ['node_modules/maplibre-gl/dist/maplibre-gl-worker.mjs'],
    bundle: true, format: 'esm', minify: true, legalComments: 'none', outfile: worker});
const hash = createHash('sha256').update(await readFile(worker)).digest('hex').slice(0, 12);
// Content-addressed shared chunks let both pages reuse the same MapLibre code.
// Remove obsolete chunks so the production image and asset manifest stay bounded.
for (const file of await readdir('src/assets/js')) {
    if (/^map-shared-[A-Z0-9]+\.min\.js$/.test(file)) await unlink(`src/assets/js/${file}`);
}
await build({entryPoints: ['src/assets/js/map.js', 'src/assets/js/map-pin.js'],
        bundle: true, format: 'esm', splitting: true, outdir: 'src/assets/js',
        entryNames: '[name].min', chunkNames: 'map-shared-[hash].min',
        minify: true, legalComments: 'none',
        define: {
            DNR_MAPLIBRE_WORKER_URL: JSON.stringify(`/assets/js/maplibre-worker.min.js?v=${hash}`),
            // Page bundles explicitly set a same-origin worker URL;
            // MapLibre's direct-browser ESM URL discovery is unused here.
            'import.meta.url': '""'
        }});
