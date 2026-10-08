'use strict';
const vm = require('node:vm');
const fs = require('node:fs');
const bootstrap = fs.readFileSync(require.resolve('../../../src/assets/js/account-context.js'), 'utf8');

// Model renderPageHead: the trusted account bootstrap loads before page scripts.
exports.runInNewContext = (source, context) => {
    if (context.window) {
        context.window.location ||= new URL('http://localhost/');
        context.window.location.href ||= 'http://localhost/';
        context.window.location.pathname ||= new URL(context.window.location.href).pathname;
        for (const name of ['localStorage', 'sessionStorage']) {
            if (!context.window[name] && context[name]) context.window[name] = context[name];
        }
        context.URL ||= URL;
        vm.runInNewContext(bootstrap, context);
    }
    return vm.runInNewContext(source, context);
};
