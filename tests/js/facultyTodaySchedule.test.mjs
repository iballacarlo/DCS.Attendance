import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { transform } from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const sourceUrl = new URL('../../resources/js/components/FacultyTodaySchedule.jsx', import.meta.url);
const { code } = await transform(await readFile(sourceUrl, 'utf8'), { loader: 'jsx', jsx: 'automatic', format: 'esm' });
const resolved = code.replace(/from "([^"]+)"/g, (_, specifier) => `from ${JSON.stringify(specifier.startsWith('.') ? new URL(`${specifier}.js`, sourceUrl).href : import.meta.resolve(specifier))}`);
const { default: FacultyTodaySchedule, shiftScheduleDate } = await import(`data:text/javascript;base64,${Buffer.from(resolved).toString('base64')}`);
const session = (overrides = {}) => ({ key: 'class-1', schedule_type: 'class', subject: 'DCIT 24', section: 'BSIT 1A', class_mode: 'lecture',
    start_time: '08:00:00', end_time: '10:00:00', time_in: null, time_out: null, status: 'upcoming', is_current: false, ...overrides });
const render = (sessions, summary = { finished: 0, total: 1 }) => renderToStaticMarkup(React.createElement(FacultyTodaySchedule,
    { sessions, summary, date: '2026-10-05', asOf: '2026-10-05T10:30:00+08:00' }));

test('finished cards show actual taps, all three completed steps and late/undertime warnings', () => {
    const html = render([session({ status: 'finished', time_in: '08:05', time_out: '09:50', late_minutes: 5, undertime_minutes: 10 })], { finished: 1, total: 2 });
    assert.match(html, /Finished: 1\/2 classes/);
    assert.match(html, /08:05 AM/);
    assert.match(html, /09:50 AM/);
    assert.match(html, /Late · 5 min/);
    assert.match(html, /Undertime · 10 min/);
    assert.equal((html.match(/class="is-recorded"/g) || []).length, 3);
    assert.match(html, /aria-label="Class attendance progress"/);
    assert.match(html, /<time dateTime="2026-10-05"/);
});

test('open and missing attendance never display a fabricated OUT or Finished step', () => {
    const html = render([session({ status: 'checked_in', time_in: '08:05', is_current: true })]);
    assert.match(html, /Checked in/);
    assert.match(html, /is-current/);
    assert.match(html, />Now</);
    assert.equal((html.match(/class="is-recorded"/g) || []).length, 1);
    assert.equal(html.includes('>Done<'), false);
    assert.match(html, /—/);
    const absent = render([session({ status: 'absent' })]);
    assert.match(absent, />Absent</);
    assert.equal(absent.includes('class="is-recorded"'), false);
    const review = render([session({ status: 'for_review', time_in: '08:10', time_out: '08:05', late_minutes: 10 })]);
    assert.match(review, />For review</);
    assert.equal(review.includes('>Done<'), false);
    assert.equal(review.includes('Late ·'), false);
});

test('consultation and lunch remain time-only cards with no attendance steps', () => {
    const html = render([session({ key: 'consultation', schedule_type: 'consultation', status: null }), session({ key: 'break', schedule_type: 'lunch_break', status: null })], { finished: 0, total: 0 });
    assert.match(html, /Consultation/);
    assert.match(html, /Lunch break/);
    assert.match(html, /No required classes today/);
    assert.equal(html.includes('Class attendance progress'), false);
    assert.equal(html.includes('faculty-session-status'), false);
});

test('excluded classes display a textual reason and empty days have a clear state', () => {
    const excluded = render([session({ status: 'not_required', exclusion: 'Approved leave' })], { finished: 0, total: 0 });
    assert.match(excluded, />Not required</);
    assert.match(excluded, /Approved leave/);
    assert.equal(excluded.includes('>Done<'), false);
    const empty = render([], { finished: 0, total: 0 });
    assert.match(empty, /No assigned sessions today/);
    assert.equal(empty.includes('faculty-session-card'), false);
});

test('date arrows cross month, leap day and year boundaries without local timezone shifts', () => {
    assert.equal(shiftScheduleDate('2026-11-01', -1), '2026-10-31');
    assert.equal(shiftScheduleDate('2026-12-31', 1), '2027-01-01');
    assert.equal(shiftScheduleDate('2028-03-01', -1), '2028-02-29');
});

test('historical schedule has accessible date controls and date-specific empty messages', () => {
    const html = renderToStaticMarkup(React.createElement(FacultyTodaySchedule, {
        sessions: [], date: '2026-10-04', today: '2026-10-05', onDateChange: () => {}, loading: true,
    }));
    assert.match(html, /Schedule &amp; attendance/);
    assert.match(html, /No assigned sessions on this date/);
    assert.match(html, /No required classes on this date/);
    assert.match(html, /aria-label="Previous day"/);
    assert.match(html, /aria-label="Next day"/);
    assert.match(html, /type="date"/);
    assert.match(html, /value="2026-10-04"/);
    assert.match(html, />Today</);
    assert.match(html, /aria-busy="true"/);
    assert.equal((html.match(/disabled=""/g) || []).length, 4);
});
