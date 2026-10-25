/* Run: node tests/language_switch.test.js. No browser or server required. */
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/js/i18n.js'), 'utf8');
const input = {name: 'title', type: 'text', value: 'Original'};
const form = {method: 'post', elements: [input]};
let handler;
let confirms = 0;
let accepted = false;
const link = {
    href: '?lang=en',
    getAttribute(name) { return {'data-language': 'en', 'data-unsaved': 'Discard changes?'}[name] || null; },
    addEventListener(name, fn) { if (name === 'click') handler = fn; },
};
const document = {forms: [form], querySelectorAll() { return [link]; }};
const window = {
    location: {href: 'http://localhost/index.php?cat=3&lang=zh-CN#cat-3'},
    confirm() { confirms += 1; return accepted; },
};
vm.runInNewContext(source, {document, window, URL});
input.value = 'Unsaved edit';
let prevented = false;
handler({preventDefault() { prevented = true; }});
assert.equal(prevented, true);
assert.equal(input.value, 'Unsaved edit');
assert.equal(link.href, '?lang=en');
accepted = true;
handler({preventDefault() { throw new Error('Unexpected cancellation'); }});
assert.equal(link.href, 'http://localhost/index.php?cat=3&lang=en#cat-3');
assert.equal(confirms, 2);
input.value = 'Original';
handler({preventDefault() { throw new Error('Unexpected cancellation'); }});
assert.equal(confirms, 2);
console.log('PASS: unsaved edit cancellation, input preservation and query/hash preservation');
