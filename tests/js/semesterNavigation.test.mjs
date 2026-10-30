import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { transform } from 'esbuild';

const moduleUrl = (code) => `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
const runtimeUrl = moduleUrl(`export let page; export const visits = [];
export function setPage(value) { page = value; visits.length = 0; }
export function usePage() { return page; }
export const router = { get(...args) { visits.push(args); } };`);
const runtime = await import(runtimeUrl);
const source = await readFile(new URL('../../resources/js/components/SemesterPicker.jsx', import.meta.url), 'utf8');
const compiled = await transform(source, { loader: 'jsx', jsx: 'automatic', format: 'esm' });
const yearCompiled = await transform(await readFile(new URL('../../resources/js/components/YearFilterInput.jsx', import.meta.url), 'utf8'), { loader: 'jsx', jsx: 'automatic', format: 'esm' });
const yearModule = moduleUrl(yearCompiled.code.replaceAll('from "react/jsx-runtime"', `from ${JSON.stringify(import.meta.resolve('react/jsx-runtime'))}`));
const code = compiled.code.replaceAll('from "./YearFilterInput"', `from ${JSON.stringify(yearModule)}`).replaceAll('from "@inertiajs/react"', `from ${JSON.stringify(runtimeUrl)}`)
    .replaceAll('from "../lib/cutoff.js"', `from ${JSON.stringify(new URL('../../resources/js/lib/cutoff.js', import.meta.url).href)}`)
    .replaceAll('from "react/jsx-runtime"', `from ${JSON.stringify(import.meta.resolve('react/jsx-runtime'))}`);
const { default: SemesterPicker } = await import(moduleUrl(code));
const term = { id: 7, label: 'Second Semester · 2026-2027', start_date: '2026-10-16', end_date: '2026-10-31', status: 'active' };
const setPage = (url, options = [term]) => runtime.setPage({ url, props: { semesterOptions: options } });
const select = (element) => element.props.children.find((child) => child?.type === 'label').props.children.find((child) => child?.type === 'select') || element.props.children[1].props.children.find((child) => child?.type === 'select');

test('semester navigation preserves attendance scope and selects the semester opening cutoff', () => {
    setPage('/entrance?employee_id=4&status=late&page=3&per_page=25');
    const control = select(SemesterPicker({}));
    control.props.onChange({ target: { value: '7' } });
    const [url, params, options] = runtime.visits[0];
    assert.equal(url, '/entrance');
    assert.deepEqual(params, { employee_id: '4', status: 'late', page: 1, per_page: '25', term_id: '7', cutoff_start: '2026-10-16' });
    assert.equal(options.preserveState, false);
    assert.equal(options.preserveScroll, true);
});

test('DTR and faculty history use their existing date parameters without dropping ownership context', () => {
    for (const [url, field, expected] of [
        ['/attendance/dtr?employee_id=4&start_date=2026-09-01', 'start_date', '2026-10-16'],
    ]) {
        setPage(url);
        select(SemesterPicker({})).props.onChange({ target: { value: '7' } });
        assert.equal(runtime.visits[0][1][field], expected);
        assert.equal(runtime.visits[0][1].term_id, '7');
        assert.equal(Object.hasOwn(runtime.visits[0][1], 'cutoff_start'), false);
        if (url.startsWith('/attendance/dtr')) assert.equal(runtime.visits[0][1].employee_id, '4');
    }
});

test('schedule controls require a named semester, preserve local callbacks and support disabled or unconfigured state', () => {
    setPage('/employees/4/schedule?term_id=7');
    let selected;
    const control = select(SemesterPicker({ legacyOnly: true, disabled: true, onChange: (value) => { selected = value; } }));
    assert.equal(control.props.disabled, true);
    assert.equal(control.props.value, '7');
    assert.equal(control.props.children[0].props.children, 'Select semester');
    assert.equal(control.props.children[0].props.disabled, true);
    assert.equal(JSON.stringify(control).includes('Legacy / Unassigned'), false);
    assert.equal(JSON.stringify(select(SemesterPicker({}))).includes('Legacy / Unassigned'), false);
    control.props.onChange({ target: { value: '7' } });
    assert.equal(selected, '7');
    assert.equal(runtime.visits.length, 0);
    setPage('/entrance', []);
    assert.equal(SemesterPicker({}), null);
});

test('dashboard never exposes a semester picker and regular choices hide drafts', () => {
    setPage('/?term_id=7');
    assert.equal(SemesterPicker({}), null);
    setPage('/my-schedule', [term, { ...term, id: 9, label: 'Hidden draft', status: 'draft' }]);
    assert.equal(JSON.stringify(select(SemesterPicker({}))).includes('Hidden draft'), false);
    setPage('/class-reports', [{ ...term, status: 'draft' }]);
    assert.equal(SemesterPicker({}), null);
});
