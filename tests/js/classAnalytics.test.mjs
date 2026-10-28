import test from 'node:test';
import assert from 'node:assert/strict';
import { attendanceOverview, attendanceTrend, classReportView, sessionIndicators, sessionPage } from '../../resources/js/lib/classAnalytics.js';

const day = (label, complete, incomplete = 0, missed = 0) => ({ label, complete, incomplete, missed, ended: complete + incomplete + missed });

test('overview counts only finished classes and separates future and excluded sessions', () => {
    assert.deepEqual(attendanceOverview({ expected: 12, ended: 8, complete: 5, incomplete: 1, missed: 2, excluded: 3 }), {
        due: 8, complete: 5, needsReview: 3, notYetFinished: 4, rate: 62.5,
    });
    assert.equal(attendanceOverview({ expected: 5, ended: 0, complete: 0 }).rate, null);
});

test('short trends preserve actual daily counts in date order', () => {
    const trend = attendanceTrend([day('2026-10-07', 1, 2), day('2026-10-05', 5, 1, 2)]);
    assert.equal(trend.unit, 'day');
    assert.deepEqual(trend.rows.map((r) => r.key), ['2026-10-05', '2026-10-07']);
    assert.deepEqual(trend.rows.map((r) => r.ended), [8, 3]);
});

test('weekly charts add counts across year boundaries instead of averaging rates', () => {
    const trend = attendanceTrend([day('2026-12-28', 1, 0, 0), day('2027-01-01', 0, 0, 9), day('2027-01-20', 2, 1)]);
    assert.equal(trend.unit, 'week');
    assert.equal(trend.rows[0].key, '2026-12-28');
    assert.equal(trend.rows[0].ended, 10);
    assert.equal(attendanceOverview(trend.rows[0]).rate, 10);
    assert.equal(trend.rows.reduce((sum, r) => sum + r.ended, 0), 13);
});

test('long trends use chronological month buckets and preserve all status totals', () => {
    const trend = attendanceTrend([day('2026-10-05', 3, 2, 1), day('2026-01-05', 1, 2, 3), day('2026-01-12', 4, 0, 2)]);
    assert.equal(trend.unit, 'month');
    assert.deepEqual(trend.rows.map((r) => [r.key, r.complete, r.incomplete, r.missed, r.ended]), [
        ['2026-01', 5, 2, 5, 12], ['2026-10', 3, 2, 1, 6],
    ]);
});

test('empty trends have no fabricated data points', () => {
    assert.deepEqual(attendanceTrend([]), { unit: 'day', rows: [] });
});

test('session pagination limits rows, clamps the last page, and keeps the full export data intact', () => {
    const rows = Array.from({ length: 57 }, (_, id) => ({ id, status: 'complete', ended: true }));
    const second = sessionPage(rows, 2, 10);
    assert.deepEqual(second.rows.map((r) => r.id), [10, 11, 12, 13, 14, 15, 16, 17, 18, 19]);
    assert.equal(second.total, 57);
    assert.equal(second.pages, 6);
    assert.equal(second.from, 11);
    assert.equal(second.to, 20);
    assert.equal(sessionPage(rows, 20, 25).current, 3);
    assert.equal(sessionPage(rows, 20, 25).rows.length, 7);
    assert.equal(sessionPage(rows, 1, 50).rows.length, 50);
    assert.equal(rows.length, 57);
});

test('chart status drilldown matches finished-class metrics and resets empty pages safely', () => {
    const rows = [{ status: 'incomplete', ended: false }, { status: 'incomplete', ended: true }, { status: 'missed', ended: true }];
    assert.equal(sessionPage(rows, 1, 10, 'incomplete').total, 1);
    assert.equal(sessionPage(rows, 1, 10, 'missed').total, 1);
    assert.deepEqual(sessionPage(rows, 9, 10, 'complete'), { rows: [], current: 1, pages: 1, total: 0, from: 0, to: 0 });
});

test('time-bar drilldown uses only actual dates inside the selected report period', () => {
    const result = attendanceTrend([day('2026-10-07', 1), day('2026-10-09', 1), day('2026-10-29', 1)]);
    assert.equal(result.unit, 'week');
    assert.equal(result.rows[0].start_date, '2026-10-07');
    assert.equal(result.rows[0].end_date, '2026-10-09');
});

test('late and undertime drilldowns can overlap without including future or excluded sessions', () => {
    const rows = [
        { status: 'complete', ended: true, late_minutes: 5, early_departure_minutes: 10 },
        { status: 'incomplete', ended: true, late_minutes: 2, early_departure_minutes: null },
        { status: 'excluded', ended: true, late_minutes: 5, early_departure_minutes: 10 },
        { status: 'complete', ended: false, late_minutes: 5, early_departure_minutes: 10 },
        { status: 'missed', ended: true, late_minutes: null, early_departure_minutes: null },
        { status: 'complete', ended: true, late_minutes: 0, early_departure_minutes: 0 },
    ];
    assert.equal(sessionPage(rows, 1, 10, 'late').total, 2);
    assert.equal(sessionPage(rows, 1, 10, 'undertime').total, 1);
    assert.equal(sessionPage(rows, 1, 10, 'on_time').total, 1);
    assert.equal(sessionPage(rows, 1, 10, 'missed').total, 1);
    assert.deepEqual(sessionIndicators(rows[0]).map((item) => item.key), ['late', 'undertime']);
    assert.deepEqual(sessionIndicators(rows[1]).map((item) => item.key), ['incomplete', 'late']);
    assert.deepEqual(sessionIndicators(rows[4]).map((item) => item.key), ['missed']);
    assert.deepEqual(sessionIndicators(rows[5]).map((item) => item.key), ['on_time']);
});

test('status exports include all matching pages and recompute summary and faculty/subject totals', () => {
    const report = { filters: {}, rows: Array.from({ length: 27 }, (_, id) => ({ id, employee_id: id % 2, faculty: `Faculty ${id % 2}`, subject: 'DCIT 21', date: '2026-10-05', status: 'complete', ended: true, late_minutes: 5, early_departure_minutes: id % 2 ? 10 : 0 })) };
    report.rows.push({ status: 'excluded', ended: true, late_minutes: 99 });
    const view = classReportView(report, 'late');
    assert.equal(view.rows.length, 27);
    assert.equal(view.summary.late, 27);
    assert.equal(view.summary.undertime, 13);
    assert.equal(view.summary.total_late_minutes, 135);
    assert.equal(view.summary.total_undertime_minutes, 130);
    assert.equal(view.summary.on_time, 0);
    assert.equal(view.summary.completion_rate, 100);
    assert.equal(view.faculty_breakdown.reduce((sum, row) => sum + row.late, 0), 27);
    assert.equal(view.subject_breakdown[0].total_late_minutes, 135);
    assert.equal(view.trend[0].total_undertime_minutes, 130);
    assert.equal(report.rows.length, 28);
    assert.equal(classReportView(report), report);
});
