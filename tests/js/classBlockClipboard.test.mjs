import test from 'node:test';
import assert from 'node:assert/strict';
import { classDetailsForPaste, decodeClassDetails, encodeClassDetails } from '../../resources/js/lib/classBlockClipboard.js';

test('class clipboard copies only delivery, section, and subject, excluding identity and timing', () => {
    const text = encodeClassDetails({ id: 42, employee_id: 7, schedule_type: 'class', class_mode: 'laboratory', section: 'BSIT 2A', subject: 'DCIT 24', day_of_week: 3, start_time: '08:00', end_time: '10:00' });
    const details = decodeClassDetails(text);
    assert.deepEqual(details, { class_mode: 'laboratory', section: 'BSIT 2A', subject: 'DCIT 24' });
    const target = { id: 99, day_of_week: 6, start_time: '13:00', end_time: '14:00', schedule_type: 'class', ...details };
    assert.equal(target.id, 99);
    assert.equal(target.day_of_week, 6);
    assert.equal(target.start_time, '13:00');
    assert.equal(target.end_time, '14:00');
    assert.equal(target.subject, 'DCIT 24');
    assert.equal(text.includes('employee_id'), false);
});

test('clipboard ignores ordinary text, malformed data, breaks, and incomplete class details', () => {
    for (const text of ['DCIT 24', '{broken', 'null', '{}', JSON.stringify({ type: 'dcs-class-details', version: 2, class_mode: 'lecture', section: 'BSIT 1A', subject: 'NSTP 1' }), JSON.stringify({ type: 'dcs-class-details', version: 1, class_mode: 'unknown', section: 'BSIT 1A', subject: 'NSTP 1' })]) assert.equal(decodeClassDetails(text), null);
    assert.equal(encodeClassDetails({ schedule_type: 'consultation' }), null);
    assert.equal(encodeClassDetails({ schedule_type: 'lunch_break' }), null);
    assert.equal(encodeClassDetails({ schedule_type: 'class', class_mode: 'lecture', section: '', subject: '' }), null);
});

test('clipboard drops extra properties from pasted structured data', () => {
    const text = JSON.stringify({ type: 'dcs-class-details', version: 1, class_mode: 'lecture', section: 'BSIT 1A', subject: 'NSTP 1', id: 123, day_of_week: 2, start_time: '06:00', schedule_type: 'lunch_break' });
    assert.deepEqual(decodeClassDetails(text), { class_mode: 'lecture', section: 'BSIT 1A', subject: 'NSTP 1' });
});

test('pasting reverses delivery while preserving subject, section, and target timing', () => {
    for (const [from, to] of [['lecture', 'laboratory'], ['laboratory', 'lecture']]) {
        const copied = decodeClassDetails(encodeClassDetails({ schedule_type: 'class', class_mode: from, section: 'BSIT 2B', subject: 'DCIT 24' }));
        const target = { id: 8, day_of_week: 4, start_time: '13:00', end_time: '15:00', ...classDetailsForPaste(copied) };
        assert.deepEqual(target, { id: 8, day_of_week: 4, start_time: '13:00', end_time: '15:00', class_mode: to, section: 'BSIT 2B', subject: 'DCIT 24' });
        assert.equal(copied.class_mode, from);
    }
});

test('lecture-only subjects remain Lecture when pasted', () => {
    const restricted = ['NSTP 1', 'NSTP 2', 'CVSU 101'];
    for (const subject of restricted) {
        assert.deepEqual(classDetailsForPaste({ class_mode: 'lecture', section: 'BSIT 1A', subject }, restricted), { class_mode: 'lecture', section: 'BSIT 1A', subject });
    }
});
