'use strict';

// Exercise the same bundled module graph shipped to ordinary page scripts.
module.exports = require('esbuild').buildSync({
    entryPoints: [require.resolve('../../../src/assets/js/page-actions.js')],
    bundle: true,
    format: 'iife',
    write: false,
}).outputFiles[0].text;
