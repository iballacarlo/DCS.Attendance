import test from 'node:test';
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { displayInputDate, inputDateError, parseInputDate } from '../../resources/js/lib/dateInputValue.js';

const compiled = await build({ entryPoints: [fileURLToPath(new URL('../../resources/js/components/DateInput.jsx', import.meta.url))],
    bundle: true, write: false, format: 'esm', jsx: 'automatic', packages: 'external', loader: { '.css': 'empty' } });
const code = compiled.outputFiles[0].text.replace(/from "([^"]+)"/g, (_, name) => `from ${JSON.stringify(import.meta.resolve(name))}`);
const { default: DateInput } = await import(`data:text/javascript;base64,${Buffer.from(code).toString('base64')}`);

test('dates display month first and preserve ISO request values, including midyear 2027', () => {
    assert.equal(displayInputDate('2027-06-14'), '06/14/2027');
    assert.equal(parseInputDate('07/24/2027'), '2027-07-24');
    assert.equal(displayInputDate('2027-06-14T15:30', true), '06/14/2027 15:30');
    assert.equal(parseInputDate('06/14/2027 15:30', true), '2027-06-14T15:30');
    assert.equal(parseInputDate(''), '');
});

test('invalid dates, reversed date format, bounds, and invalid times are rejected', () => {
    for (const date of ['14/06/2027', '02/29/2027', '04/31/2027', '6/14/2027']) assert.equal(parseInputDate(date), null);
    assert.equal(parseInputDate('02/29/2028'), '2028-02-29');
    assert.equal(parseInputDate('06/14/2027 24:00', true), null);
    assert.equal(parseInputDate('06/14/2027 12:60', true), null);
    assert.equal(inputDateError('06/14/2027', '2026-01-01', '2027-12-31'), '');
    assert.match(inputDateError('01/01/2028', undefined, '2027-12-31'), /12\/31\/2027/);
    assert.equal(inputDateError('10/30/2026 23:59', undefined, '2026-10-30T23:59', true), '');
    assert.notEqual(inputDateError('10/31/2026 00:00', undefined, '2026-10-30T23:59', true), '');
});

test('visible input stays MM/DD/YYYY with an accessible calendar button', () => {
    const html = renderToStaticMarkup(React.createElement(DateInput, { value: '2027-06-14', min: '2026-01-01', max: '2027-12-31', required: true }));
    assert.match(html, /type="text"[^>]*value="06\/14\/2027"/);
    assert.match(html, /placeholder="MM\/DD\/YYYY"/);
    assert.match(html, /aria-label="Choose date"/);
    assert.match(html, /class="date-input-native"[^>]*aria-hidden="true"/);
    assert.match(html, /max="2027-12-31"/);
});
