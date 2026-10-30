'use strict';

const {join} = require('node:path');

// Exercise exported browser modules through the same bundler as production.
module.exports = function browserModule(relativePath) {
    return require('esbuild').buildSync({
        entryPoints: [join(__dirname, '../../../src/assets/js', relativePath)],
        bundle: true,
        format: 'iife',
        globalName: 'BrowserModule',
        write: false,
    }).outputFiles[0].text;
};
