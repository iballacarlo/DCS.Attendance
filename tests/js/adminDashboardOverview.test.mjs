import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { transform } from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const sourceUrl = new URL('../../resources/js/components/AdminDashboardOverview.jsx', import.meta.url);
const { code } = await transform(await readFile(sourceUrl, 'utf8'), { loader: 'jsx', jsx: 'automatic', format: 'esm' });
const resolved = code.replace(/from "([^"]+)"/g, (_, specifier) => `from ${JSON.stringify(specifier.startsWith('.') ? new URL(`${specifier}.js`, sourceUrl).href : import.meta.resolve(specifier))}`);
const { default: Overview } = await import(`data:text/javascript;base64,${Buffer.from(resolved).toString('base64')}`);
const render = (props = {}) => renderToStaticMarkup(React.createElement(Overview, { date: '2026-10-29', ...props }));

test('admin status cards show plain class counts and one shared finished-class context', () => {
    const html = render({ report: { summary: { ended: 12, on_time: 5, late: 1, undertime: 2, missed: 3, incomplete: 2 }, trend: [] } });
    assert.doesNotMatch(html, /<small>\/12/);
    assert.match(html, /<strong>1<small> class<\/small><\/strong>/);
    assert.match(html, /<strong>2<small> classes<\/small><\/strong>/);
    assert.match(html, /Based on 12 finished classes in the last 7 days/);
    assert.doesNotMatch(html, /Class Records for Review/);
    assert.match(html, /both late and undertime/);
    assert.doesNotMatch(html, /Record completion|Faculty breakdown|Subject breakdown/);
});

test('empty dashboard shows honest zero counts and no invented trend or percentage', () => {
    const html = render({ report: { summary: { ended: 0 }, trend: [{ label: '2026-10-30', ended: 0, complete: 0 }] } });
    assert.equal((html.match(/<strong>0<small> classes<\/small><\/strong>/g) || []).length, 4);
    assert.match(html, /No finished classes in the last 7 days/);
    assert.match(html, /No school attendance recorded today/);
    assert.doesNotMatch(html, /NaN|Infinity|10\/30\/2026/);
});

test('recent school list is limited to five while counts and links retain the full scope', () => {
    const entrance = Array.from({ length: 8 }, (_, id) => ({ id, employee: { first_name: `Faculty${id}`, last_name: 'Test' }, time_in: '08:00', time_out: id ? null : '17:00' }));
    const html = render({ entrance, presentToday: 8, incompleteToday: 7, employeeCount: 20 });
    assert.match(html, /Latest 5 of 8 records/);
    assert.match(html, /Faculty4/);
    assert.doesNotMatch(html, /Faculty5/);
    assert.doesNotMatch(html, /Awaiting OUT|Pending School OUT|Faculty Checked In Today/);
    for (const href of ['/entrance', '/class-reports', '/attendance/dtr', '/employees', '/leave']) assert.ok(html.includes(`href="${href}"`));
});

test('daily chart sorts real ended days and describes record completion rather than punctuality', () => {
    const html = render({ report: { summary: { ended: 5 }, trend: [{ label: '2026-10-29', complete: 1, ended: 3 }, { label: '2026-10-28', complete: 2, ended: 2 }, { label: '2026-10-30', complete: 0, ended: 0 }] } });
    assert.match(html, /10\/28\/2026: 2\/2 finished classes have complete IN\/OUT records/);
    assert.match(html, /10\/29\/2026: 1\/3 finished classes have complete IN\/OUT records/);
    assert.doesNotMatch(html, /10\/30\/2026/);
    assert.ok(html.indexOf('10/28/2026') < html.indexOf('10/29/2026:'));
});

test('faculty present count replaces review and is the middle overview card', () => {
    const html = render({ employeeCount: 20, presentToday: 8, incompleteToday: 77, overview: { classes_today: 9 }, report: { summary: { incomplete: 3 }, trend: [] } });
    assert.match(html, /Faculty Present Today/);
    assert.match(html, /<strong>8<\/strong>/);
    assert.match(html, /<strong>9<\/strong>/);
    assert.doesNotMatch(html, /Class Records for Review|Pending Leave Requests|Awaiting OUT|Pending School OUT|<strong>77</);
    assert.ok(html.indexOf('COS Faculty') < html.indexOf('Faculty Present Today'));
    assert.ok(html.indexOf('Faculty Present Today') < html.indexOf('Scheduled Classes Today'));
});
