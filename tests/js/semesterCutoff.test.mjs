import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { transform } from 'esbuild';
import React from 'react';
import { constrainCutoff, semesterCutoffs } from '../../resources/js/lib/cutoff.js';
import { matchesSessionStatus } from '../../resources/js/lib/classAnalytics.js';

const first = { id: 1, start_date: '2026-08-10', end_date: '2026-12-12' };
const second = { id: 2, start_date: '2027-01-11', end_date: '2027-05-15' };
const midyear = { id: 3, start_date: '2027-06-14', end_date: '2027-07-24' };
const moduleUrl = (code) => `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
const runtimeUrl = moduleUrl('export let page; export function setPage(value) { page = value; } export function usePage() { return page; }');
const runtime = await import(runtimeUrl);
const compiled = await transform(await readFile(new URL('../../resources/js/components/CutoffPicker.jsx', import.meta.url), 'utf8'), { loader: 'jsx', jsx: 'automatic', format: 'esm' });
const yearCompiled = await transform(await readFile(new URL('../../resources/js/components/YearFilterInput.jsx', import.meta.url), 'utf8'), { loader: 'jsx', jsx: 'automatic', format: 'esm' });
const yearModule = moduleUrl(yearCompiled.code.replaceAll('from "react/jsx-runtime"', `from ${JSON.stringify(import.meta.resolve('react/jsx-runtime'))}`));
const code = compiled.code.replaceAll('from "./YearFilterInput"', `from ${JSON.stringify(yearModule)}`).replaceAll('from "@inertiajs/react"', `from ${JSON.stringify(runtimeUrl)}`)
    .replaceAll('from "../lib/cutoff.js"', `from ${JSON.stringify(new URL('../../resources/js/lib/cutoff.js', import.meta.url).href)}`)
    .replaceAll('from "react/jsx-runtime"', `from ${JSON.stringify(import.meta.resolve('react/jsx-runtime'))}`);
const { default: Picker } = await import(moduleUrl(code));
const fields = (value, term, onChange = () => {}) => {
    runtime.setPage({ url: '/attendance', props: { semesterOptions: [term] } });
    const root = Picker({ value, termId: term.id, onChange });
    return Object.fromEntries(root.props.children.props.children.map((label) => [label.props.children[0], label.props.children[1]]));
};
const values = (select) => React.Children.toArray(select.props.children).map((option) => option.props.value);

test('first semester only permits August to December 2026 and excludes cutoffs after its last day', () => {
    const controls = fields('2026-05-01', first);
    assert.deepEqual(values(controls.Month), ['08', '09', '10', '11', '12']);
    assert.deepEqual(controls.Year.props.allowedYears, ['2026']);
    assert.equal(controls.Month.props.value, '08');
    assert.deepEqual(values(fields('2026-12-01', first).Cutoff), ['01']);
    assert.equal(constrainCutoff('2027-05-01', first).start, '2026-12-01');
});

test('second semester and June-July midyear use their actual calendar year', () => {
    assert.deepEqual(values(fields('2026-10-01', second).Month), ['01', '02', '03', '04', '05']);
    assert.deepEqual(fields('2026-10-01', second).Year.props.allowedYears, ['2027']);
    assert.deepEqual(values(fields('2027-06-01', midyear).Month), ['06', '07']);
    assert.equal(constrainCutoff('2027-06-01', midyear).start, '2027-06-01');
    assert.equal(constrainCutoff('2027-08-01', midyear).start, '2027-07-16');
});

test('cross-year choices and partial cutoffs adjust month and day safely when changing year', () => {
    let selected;
    const term = { ...first, end_date: '2027-05-15' };
    const controls = fields('2026-08-16', term, (period) => { selected = period; });
    assert.deepEqual(controls.Year.props.allowedYears, ['2026', '2027']);
    controls.Year.props.onYearChange('2027');
    assert.equal(selected.start, '2027-01-16');
    assert.deepEqual(values(fields('2027-01-16', term).Month), ['01', '02', '03', '04', '05']);
    const narrow = { ...first, start_date: '2026-08-16', end_date: '2026-08-20' };
    assert.deepEqual(semesterCutoffs(narrow).map((period) => period.start), ['2026-08-16']);
    assert.equal(semesterCutoffs({ start_date: '2028-02-20', end_date: '2028-02-29' })[0].end, '2028-02-29');
});

test('combined late-undertime status includes only completed ended sessions with both violations', () => {
    const row = { status: 'complete', ended: true, late_minutes: 5, early_departure_minutes: 10 };
    assert.equal(matchesSessionStatus(row, 'late_undertime'), true);
    assert.equal(matchesSessionStatus({ ...row, late_minutes: 0 }, 'late_undertime'), false);
    assert.equal(matchesSessionStatus({ ...row, status: 'incomplete' }, 'late_undertime'), false);
    assert.equal(matchesSessionStatus({ ...row, ended: false }, 'late_undertime'), false);
});
