import test from 'node:test';
import assert from 'node:assert/strict';
import { pieGraphModel, matchesPieHighlight } from '../../resources/js/lib/reportPie.js';
import { graphReducer, graphView, initialGraphState } from '../../resources/js/lib/reportGraph.js';
import { classReportView, sessionPage } from '../../resources/js/lib/classAnalytics.js';

const row = (overrides = {}) => ({ employee_id: 1, faculty: 'Faculty One', subject: 'DCIT 24', date: '2026-10-29', ended: true, status: 'complete', late_minutes: 0, early_departure_minutes: 0, ...overrides });
const fixture = () => [row(), row({ late_minutes: 5 }), row({ early_departure_minutes: 10 }), row({ late_minutes: 3, early_departure_minutes: 5 }), row({ status: 'missed' }), row({ status: 'incomplete', late_minutes: 30 }), row({ ended: false }), row({ status: 'excluded' })];

test('pie partitions every eligible finished class once, including late-undertime overlap and review', () => {
    const model = pieGraphModel(fixture());
    assert.equal(model.total, 6);
    assert.equal(model.slices.length, 6);
    assert.equal(model.slices.reduce((sum, slice) => sum + slice.count, 0), 6);
    assert.ok(Math.abs(model.slices.reduce((sum, slice) => sum + slice.percentage, 0) - 100) < 0.0001);
    assert.equal(model.highlights.find((item) => item.key === 'late').count, 2);
    assert.equal(model.highlights.find((item) => item.key === 'undertime').count, 2);
    assert.equal(model.highlights.find((item) => item.key === 'incomplete').count, 1);
    assert.ok(model.slices.every((slice) => slice.highlighted && !/NaN|Infinity/.test(slice.path)));
});

test('Late highlights Late only and Late & undertime without removing or filtering any slice', () => {
    const original = fixture();
    const copy = structuredClone(original);
    const late = pieGraphModel(original, 'late');
    assert.deepEqual(late.slices.filter((slice) => slice.highlighted).map((slice) => slice.key), ['late', 'late_undertime']);
    assert.equal(late.selected.count, 2);
    assert.equal(late.total, 6);
    assert.equal(late.slices.length, 6);
    assert.equal(matchesPieHighlight('late_undertime', 'undertime'), true);
    assert.equal(matchesPieHighlight('late', 'undertime'), false);
    assert.deepEqual(original, copy);
});

test('zero and full results have honest geometry with no fabricated percentages or empty slices', () => {
    const empty = pieGraphModel([row({ ended: false }), row({ status: 'excluded' })], 'late');
    assert.equal(empty.total, 0);
    assert.deepEqual(empty.slices, []);
    assert.equal(empty.selected.count, 0);
    const full = pieGraphModel([row({ late_minutes: 2 })], 'late');
    assert.equal(full.slices.length, 1);
    assert.equal(full.slices[0].percentage, 100);
    assert.equal((full.slices[0].path.match(/A126/g) || []).length, 2);
    assert.equal(full.slices[0].count, 1);
});

test('external labels stay separated even when five categories have tiny slices on one side', () => {
    const model = pieGraphModel([...Array.from({ length: 1000 }, () => row()), ...fixture().slice(1)]);
    for (const side of ['left', 'right']) {
        const labels = model.slices.filter((slice) => slice.side === side).sort((a, b) => a.labelY - b.labelY);
        labels.forEach((slice, index) => {
            assert.ok(slice.labelY >= 38 && slice.labelY <= 332);
            if (index) assert.ok(slice.labelY - labels[index - 1].labelY >= 45.99);
        });
    }
});

test('pie leaders stay outside slices and do not cross for crowded and dominant categories', () => {
    const profiles = [[121, 9, 2, 2, 4, 0], ...Array.from({ length: 6 }, (_, dominant) =>
        Array.from({ length: 6 }, (_, index) => index === dominant ? 1000 : index + 1))];
    const definitions = [row(), row({ late_minutes: 5 }), row({ late_minutes: 5, early_departure_minutes: 5 }),
        row({ early_departure_minutes: 5 }), row({ status: 'missed' }), row({ status: 'incomplete' })];
    const cross = (a, b, c) => (b.x - a.x) * (c.y - a.y) - (b.y - a.y) * (c.x - a.x);
    for (const counts of profiles) {
        const model = pieGraphModel(counts.flatMap((count, index) => Array.from({ length: count }, () => definitions[index])));
        const leaders = model.slices.map((slice) => slice.leader.split(' ').map((point) => {
            const [x, y] = point.split(',').map(Number);
            return { x, y };
        }));
        leaders.forEach((points, index) => {
            assert.equal(points.length, 4);
            assert.deepEqual(points[0], model.slices[index].anchor);
            assert.ok(Math.abs(Math.hypot(points[0].x - 320, points[0].y - 185) - 126) < .02);
            for (let segment = 1; segment < points.length; segment++) {
                for (let step = 0; step <= 20; step++) {
                    const fraction = step / 20;
                    const x = points[segment - 1].x + (points[segment].x - points[segment - 1].x) * fraction;
                    const y = points[segment - 1].y + (points[segment].y - points[segment - 1].y) * fraction;
                    assert.ok(Math.hypot(x - 320, y - 185) >= 125.98, 'Leader must not cut through the pie');
                }
            }
            for (const other of leaders.slice(index + 1)) {
                for (let a = 1; a < points.length; a++) {
                    for (let b = 1; b < other.length; b++) {
                        const intersects = cross(points[a - 1], points[a], other[b - 1]) * cross(points[a - 1], points[a], other[b]) < -1e-6
                            && cross(other[b - 1], other[b], points[a - 1]) * cross(other[b - 1], other[b], points[a]) < -1e-6;
                        assert.equal(intersects, false, 'Status leaders must not cross');
                    }
                }
            }
        });
    }
});

test('highlighting is local, toggles off, survives refresh and never changes exports or session details', () => {
    const report = { rows: fixture(), filters: {} };
    const exported = classReportView(report, 'late');
    const details = sessionPage(report.rows, 1, 10, 'incomplete');
    let state = graphReducer(initialGraphState(), { type: 'highlight', highlight: 'late' });
    assert.equal(state.view, 'overall');
    assert.equal(state.chartType, 'donut');
    assert.equal(state.highlight, 'late');
    assert.equal(graphView(report.rows, state).total, 6);
    assert.equal(graphReducer(state, { type: 'refresh', rows: fixture() }), state);
    assert.equal(graphReducer(state, { type: 'highlight', highlight: 'late' }).highlight, '');
    assert.equal(graphReducer(state, { type: 'reset' }).highlight, '');
    assert.deepEqual(classReportView(report, 'late'), exported);
    assert.deepEqual(sessionPage(report.rows, 1, 10, 'incomplete'), details);
});

test('summary highlight opens the pie locally and Back restores the previous comparison and filters', () => {
    const before = { ...initialGraphState(), chartType: 'bar', view: 'faculty', faculty: '1', subject: 'DCIT 24' };
    const selected = graphReducer(before, { type: 'highlight', highlight: 'missed' });
    assert.equal(selected.chartType, 'donut');
    assert.equal(selected.view, 'overall');
    assert.equal(selected.faculty, before.faculty);
    assert.equal(selected.subject, before.subject);
    assert.deepEqual(graphReducer(selected, { type: 'back' }), before);
});
