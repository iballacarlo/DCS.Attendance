import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { build } from 'esbuild';
import { fileURLToPath } from 'node:url';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const sourceUrl = new URL('../../resources/js/components/WeeklyClassAttendance.jsx', import.meta.url);
const compiled = await build({ entryPoints: [fileURLToPath(sourceUrl)], bundle: true, write: false, format: 'esm', jsx: 'automatic', packages: 'external' });
const resolved = compiled.outputFiles[0].text.replace(/from "([^"]+)"/g, (_, specifier) => `from ${JSON.stringify(import.meta.resolve(specifier))}`);
const { default: WeeklyClassAttendance, weeklyStatusKey } = await import(`data:text/javascript;base64,${Buffer.from(resolved).toString('base64')}`);
const row = (overrides = {}) => ({ key: 'row-1', date: '2026-11-10', faculty: 'Faculty One', subject: 'ITEC 116', section: 'BSIT 4A', room: 'CL 1',
    scheduled_start: '08:00:00', scheduled_end: '10:00:00', time_in: null, time_out: null, late_minutes: null, early_departure_minutes: null, status: 'upcoming', ...overrides });
const render = (rows) => renderToStaticMarkup(React.createElement(WeeklyClassAttendance, {
    rows, weekStart: '2026-11-09', moveWeek: (value) => value, onWeekChange: () => {},
}));

const renderAdminWithoutFaculty = () => renderToStaticMarkup(React.createElement(WeeklyClassAttendance, {
    rows: [], weekStart: '2026-11-09', moveWeek: (value) => value, onWeekChange: () => {},
    admin: true, employees: [{ id: 1, first_name: 'Faculty', last_name: 'One' }],
    selectedEmployeeId: '', onFacultySelect: () => {}, facultySelectionRequired: true,
}));

test('weekly attendance shows continuous full-color status blocks with no time columns', () => {
    const html = render([row(), row({ key: 'row-2', date: '2026-11-12', subject: 'DCIT 24' })]);
    assert.match(html, /<table class="weekly-plot-attendance">/);
    assert.match(html, /<th class="weekly-day-header-cell">/);
    assert.match(html, />Tuesday</);
    assert.match(html, />Thursday</);
    assert.equal(html.includes('>Monday<'), false);
    assert.equal((html.match(/weekly-plot-session status-upcoming/g) || []).length, 2);
    assert.match(html, /<strong>BSIT 4A<\/strong><span>CL 1<\/span>/);
    assert.match(html, /08:00 AM – 10:00 AM/);
    assert.match(html, /aria-label="Previous week"/);
    assert.match(html, /aria-label="Current week"/);
    assert.match(html, /aria-label="Next week"/);
    assert.equal(html.includes('>Previous<'), false);
    assert.equal(html.includes('>Current week<'), false);
    assert.equal(html.includes('>Time<'), false);
    assert.equal(html.includes('>From<'), false);
    assert.match(html, /IN <strong>—<\/strong>/);
    assert.match(html, /Undertime: 0 min/);
    assert.equal(html.includes('weekly-attendance-grid'), false);
    assert.equal(html.includes('No scheduled classes or activities'), false);
});

test('weekly attendance has honest empty state and accessible expandable rows', async () => {
    assert.match(render([]), /No scheduled classes this week/);
    const html = render([row()]);
    assert.match(html, /weekly-plot-attendance-scroll/);
    assert.match(html, /<button type="button"/);
    assert.match(html, /aria-expanded="false"/);
    const source = await readFile(sourceUrl, 'utf8');
    assert.match(source, /row\.schedule_type === 'class'/);
    assert.match(source, /weekly-plot-day-blocks/);
    assert.match(source, /status-\$\{visualStatus\}/);
    assert.match(source, /Correct missing OUT/);
});

test('admin weekly attendance waits for a faculty selection instead of mixing schedules', () => {
    const html = renderAdminWithoutFaculty();
    assert.match(html, /Faculty/);
    assert.match(html, /Select a faculty member to view weekly class attendance\./);
    assert.equal(html.includes('weekly-plot-attendance">'), false);
});

test('weekly attendance excludes consultation and lunch rows defensively', () => {
    const html = render([
        row(),
        row({ key: 'consultation', subject: 'Consultation', schedule_type: 'consultation' }),
        row({ key: 'lunch', subject: 'Lunch break', schedule_type: 'lunch_break' }),
    ]);
    assert.match(html, /ITEC 116/);
    assert.equal(html.includes('Consultation'), false);
    assert.equal(html.includes('Lunch break'), false);
});

test('weekly attendance status mapping keeps all monitoring states distinct', () => {
    assert.equal(weeklyStatusKey(row({ status: 'complete', late_minutes: 0, early_departure_minutes: 0 })), 'on-time');
    assert.equal(weeklyStatusKey(row({ status: 'complete', late_minutes: 5, early_departure_minutes: 0 })), 'late');
    assert.equal(weeklyStatusKey(row({ status: 'complete', late_minutes: 0, early_departure_minutes: 5 })), 'undertime');
    assert.equal(weeklyStatusKey(row({ status: 'complete', late_minutes: 5, early_departure_minutes: 5 })), 'late-undertime');
    assert.equal(weeklyStatusKey(row({ status: 'missed' })), 'absent');
    assert.equal(weeklyStatusKey(row({ status: 'incomplete' })), 'review');
    assert.equal(weeklyStatusKey(row({ status: 'upcoming' })), 'upcoming');
});
