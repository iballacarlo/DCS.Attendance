import test from 'node:test';
import assert from 'node:assert/strict';
import { normalizeRfidSerial, scanRfid } from '../../resources/js/lib/scanRfid.js';

function setup(scan = () => Promise.resolve()) {
    let reader;
    const controller = new AbortController();
    const environment = { isSecureContext: true, NDEFReader: class {
        constructor() { reader = this; }
        scan(options) { return scan(options); }
    } };
    return { controller, environment, reader: () => reader };
}

test('phone serial separators normalize to the existing hardware UID format', () => {
    assert.equal(normalizeRfidSerial('04:a1:02:ff'), '04A102FF');
    assert.equal(normalizeRfidSerial('04-a1 02 ff'), '04A102FF');
    for (const serial of ['', undefined, 'https://instagram.com/example', 'ABC', 'AA'.repeat(51)]) {
        assert.throws(() => normalizeRfidSerial(serial), /usable RFID UID/);
    }
});

test('scan uses card serial, never the Instagram NDEF payload, and detaches after one read', async () => {
    const fixture = setup();
    const promise = scanRfid({ ...fixture, signal: fixture.controller.signal });
    fixture.reader().onreading({ serialNumber: '04:a1:02:ff', message: { records: [{ recordType: 'url', data: 'https://instagram.com/example' }] } });
    assert.equal(await promise, '04A102FF');
    assert.equal(fixture.reader().onreading, null);
    assert.equal(fixture.reader().onreadingerror, null);
});

test('card with no serial is rejected even when it has readable NDEF content', async () => {
    const fixture = setup();
    const promise = scanRfid({ ...fixture, signal: fixture.controller.signal });
    fixture.reader().onreading({ serialNumber: '', message: { records: [{ recordType: 'text', data: '04A102FF' }] } });
    await assert.rejects(promise, /usable RFID UID/);
});

test('HTTP and unsupported browsers get actionable messages', async () => {
    await assert.rejects(scanRfid({ environment: { isSecureContext: false } }), /requires HTTPS/);
    await assert.rejects(scanRfid({ environment: { isSecureContext: true } }), /NFC-enabled Android/);
});

test('permission denial does not leave a scanner active', async () => {
    const fixture = setup(() => Promise.reject(new DOMException('Denied', 'NotAllowedError')));
    await assert.rejects(scanRfid({ ...fixture, signal: fixture.controller.signal }), /permission was denied/);
    assert.equal(fixture.reader().onreading, null);
});

test('cancel stops a pending scan without accepting a card', async () => {
    const fixture = setup();
    const promise = scanRfid({ ...fixture, signal: fixture.controller.signal });
    fixture.controller.abort();
    await assert.rejects(promise, { name: 'AbortError' });
    assert.equal(fixture.reader().onreading, null);
});

test('an unreadable card can be retried during the same scan', async () => {
    const fixture = setup();
    let warning;
    const promise = scanRfid({ ...fixture, signal: fixture.controller.signal, onReadingError: (message) => { warning = message; } });
    fixture.reader().onreadingerror();
    assert.match(warning, /Could not read this card/);
    fixture.reader().onreading({ serialNumber: '04a102ff' });
    assert.equal(await promise, '04A102FF');
});
