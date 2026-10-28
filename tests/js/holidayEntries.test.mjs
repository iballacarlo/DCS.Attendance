import test from 'node:test';
import assert from 'node:assert/strict';
import { holidayEntries } from '../../resources/js/lib/holidayEntries.js';

const entry = (id, type, start, end = start, extra = {}) => ({ id, type, title: String(id), start_date: start, end_date: end, scope_type: 'campus', ...extra });

test('year list includes automatic holidays and cross-year suspensions in date order', () => {
    const manual = [entry(1, 'suspension', '2025-12-31', '2026-01-02'), entry(2, 'suspension', '2026-10-28'), entry(3, 'suspension', '2027-01-02')];
    const regular = [entry('new-year', 'regular_holiday', '2026-01-01'), entry('christmas', 'regular_holiday', '2026-12-25')];
    assert.deepEqual(holidayEntries(manual, regular, 2026).map((row) => row.id), [1, 'new-year', 2, 'christmas']);
    assert.equal(manual.length, 3);
});

test('only a campus regular holiday covering the same date suppresses its automatic duplicate', () => {
    const regular = [entry('automatic', 'regular_holiday', '2026-12-25')];
    const manual = entry(1, 'regular_holiday', '2026-12-24', '2026-12-26');
    assert.deepEqual(holidayEntries([manual], regular, 2026).map((row) => row.id), [1]);
    assert.equal(holidayEntries([{ ...manual, scope_type: 'faculty' }], regular, 2026).length, 2);
    assert.equal(holidayEntries([{ ...manual, type: 'suspension' }], regular, 2026).length, 2);
});

test('type/search filters preserve historical entries and handle empty results', () => {
    const manual = [entry(1, 'suspension', '2026-10-28', undefined, { title: 'Typhoon suspension' }), entry(2, 'asynchronous', '2026-10-29')];
    assert.deepEqual(holidayEntries(manual, [], 2026, 'suspension', 'TYPHOON').map((row) => row.id), [1]);
    assert.equal(holidayEntries(manual, [], 2026).length, 2);
    assert.equal(holidayEntries(manual, [], 2026, 'regular_holiday').length, 0);
    assert.equal(holidayEntries([], [], 2028).length, 0);
});
