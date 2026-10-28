import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { transform } from 'esbuild';
import React from 'react';
import { renderToStaticMarkup } from 'react-dom/server';

// Render the actual JSX with the project's existing compiler and React runtime.
const modules = new Map();
async function componentModule(sourceUrl) {
    if (modules.has(sourceUrl.href)) return modules.get(sourceUrl.href);
    const { code } = await transform(await readFile(sourceUrl, 'utf8'), { loader: 'jsx', jsx: 'automatic', format: 'esm' });
    let resolvedCode = code;
    for (const specifier of new Set([...code.matchAll(/from "([^"]+)"/g)].map((match) => match[1]))) {
        let target = specifier.startsWith('.') ? new URL(`${specifier}${specifier.includes('/lib/') ? '.js' : '.jsx'}`, sourceUrl).href : import.meta.resolve(specifier);
        if (target.endsWith('.jsx')) target = await componentModule(new URL(target));
        resolvedCode = resolvedCode.replaceAll(`from "${specifier}"`, `from ${JSON.stringify(target)}`);
    }
    const target = `data:text/javascript;base64,${Buffer.from(resolvedCode).toString('base64')}`;
    modules.set(sourceUrl.href, target);
    return target;
}
const { default: ReportChart } = await import(await componentModule(new URL('../../resources/js/components/ReportChart.jsx', import.meta.url)));
const render = (props) => renderToStaticMarkup(React.createElement(ReportChart, { daily: true, measure: 'late', description: (bar) => `Late: ${bar.count}/${bar.total}`, onChoose() {}, ...props }));

test('bar and line render the same ratio and accessible selection controls', () => {
    const bars = [{ key: 'late', label: 'Oct 1', value: '2026-10-01', count: 1, total: 12, percentage: 1 / 12 * 100 }];
    for (const chartType of ['bar', 'line']) {
        const html = render({ bars, chartType, selectedDay: '2026-10-01' });
        assert.match(html, /1\/12/);
        assert.match(html, /aria-label="Late: 1\/12"/);
        assert.match(html, /aria-pressed="true"/);
        assert.equal(html.includes('NaN'), false);
        assert.equal(html.includes('Infinity'), false);
    }
});

test('single-point line has a keyboard-accessible point and no fabricated path', () => {
    const html = render({ chartType: 'line', bars: [{ key: 'late', label: 'Oct 1', value: '2026-10-01', count: 1, total: 12, percentage: 8.333 }] });
    assert.match(html, /role="button" tabindex="0"/);
    assert.equal((html.match(/class="report-line-dot"/g) || []).length, 1);
    assert.equal(html.includes('report-line-path'), false);
});

test('empty line data displays an empty state instead of an invented chart', () => {
    for (const bars of [[], [{ value: '2026-10-01', total: 0, count: 0, percentage: null }]]) {
        const html = render({ chartType: 'line', bars });
        assert.match(html, /No finished classes/);
        assert.equal(html.includes('<svg'), false);
    }
});

test('graph controls are directly visible with only supported graph types selectable', async () => {
    const { default: ReportAnalytics } = await import(await componentModule(new URL('../../resources/js/components/ReportAnalytics.jsx', import.meta.url)));
    const html = renderToStaticMarkup(React.createElement(ReportAnalytics, { report: { rows: [], summary: { ended: 0 } }, onReview() {} }));
    assert.equal(html.includes('<details'), false);
    assert.match(html, /<label>Faculty<select/);
    assert.match(html, /<label>Subject<select/);
    assert.match(html, /<label>Graph type<select/);
    assert.match(html, /<option value="line">Line - Daily trend/);
    assert.match(html, /<option value="donut">Donut - Status share/);
    assert.match(html, /<option value="bar" selected="">Bar - Comparison/);
    assert.equal(html.includes('value="column"'), false);
    assert.match(html, /Compare status rates overall, by faculty, or by subject/);
    assert.match(html, /<label>Compare<select/);
    assert.equal((html.match(/class="panel report-summary-card /g) || []).length, 4);
    assert.equal((html.match(/class="panel report-graph"/g) || []).length, 1);
    assert.equal(html.includes('Graph filters'), false);
    assert.match(html, /No finished classes/);
});

test('donut renders one status, its complement and a keyboard-accessible drilldown', () => {
    const bars = [{ key: 'late', count: 1, total: 12 }, { key: 'undertime', count: 1, total: 12 }];
    const html = render({ chartType: 'donut', bars, measure: 'late' });
    assert.match(html, /1\/12/);
    assert.match(html, /8\.3% of finished classes/);
    assert.match(html, /Other classes/);
    assert.match(html, /<strong>11<\/strong>/);
    assert.equal((html.match(/class="report-donut-segment"/g) || []).length, 1);
    assert.match(html, /role="button" tabindex="0"/);
    assert.match(html, /<button[^>]+report-donut-status/);
    assert.equal(html.includes('Undertime'), false);
    const neutral = html.match(/<circle class="report-donut-track"[^>]*>/)[0];
    assert.equal(neutral.includes('role="button"'), false);
    assert.equal(neutral.includes('tabindex'), false);
});

test('donut handles empty, zero and 100 percent without fabricated segments or percentages', () => {
    const empty = render({ chartType: 'donut', bars: [] });
    assert.match(empty, /0\/0/);
    assert.match(empty, /No finished classes/);
    assert.equal(empty.includes('report-donut-segment'), false);
    assert.equal(empty.includes('% of finished classes'), false);
    const zero = render({ chartType: 'donut', bars: [{ key: 'late', count: 0, total: 12 }] });
    assert.match(zero, /0% of finished classes/);
    assert.equal(zero.includes('report-donut-segment'), false);
    const full = render({ chartType: 'donut', bars: [{ key: 'late', count: 12, total: 12 }] });
    assert.match(full, /100% of finished classes/);
    assert.match(full, /stroke-dasharray="100 0"/);
    assert.match(full, /Other classes: 0\/12/);
    assert.equal(full.includes('NaN'), false);
});
