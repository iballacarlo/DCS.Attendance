import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { transform } from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const sourceUrl = new URL('../../resources/js/components/FacultyDashboardOverview.jsx', import.meta.url);
const { code } = await transform(await readFile(sourceUrl, 'utf8'), { loader: 'jsx', jsx: 'automatic', format: 'esm' });
const resolved = code.replace(/from "([^"]+)"/g, (_, specifier) => `from ${JSON.stringify(specifier.startsWith('.') ? new URL(`${specifier}.js`, sourceUrl).href : import.meta.resolve(specifier))}`);
const { default: Overview } = await import(`data:text/javascript;base64,${Buffer.from(resolved).toString('base64')}`);
const session = (overrides = {}) => ({ key: 'a', schedule_type: 'class', subject: 'DCIT 24', section: 'BSIT 1A', class_mode: 'lecture', start_time: '08:00', end_time: '10:00', status: 'upcoming', is_current: false, ...overrides });
const render = (props = {}) => renderToStaticMarkup(React.createElement(Overview, { date: '2026-10-28', ...props }));

test('school attendance shows personal times and explicit empty, IN and OUT states without aggregate counts', () => {
    const empty = render();
    assert.match(empty, /Not yet recorded/);
    assert.equal((empty.match(/<dd>—<\/dd>/g) || []).length, 2);
    assert.doesNotMatch(empty, /School arrivals today|Awaiting school OUT|<table/);
    assert.match(render({ entrance: [{ time_in: '06:42', time_out: null }] }), /Checked in/);
    const complete = render({ entrance: [{ time_in: '06:42', time_out: '16:08' }] });
    assert.match(complete, /Checked out/);
    assert.match(complete, /06:42 AM/);
    assert.match(complete, /04:08 PM/);
    assert.match(complete, /href="\/entrance"/);
});

test('current unfinished class wins over future, finished, excluded and auxiliary sessions', () => {
    const html = render({ sessions: [session({ subject: 'FUTURE', start_time: '14:00' }), session({ subject: 'CURRENT', status: 'awaiting_in', is_current: true }), session({ subject: 'FINISHED', status: 'finished', is_current: false }), session({ subject: 'EXCLUDED', status: 'not_required' }), session({ subject: 'CONSULT', schedule_type: 'consultation' })] });
    assert.match(html, />Current class</);
    assert.match(html, /CURRENT/);
    assert.match(html, /Awaiting IN/);
    assert.doesNotMatch(html, /FUTURE|FINISHED|EXCLUDED|CONSULT/);
});

test('earliest upcoming class includes early IN and school OUT never completes it', () => {
    const html = render({ entrance: [{ time_in: '07:00', time_out: '07:30' }], sessions: [session({ subject: 'LATER', start_time: '14:00' }), session({ subject: 'EARLY IN', start_time: '10:00', status: 'checked_in' })] });
    assert.match(html, />Next class</);
    assert.match(html, /EARLY IN/);
    assert.match(html, /Checked in/);
    assert.match(html, /Checked out/);
    assert.doesNotMatch(html, /LATER|Finished/);
});

test('finished or missed day differs from a day with no required classes', () => {
    assert.match(render({ sessions: [session({ status: 'finished' }), session({ status: 'for_review' }), session({ status: 'absent' })] }), /No more scheduled classes today/);
    assert.match(render({ sessions: [session({ status: 'not_required' }), session({ schedule_type: 'consultation' })] }), /No required classes today/);
    assert.match(render(), /No required classes today/);
});

test('shortcuts target existing faculty pages and unlinked users see only linking guidance', () => {
    const html = render();
    for (const path of ['/my-schedule', '/attendance/dtr', '/leave']) assert.ok(html.includes(`href="${path}"`));
    const missing = render({ linked: false, sessions: [session({ subject: 'PRIVATE' })], entrance: [{ time_in: '06:42' }] });
    assert.match(missing, /Contact the administrator/);
    assert.doesNotMatch(missing, /PRIVATE|06:42|School IN|Faculty shortcuts/);
});
