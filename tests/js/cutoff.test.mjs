import test from 'node:test';
import assert from 'node:assert/strict';
import { cutoffPeriod, currentCutoff } from '../../resources/js/lib/cutoff.js';

test('cutoffs derive calendar month ends and leap years without locale parsing', () => {
    for (const [start, end] of [['2028-02-16', '2028-02-29'], ['2027-02-16', '2027-02-28'], ['2026-04-16', '2026-04-30'], ['2026-12-16', '2026-12-31'], ['2027-01-01', '2027-01-15']]) {
        assert.equal(cutoffPeriod(start).end, end);
    }
    assert.equal(cutoffPeriod('2028-02-16').label, '02/16/2028 - 02/29/2028');
    assert.equal(cutoffPeriod('2000-02-16').end, '2000-02-29');
    assert.equal(cutoffPeriod('2100-02-16').end, '2100-02-28');
    assert.equal(currentCutoff().start.slice(8) === '01' || currentCutoff().start.slice(8) === '16', true);
});

test('cutoffs reject malformed dates, other days and unsupported years', () => {
    for (const value of ['2026-10-02', '2026-13-01', '2026-00-16', '2026-2-01', '1999-12-16', '1900-01-01', '1899-01-01', '2101-01-01', '', 'October', null]) {
        assert.equal(cutoffPeriod(value), null);
    }
});
