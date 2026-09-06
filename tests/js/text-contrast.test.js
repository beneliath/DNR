'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const css = fs.readFileSync(require.resolve('../../src/assets/css/modern.css'), 'utf8');
function luminance(hex) {
    const rgb = hex.match(/../g).map(v => parseInt(v, 16) / 255).map(v => v <= 0.04045 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4);
    return rgb.reduce((sum, v, i) => sum + v * [0.2126, 0.7152, 0.0722][i], 0);
}
test('informative light subtle text meets ordinary-text contrast on every shared surface', () => {
    const color = token => css.match(new RegExp('--' + token + ': #([0-9a-f]{6});'))[1];
    const text = luminance(color('text-subtle'));
    for (const token of ['surface', 'surface-subtle', 'app-bg']) {
        const background = luminance(color(token));
        assert.ok((Math.max(background, text) + 0.05) / (Math.min(background, text) + 0.05) >= 4.5, token);
    }
});
