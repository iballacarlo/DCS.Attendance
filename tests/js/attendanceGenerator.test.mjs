import test from 'node:test';
import assert from 'node:assert/strict';
import { generationPayload, generationKey, generationError } from '../../resources/js/lib/attendanceGenerator.js';

test('preview scope includes mode, cutoff and faculty with normalized faculty identifiers', () => {
    const filters = { mode: 'class', cutoff_start: '2026-10-01', employee_id: 1 };
    const key = generationKey(filters);
    assert.equal(key, generationKey({ ...filters, employee_id: '1' }));
    assert.notEqual(key, generationKey({ ...filters, mode: 'school' }));
    assert.notEqual(key, generationKey({ ...filters, cutoff_start: '2026-10-16' }));
    assert.notEqual(key, generationKey({ ...filters, employee_id: '' }));
    assert.equal(generationKey({ ...filters, employee_id: '' }), generationKey({ ...filters, employee_id: null }));
});

test('expired, disabled and throttled requests have actionable error messages', () => {
    for (const [status, pattern] of [[401, /session expired/], [403, /unavailable/], [404, /unavailable/], [419, /Reload/], [423, /temporary password/], [429, /Too many requests/]]) {
        assert.match(generationError({ response: { status } }), pattern);
    }
});

test('validation messages are shown but server debug payloads and network details are not exposed', () => {
    assert.equal(generationError({ response: { status: 422, data: { errors: { preview_token: ['Preview again.'] } } } }), 'Preview again.');
    assert.match(generationError(new Error('network failure')), /generating again/);
    const message = generationError({ response: { status: 500, data: { message: 'DATABASE_SECRET', trace: ['private'] } } });
    assert.doesNotMatch(message, /DATABASE_SECRET|private/);
});

test('direct generation sends the current scope without a preview token and explicitly enables overwrite', () => {
    const filters = { mode: 'class', cutoff_start: '2026-10-01', employee_id: 1, overwrite: false };
    assert.deepEqual(generationPayload(filters), {
        mode: 'class', cutoff_start: '2026-10-01', employee_id: 1, overwrite: false, confirm_overwrite: false,
    });
    assert.deepEqual(generationPayload({ ...filters, mode: 'school', employee_id: '', overwrite: true }), {
        mode: 'school', cutoff_start: '2026-10-01', employee_id: null, overwrite: true, confirm_overwrite: true,
    });
    assert.deepEqual(generationPayload(filters), generationPayload(filters));
    assert.equal(Object.hasOwn(generationPayload(filters), 'preview_token'), false);
});

test('semester payload and preview key depend on semester rather than a leftover cutoff', () => {
    const filters = { mode: 'school', period: 'semester', term_id: 3, employee_id: '', cutoff_start: '2026-10-01', overwrite: true };
    assert.deepEqual(generationPayload(filters), { mode: 'school', period: 'semester', term_id: 3, employee_id: null, overwrite: true, confirm_overwrite: true });
    assert.equal(generationKey(filters), generationKey({ ...filters, term_id: '3', cutoff_start: '2026-09-16' }));
    assert.notEqual(generationKey(filters), generationKey({ ...filters, term_id: 4 }));
    assert.notEqual(generationKey(filters), generationKey({ ...filters, period: 'cutoff' }));
});
