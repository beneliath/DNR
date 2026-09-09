import {build} from 'esbuild';
import {createHash} from 'node:crypto';
import {readFile} from 'node:fs/promises';

// MapLibre 6 publishes a separate module worker. Bundle its shared imports
// locally and fingerprint the URL so the worker always matches the map code.
const worker = 'src/assets/js/maplibre-worker.min.js';
await build({entryPoints: ['node_modules/maplibre-gl/dist/maplibre-gl-worker.mjs'],
    bundle: true, format: 'esm', minify: true, legalComments: 'none', outfile: worker});
const hash = createHash('sha256').update(await readFile(worker)).digest('hex').slice(0, 12);
for (const page of ['map', 'map-pin']) {
    await build({entryPoints: [`src/assets/js/${page}.js`], bundle: true, format: 'iife',
        minify: true, legalComments: 'none', outfile: `src/assets/js/${page}.min.js`,
        define: {
            DNR_MAPLIBRE_WORKER_URL: JSON.stringify(`/assets/js/maplibre-worker.min.js?v=${hash}`),
            // Classic page bundles explicitly set a same-origin worker URL;
            // MapLibre's direct-browser ESM URL discovery is unused here.
            'import.meta.url': '""'
        }});
}
