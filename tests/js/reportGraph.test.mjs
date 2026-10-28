import test from 'node:test';
import assert from 'node:assert/strict';
import { graphChoices, graphReducer, graphView, initialGraphState, lineGraphModel, donutGraphModel } from '../../resources/js/lib/reportGraph.js';
import { classReportView, sessionPage } from '../../resources/js/lib/classAnalytics.js';

const session = (id, overrides = {}) => ({ key: String(id), employee_id: id % 2 + 1, faculty: `Faculty ${id % 2 + 1}`,
    subject: id % 2 ? 'DCIT 26' : 'DCIT 24', date: `2026-10-${String(id % 12 + 1).padStart(2, '0')}`,
    ended: true, status: 'complete', late_minutes: 0, early_departure_minutes: 0, ...overrides });
const fixture = () => [
    ...Array.from({ length: 9 }, (_, id) => session(id)),
    session(9, { late_minutes: 5, early_departure_minutes: 10 }),
    session(10, { status: 'missed', late_minutes: null, early_departure_minutes: null }),
    session(11, { status: 'incomplete', late_minutes: 42, early_departure_minutes: null }),
    session(12, { ended: false }), session(13, { status: 'excluded' }), session(14, { status: 'in_progress', ended: false }),
];

test('summary bars use all finished eligible classes, including review, without double-counting denominators', () => {
    const view = graphView(fixture(), initialGraphState());
    assert.equal(view.total, 12);
    assert.deepEqual(view.bars.map((bar) => [bar.key, bar.count, bar.total]), [
        ['on_time', 9, 12], ['late', 1, 12], ['undertime', 1, 12], ['missed', 1, 12],
    ]);
    assert.equal(view.bars[1].percentage, 1 / 12 * 100);
    assert.equal(view.bars.length, 4);
});

test('empty data shows four 0/0 counts and no invented percentage', () => {
    const rows = [session(1, { ended: false }), session(2, { status: 'excluded' })];
    const view = graphView(rows, initialGraphState());
    assert.ok(view.bars.every((bar) => bar.count === 0 && bar.total === 0 && bar.percentage === null));
    assert.equal(graphView(rows, { ...initialGraphState(), view: 'faculty' }).bars.length, 0);
});

test('status then faculty drilldown stays local and Back restores the previous graph selections', () => {
    const rows = fixture();
    const original = structuredClone(rows);
    let state = initialGraphState();
    state = graphReducer(state, { type: 'overall', measure: 'late' });
    assert.equal(state.view, 'faculty');
    assert.equal(state.measure, 'late');
    const faculty = graphView(rows, state).bars[0];
    assert.equal(faculty.value, '2');
    assert.equal(faculty.count, 1);
    assert.equal(faculty.total, 6);
    state = graphReducer(state, { type: 'group', value: faculty.value });
    assert.equal(state.view, 'daily');
    assert.equal(state.faculty, '2');
    const beforeSelection = graphView(rows, state);
    state = graphReducer(state, { type: 'day', day: beforeSelection.bars[0].value });
    assert.deepEqual(graphView(rows, state), beforeSelection);
    state = graphReducer(state, { type: 'back' });
    assert.equal(state.view, 'faculty');
    assert.equal(state.faculty, '');
    state = graphReducer(state, { type: 'back' });
    assert.deepEqual(state, initialGraphState());
    assert.deepEqual(rows, original);
});

test('subject drilldown uses subject-specific daily denominators and a chronological trend', () => {
    const rows = [session(0, { date: '2026-10-09' }), session(2, { date: '2026-10-01', late_minutes: 5 }),
        session(4, { date: '2026-10-01' }), session(1)];
    let state = graphReducer(initialGraphState(), { type: 'view', view: 'subject' });
    const subject = graphView(rows, state).bars.find((bar) => bar.value === 'DCIT 24');
    assert.equal(subject.count, 1);
    assert.equal(subject.total, 3);
    state = graphReducer(state, { type: 'group', value: subject.value });
    assert.deepEqual(graphView(rows, state).bars.map((bar) => [bar.value, bar.count, bar.total]), [['2026-10-01', 1, 2], ['2026-10-09', 0, 1]]);
});

test('local choices use only supplied report records and subjects follow faculty', () => {
    const rows = [session(0), session(1), session(3, { subject: null })];
    const choices = graphChoices(rows, '1');
    assert.deepEqual(choices.faculties.map((item) => item.value), ['1', '2']);
    assert.deepEqual(choices.subjects.map((item) => item.value), ['DCIT 24']);
    const unspecified = graphChoices(rows, '2').subjects.find((item) => item.label === 'Unspecified subject');
    const state = { ...initialGraphState(), subject: unspecified.value };
    assert.equal(graphView(rows, state).total, 1);
});

test('faculty and subject graph pagination shows five bars and clamps after data shrinks', () => {
    const rows = Array.from({ length: 13 }, (_, id) => session(id, { employee_id: id + 1, faculty: `Faculty ${String(id).padStart(2, '0')}`, subject: `Subject ${id}` }));
    for (const view of ['faculty', 'subject']) {
        const state = { ...initialGraphState(), view };
        const first = graphView(rows, state);
        const second = graphView(rows, { ...state, page: 2 });
        const last = graphView(rows, { ...state, page: 999 });
        assert.deepEqual([first.bars.length, second.bars.length, last.bars.length], [5, 5, 3]);
        assert.equal(new Set([...first.bars, ...second.bars, ...last.bars].map((bar) => bar.value)).size, 13);
        assert.equal(last.page, 3);
        assert.equal(graphView(rows.slice(0, 2), { ...state, page: 3 }).page, 1);
    }
});

test('refresh retains valid selections and removes invalid faculty, subject, day and Back history', () => {
    const rows = fixture();
    let state = graphReducer(initialGraphState(), { type: 'overall', measure: 'undertime' });
    state = graphReducer(state, { type: 'group', value: '2' });
    state = graphReducer(state, { type: 'filter', field: 'subject', value: 'DCIT 26' });
    state = graphReducer(state, { type: 'day', day: '2026-10-10' });
    assert.equal(graphReducer(state, { type: 'refresh', rows: structuredClone(rows) }), state);
    const reduced = graphReducer(state, { type: 'refresh', rows: rows.filter((row) => row.employee_id === 1) });
    assert.equal(reduced.faculty, '');
    assert.equal(reduced.subject, '');
    assert.equal(reduced.day, '');
    assert.ok(reduced.history.every((previous) => previous.faculty !== '2'));
    assert.deepEqual(graphReducer(reduced, { type: 'reset' }), initialGraphState());
});

test('graph filters never affect report exports, explicit table status or original data', () => {
    const report = { filters: { cutoff_start: '2026-10-01' }, rows: fixture() };
    const original = structuredClone(report);
    const before = classReportView(report, 'late');
    const table = sessionPage(report.rows, 1, 10, 'incomplete');
    let state = graphReducer(initialGraphState(), { type: 'overall', measure: 'missed' });
    state = graphReducer(state, { type: 'filter', field: 'faculty', value: '1' });
    graphView(report.rows, state);
    assert.deepEqual(classReportView(report, 'late'), before);
    assert.deepEqual(sessionPage(report.rows, 1, 10, 'incomplete'), table);
    assert.deepEqual(report, original);
});

test('three graph types automatically choose the correct function and Back restores the previous graph', () => {
    let state = initialGraphState();
    assert.equal(state.chartType, 'bar');
    for (const chartType of ['column', 'pie']) assert.equal(graphReducer(state, { type: 'chart', chartType }), state);
    state = graphReducer(state, { type: 'view', view: 'subject' });
    const comparison = state;
    state = graphReducer(state, { type: 'chart', chartType: 'line' });
    assert.equal(state.view, 'daily');
    assert.equal(state.chartType, 'line');
    assert.deepEqual(graphReducer(state, { type: 'back' }), comparison);
    state = graphReducer(state, { type: 'chart', chartType: 'donut' });
    assert.equal(state.view, 'overall');
    assert.equal(state.chartType, 'donut');
    state = graphReducer(state, { type: 'chart', chartType: 'bar' });
    assert.equal(state.view, 'overall');
    assert.equal(state.chartType, 'bar');
    assert.deepEqual(graphReducer(state, { type: 'reset' }), initialGraphState());
});

test('graph type changes retain local filters, totals and valid refresh state without changing exports', () => {
    const rows = fixture();
    const report = { filters: {}, rows };
    const original = structuredClone(rows);
    const exported = classReportView(report, 'late');
    let state = graphReducer(initialGraphState(), { type: 'filter', field: 'faculty', value: '2' });
    const total = graphView(rows, state).total;
    for (const chartType of ['line', 'donut', 'bar']) {
        state = graphReducer(state, { type: 'chart', chartType });
        assert.equal(graphView(rows, state).total, total);
        assert.equal(state.faculty, '2');
        assert.equal(graphReducer(state, { type: 'refresh', rows: structuredClone(rows) }), state);
        assert.deepEqual(classReportView(report, 'late'), exported);
    }
    const legacy = graphReducer({ ...initialGraphState(), chartType: 'column' }, { type: 'refresh', rows });
    assert.equal(legacy.chartType, 'bar');
    assert.deepEqual(rows, original);
});

test('line points use actual calendar spacing and percentages without adding missing dates', () => {
    const bars = [
        { value: '2026-10-10', count: 1, total: 2, percentage: 50 },
        { value: '2026-10-01', count: 1, total: 4, percentage: 25 },
        { value: '2026-10-02', count: 0, total: 3, percentage: 0 },
    ];
    const original = structuredClone(bars);
    const plot = lineGraphModel(bars);
    assert.deepEqual(plot.points.map((point) => point.value), ['2026-10-01', '2026-10-02', '2026-10-10']);
    assert.equal(plot.points[1].y, plot.bottom);
    assert.equal(plot.points[2].y, (plot.top + plot.bottom) / 2);
    assert.ok(plot.points[1].x - plot.points[0].x < (plot.points[2].x - plot.points[1].x) / 7);
    assert.equal((plot.path.match(/L/g) || []).length, 2);
    assert.deepEqual(bars, original);
});

test('empty and single-point line graphs never invent a zero or connecting line', () => {
    assert.equal(lineGraphModel([]).points.length, 0);
    assert.equal(lineGraphModel([{ value: '2026-10-01', total: 0, percentage: null }]).points.length, 0);
    const plot = lineGraphModel([{ value: '2026-10-01', total: 12, count: 1, percentage: 1 / 12 * 100 }]);
    assert.equal(plot.points.length, 1);
    assert.equal(plot.path, '');
    assert.equal(plot.points[0].x, (plot.left + plot.right) / 2);
    assert.ok(Number.isFinite(plot.points[0].y));
    assert.equal(plot.points[0].count, 1);
});

test('donut uses only the selected status with all eligible finished classes as denominator', () => {
    const bars = graphView(fixture(), initialGraphState()).bars;
    const late = donutGraphModel(bars, 'late');
    assert.deepEqual([late.count, late.total, late.other], [1, 12, 11]);
    assert.equal(late.percentage, 1 / 12 * 100);
    assert.equal(donutGraphModel(bars, 'undertime').count, 1);
    assert.equal(donutGraphModel(bars, 'on_time').count, 9);
    assert.equal(donutGraphModel(bars, 'missed').count, 1);
    assert.equal(donutGraphModel([], 'late').percentage, null);
    for (const count of [0, 12]) {
        const model = donutGraphModel([{ key: 'late', count, total: 12 }]);
        assert.equal(model.count + model.other, 12);
        assert.equal(model.percentage, count ? 100 : 0);
    }
});

test('donut is Overall-only, defaults to Late and survives refresh and Back from drilldown', () => {
    let state = graphReducer({ ...initialGraphState(), measure: 'missed' }, { type: 'chart', chartType: 'donut' });
    assert.equal(state.chartType, 'donut');
    assert.equal(state.measure, 'late');
    state = graphReducer(state, { type: 'filter', field: 'measure', value: 'on_time' });
    const report = { filters: {}, rows: fixture() };
    const exported = classReportView(report, 'incomplete');
    assert.equal(graphReducer(state, { type: 'refresh', rows: fixture() }), state);
    const donut = graphView(report.rows, state);
    let drilled = graphReducer(state, { type: 'overall', measure: 'on_time' });
    assert.equal(drilled.view, 'faculty');
    assert.equal(drilled.chartType, 'bar');
    assert.equal(graphReducer(drilled, { type: 'chart', chartType: 'donut' }).view, 'overall');
    drilled = graphReducer(drilled, { type: 'back' });
    assert.deepEqual(drilled, state);
    assert.deepEqual(graphView(report.rows, drilled), donut);
    assert.deepEqual(classReportView(report, 'incomplete'), exported);
    assert.deepEqual(graphReducer(drilled, { type: 'reset' }), initialGraphState());
    const daily = graphReducer(state, { type: 'view', view: 'daily' });
    assert.equal(graphReducer(daily, { type: 'chart', chartType: 'donut' }).view, 'overall');
});
