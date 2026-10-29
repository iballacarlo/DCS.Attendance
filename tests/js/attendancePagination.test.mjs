import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { transform } from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

const sourceUrl = new URL('../../resources/js/components/AttendancePagination.jsx', import.meta.url);
const source = (await readFile(sourceUrl, 'utf8')).replace(/^import .*\.css';\r?\n/gm, '');
const { code } = await transform(source, { loader: 'jsx', jsx: 'automatic', format: 'esm' });
const resolved = code.replace(/from "([^"]+)"/g, (_, specifier) => `from ${JSON.stringify(import.meta.resolve(specifier))}`);
const { default: AttendancePagination } = await import(`data:text/javascript;base64,${Buffer.from(resolved).toString('base64')}`);
const records = { from: 11, to: 20, total: 32, current_page: 2, last_page: 4,
    prev_page_url: '/attendance?cutoff_start=2026-10-16&page=1', next_page_url: '/attendance?cutoff_start=2026-10-16&page=3',
    links: [{ label: 'Previous' }, ...[1, 2, 3, 4].map((page) => ({ label: String(page), url: `/attendance?page=${page}`, active: page === 2 })), { label: 'Next' }] };

test('pagination displays record range, page size, numbered pages and active page accessibly', () => {
    const html = renderToStaticMarkup(React.createElement(AttendancePagination, { records, perPage: 10, onPerPage() {}, label: 'Class attendance pages' }));
    assert.match(html, /11–20/);
    assert.match(html, /<strong>32<\/strong>/);
    assert.match(html, /Page 2 of 4/);
    assert.match(html, /aria-current="page"/);
    assert.match(html, /aria-label="Class attendance pages"/);
    assert.match(html, /Rows per page/);
    for (const size of [10, 25, 50]) assert.match(html, new RegExp(`value="${size}"`));
    assert.match(html, /cutoff_start=2026-10-16&amp;page=3/);
});

test('single and empty pages retain useful pagination information and disabled navigation', () => {
    for (const total of [0, 3]) {
        const html = renderToStaticMarkup(React.createElement(AttendancePagination, { records: { from: total ? 1 : null, to: total || null, total, current_page: 1, last_page: 1 }, onPerPage() {} }));
        assert.match(html, /Page 1 of 1/);
        assert.equal((html.match(/aria-disabled="true"/g) || []).length, 2);
        assert.doesNotMatch(html, /NaN|undefined/);
    }
});

test('row size changes return numeric values for consistent server pagination', () => {
    let chosen;
    const tree = AttendancePagination({ records, perPage: 10, onPerPage: (size) => { chosen = size; } });
    const selector = tree.props.children[1].props.children[1];
    selector.props.onChange({ target: { value: '25' } });
    assert.equal(chosen, 25);
});
