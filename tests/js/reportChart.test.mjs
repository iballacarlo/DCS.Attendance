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
const render = (props) => renderToStaticMarkup(React.createElement(ReportChart, { daily: true, measure: 'late', description: (bar) => `Late: ${bar.count} class`, onChoose() {}, ...props }));

test('bar and line render plain class counts and accessible selection controls', () => {
    const bars = [{ key: 'late', label: 'Oct 1', value: '2026-10-01', count: 1, total: 12, percentage: 1 / 12 * 100 }];
    for (const chartType of ['bar', 'line']) {
        const html = render({ bars, chartType, selectedDay: '2026-10-01' });
        assert.match(html, /1 class/);
        assert.match(html, /aria-label="Late: 1 class"/);
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
    assert.match(html, /<option value="donut" selected="">Pie - Attendance distribution/);
    assert.match(html, /<option value="bar">Bar - Comparison/);
    assert.ok(html.indexOf('<option value="donut"') < html.indexOf('<option value="bar"'));
    assert.ok(html.indexOf('<option value="bar"') < html.indexOf('<option value="line"'));
    assert.equal(html.includes('value="column"'), false);
    assert.match(html, /Select a slice or its label to highlight matching classes/);
    assert.doesNotMatch(html, /<label>Highlight<select|All attendance statuses/);
    assert.equal((html.match(/class="panel report-summary-card /g) || []).length, 4);
    assert.equal((html.match(/class="panel report-graph"/g) || []).length, 1);
    assert.equal(html.includes('Graph filters'), false);
    assert.match(html, /No finished classes/);
});

const pieRows = [
    { ended: true, status: 'complete', late_minutes: 5, early_departure_minutes: 0 },
    { ended: true, status: 'complete', late_minutes: 2, early_departure_minutes: 4 },
    { ended: true, status: 'complete', late_minutes: 0, early_departure_minutes: 0 },
    { ended: true, status: 'missed' },
];

test('pie shows colored slices, external labels, keyboard highlight controls and no navigation', () => {
    const html = render({ chartType: 'donut', bars: [], rows: pieRows, highlight: 'late' });
    assert.match(html, /Late: 2 classes/);
    assert.match(html, /Late only/);
    assert.match(html, /Late &amp; undertime/);
    assert.match(html, /25%/);
    assert.match(html, /class="report-pie-label[^"]*"[^>]+role="button" tabindex="0"/);
    assert.match(html, /class="report-pie-legend report-pie-mobile-legend"/);
    assert.match(html, /<polyline/);
    assert.match(html, /role="button" tabindex="0"/);
    assert.match(html, /report-pie-slice is-late is-highlighted/);
    assert.match(html, /report-pie-slice is-late_undertime is-highlighted/);
    assert.match(html, /report-pie-slice is-missed is-muted/);
    assert.match(html, /aria-pressed="true"/);
    assert.doesNotMatch(html, /View faculty breakdown|href=|[0-9]\/[0-9]/);
});

test('empty and 100 percent pie render safely without invented percentages', () => {
    const empty = render({ chartType: 'donut', bars: [], rows: [] });
    assert.match(empty, /No finished classes/);
    assert.doesNotMatch(empty, /report-pie-slice|NaN|Infinity|[0-9]%|Show all/);
    const full = render({ chartType: 'donut', bars: [], rows: [pieRows[0]] });
    assert.match(full, /100%/);
    assert.match(full, /1 class/);
    assert.doesNotMatch(full, /NaN|Infinity/);
});

test('report summary cards show readable counts and offer keyboard status highlighting', async () => {
    const { default: ReportAnalytics } = await import(await componentModule(new URL('../../resources/js/components/ReportAnalytics.jsx', import.meta.url)));
    const html = renderToStaticMarkup(React.createElement(ReportAnalytics, { report: { rows: pieRows, summary: { ended: 61, late: 1, undertime: 2 } }, onReview() {} }));
    assert.match(html, /Based on 61 finished classes/);
    assert.match(html, /<strong>1<span> class<\/span><\/strong>/);
    assert.match(html, /<strong>2<span> classes<\/span><\/strong>/);
    assert.doesNotMatch(html, /\/61/);
    assert.equal((html.match(/class="report-summary-select"/g) || []).length, 4);
});

test('outside pie labels directly highlight the chart with mouse and keyboard', async () => {
    const { default: ReportPie } = await import(await componentModule(new URL('../../resources/js/components/ReportPie.jsx', import.meta.url)));
    const calls = [];
    const tree = ReportPie({ rows: pieRows, onHighlight: (key) => calls.push(key) });
    const elements = [];
    function visit(node) {
        if (!node || typeof node !== 'object') return;
        if (Array.isArray(node)) { node.forEach(visit); return; }
        elements.push(node);
        visit(node.props?.children);
    }
    visit(tree);
    const late = elements.find((node) => node.type === 'g' && node.props['aria-label']?.startsWith('Highlight Late only:'));
    assert.ok(late);
    late.props.onClick();
    let prevented = false;
    late.props.onKeyDown({ key: 'Enter', preventDefault() { prevented = true; } });
    assert.deepEqual(calls, ['late', 'late']);
    assert.equal(prevented, true);
});

test('bar and line graph panels omit duplicate status controls and show selection actions only when needed', async () => {
    const { AttendanceGraph } = await import(await componentModule(new URL('../../resources/js/components/ReportAnalytics.jsx', import.meta.url)));
    const { initialGraphState } = await import('../../resources/js/lib/reportGraph.js');
    const rows = [{ employee_id: 1, faculty: 'Test Faculty', subject: 'DCIT 24', date: '2026-10-29', ended: true, status: 'complete', late_minutes: 2, early_departure_minutes: 0 }];
    for (const [chartType, view] of [['bar', 'overall'], ['bar', 'faculty'], ['line', 'daily']]) {
        const state = { ...initialGraphState(), chartType, view };
        const html = renderToStaticMarkup(React.createElement(AttendanceGraph, { rows, state, dispatch() {} }));
        assert.doesNotMatch(html, /<label>Status<select|<label>Highlight<select|report-pie-legend|Show all/);
        assert.match(html, /Graph type/);
    }
    const selected = renderToStaticMarkup(React.createElement(AttendanceGraph, { rows, state: { ...initialGraphState(), chartType: 'bar', barSelection: 'late' }, dispatch() {} }));
    assert.match(selected, /View faculty breakdown/);
    assert.match(selected, /Show all/);
    assert.match(selected, /report-local-bars has-selection/);
    assert.match(selected, /report-local-bar is-late is-selected/);
    const daily = render({ chartType: 'line', selectedDay: '2026-10-29', bars: [{ value: '2026-10-29', label: 'Oct 29', key: 'late', total: 1, count: 1, percentage: 100 }] });
    assert.match(daily, /report-line-scroll is-late has-selection/);
});

test('multi-series line graph renders four colored lines together with one date axis and a clickable status legend', async () => {
    const { graphView, initialGraphState } = await import('../../resources/js/lib/reportGraph.js');
    const rows = [
        { employee_id: 1, faculty: 'Test', date: '2026-10-01', ended: true, status: 'complete', late_minutes: 0, early_departure_minutes: 0 },
        { employee_id: 1, faculty: 'Test', date: '2026-10-03', ended: true, status: 'complete', late_minutes: 5, early_departure_minutes: 5 },
    ];
    const graph = graphView(rows, { ...initialGraphState(), view: 'daily', chartType: 'line' });
    const html = render({ chartType: 'line', bars: graph.bars, series: graph.series, lineHighlight: 'late' });
    for (const key of ['on_time', 'late', 'undertime', 'missed']) assert.match(html, new RegExp(`report-line-series is-${key}`));
    assert.equal((html.match(/class="report-line-path"/g) || []).length, 4);
    assert.equal((html.match(/class="report-line-date"/g) || []).length, 2);
    assert.equal((html.match(/class="report-line-dot"/g) || []).length, 8);
    assert.match(html, /report-line-series is-late"/);
    assert.match(html, /report-line-series is-missed is-muted/);
    assert.match(html, /aria-label="Attendance lines"/);
    assert.doesNotMatch(html, /<select|NaN|Infinity/);
    assert.equal((html.match(/<button/g) || []).length, 4);
    assert.match(html, /aria-pressed="true" title="Highlight Late"/);
});

test('Late and Undertime legend buttons directly invoke the local line-status selection', async () => {
    const { default: ReportTrendLines } = await import(await componentModule(new URL('../../resources/js/components/ReportTrendLines.jsx', import.meta.url)));
    const { graphView, initialGraphState, graphReducer } = await import('../../resources/js/lib/reportGraph.js');
    const rows = [{ employee_id: 1, faculty: 'Test', date: '2026-10-29', ended: true, status: 'complete', late_minutes: 5, early_departure_minutes: 3 }];
    let state = { ...initialGraphState(), chartType: 'line', view: 'daily' };
    const data = graphView(rows, state);
    const select = (measure) => { state = graphReducer(state, { type: 'summary', measure }); };
    const chart = ReportChart({ bars: data.bars, series: data.series, chartType: 'line', onLineHighlight: select });
    assert.equal(chart.props.onStatusSelect, select);
    const tree = ReportTrendLines({ ...chart.props, measure: 'late', description: () => '', onChoose() {} });
    const buttons = tree.props.children[0].props.children.map((node) => node.props.children);
    buttons.find((button) => button.props.title === 'Highlight Late').props.onClick();
    assert.equal(state.lineHighlight, 'late');
    assert.equal(state.chartType, 'line');
    assert.deepEqual(graphView(rows, state).series, data.series);
    buttons.find((button) => button.props.title === 'Highlight Undertime').props.onClick();
    assert.equal(state.lineHighlight, 'undertime');
    buttons.find((button) => button.props.title === 'Highlight Undertime').props.onClick();
    assert.equal(state.lineHighlight, '');
});
