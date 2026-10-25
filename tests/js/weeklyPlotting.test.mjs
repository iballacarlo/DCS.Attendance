import test from 'node:test';
import assert from 'node:assert/strict';
import { weeklyPlotting } from '../../resources/js/lib/weeklyPlotting.js';

const block = (start_time, end_time, extra = {}) => ({ day_of_week: 1, schedule_type: 'class', subject: 'DCIT 21', section: 'BSIT 1A', class_mode: 'lecture', start_time, end_time, ...extra });
test('weekly plotting keeps adjacent classes separate and covers exact partial-hour boundaries', () => {
    const model = weeklyPlotting([block('07:15', '08:00'), block('08:00', '09:00')]);
    const cells = model.rows.flatMap((r) => r.cells.filter((c) => c?.block));
    assert.equal(cells.length, 2);
    assert.notEqual(cells[0].block.key, cells[1].block.key);
    assert.ok(model.rows.some((r) => r.start === 7 * 60 + 15));
    const mondaySlots = model.rows.reduce((sum, row) => sum + (row.cells[0]?.span || 0), 0);
    assert.equal(mondaySlots, model.rows.length);
});
test('weekly totals separate lecture/lab and include paid consultation but exclude breaks', () => {
    const model = weeklyPlotting([block('08:00', '10:00'), block('10:00', '12:00', { class_mode: 'laboratory' }), block('12:00', '13:00', { schedule_type: 'lunch_break' }), block('13:00', '14:00', { schedule_type: 'consultation' })]);
    assert.equal(model.courses.length, 1);
    assert.equal(model.courses[0].lecture, 2);
    assert.equal(model.courses[0].laboratory, 2);
    assert.equal(model.consultationHours, 1);
    assert.equal(model.payableHours, 5);
});
test('weekly plotting expands for early/evening blocks and still shows six days when empty', () => {
    const expanded = weeklyPlotting([block('06:00', '06:30'), block('21:30', '22:00', { day_of_week: 6 })]);
    assert.equal(expanded.rows[0].start, 360);
    assert.equal(expanded.rows.at(-1).end, 1320);
    assert.ok(expanded.rows.some((row) => row.cells[5]?.block));
    const empty = weeklyPlotting([]);
    assert.equal(empty.payableHours, 0);
    assert.ok(empty.rows.every((row) => row.cells.length === 6));
});
