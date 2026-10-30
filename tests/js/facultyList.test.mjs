import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { transform } from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const moduleUrl = (code) => `data:text/javascript;base64,${Buffer.from(code).toString('base64')}`;
const reactUrl = JSON.stringify(import.meta.resolve('react'));
const sourceUrl = new URL('../../resources/js/Pages/Employees/Index.jsx', import.meta.url);
const mocks = {
    '@inertiajs/react': moduleUrl(`import React from ${reactUrl}; export const router = {}; export const usePage = () => ({ props: { auth: { user: { role: 'admin', employee_id: null } }, facultyChoices: [] } }); export const Link = ({ href, children }) => React.createElement('a', { href }, children);`),
    '../../Layouts/AppLayout': moduleUrl(`import React from ${reactUrl}; export default function Layout({ children }) { return React.createElement('main', null, children); }`),
    '../../components/FacultyCombobox': moduleUrl('export default function Combobox() { return null; }'),
    '../../components/Pagination': moduleUrl('export default function Pagination() { return null; }'),
    '../../hooks/useDebouncedFilters': moduleUrl('export default function useFilters() {}'),
    '../../hooks/useConfirmDialog': moduleUrl('export default function useDialog() { return { ask: async () => false, dialog: null }; }'),
};
const { code } = await transform(await readFile(sourceUrl, 'utf8'), { loader: 'jsx', jsx: 'automatic', format: 'esm' });
const resolved = code.replace(/from "([^"]+)"/g, (_, name) => `from ${JSON.stringify(mocks[name] || (name.startsWith('.') ? new URL(`${name}.js`, sourceUrl).href : import.meta.resolve(name)))}`);
const { default: FacultyList } = await import(moduleUrl(resolved));
const block = { start_time: '08:00:00', end_time: '10:00:00' };
const render = (schedules) => renderToStaticMarkup(React.createElement(FacultyList, {
    employees: { data: [{ id: 4, first_name: 'Juan', last_name: 'Dela Cruz', employee_no: 'COS-4', status: 'active', schedules }], links: [] },
    filters: { sort: 'name', direction: 'asc' }, ranks: [],
}));

test('faculty list renders weekly hours for published arrays and sparse legacy response objects', () => {
    for (const schedules of [[block], { 7: block }]) {
        const html = render(schedules);
        assert.match(html, /Faculty Records/);
        assert.match(html, /Dela Cruz, Juan/);
        assert.match(html, /2(?:<!-- -->)? hrs\/week/);
        assert.match(html, /href="\/employees\/4\/schedule"/);
    }
});

test('faculty with no schedules renders zero hours instead of a blank page', () => {
    for (const schedules of [[], {}, null, undefined]) assert.match(render(schedules), /0(?:<!-- -->)? hrs\/week/);
});
