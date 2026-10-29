import test from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';

const hardware = new URL('../../hardware/', import.meta.url);
const online = fs.readFileSync(new URL('DCSAttendanceTFT/DCSAttendanceTFT.ino', hardware), 'utf8');
const local = fs.readFileSync(new URL('DCSAttendanceTFTLocal/DCSAttendanceTFTLocal.ino', hardware), 'utf8');
const localSettings = fs.readFileSync(new URL('DCSAttendanceTFTLocal/local_server.h', hardware), 'utf8');
const constant = (source, name) => {
    const match = source.match(new RegExp('const char\\* ' + name + ' = "([^"]+)";'));
    assert.ok(match, `${name} must have an explicit storage path`);
    return match[1];
};
const namespace = (source) => {
    const match = source.match(/offlinePreferences\.begin\("([^"]+)"/);
    assert.ok(match, 'Offline queue must have an explicit NVS namespace');
    return match[1];
};
const body = (source, name) => {
    const match = source.match(new RegExp('^[\\w&* ]+\\b' + name + '\\([^;]*?\\) \\{[\\s\\S]*?^\\}', 'm'));
    assert.ok(match, `Missing shared hardware function: ${name}`);
    return match[0];
};

test('local and Railway queues, RFID caches and NVS state never share storage keys', () => {
    const names = ['OFFLINE_QUEUE_PATH', 'RFID_CACHE_PATH', 'RFID_CACHE_TEMP', 'RFID_CACHE_BACKUP'];
    const railwayPaths = names.map((name) => constant(online, name));
    const localPaths = names.map((name) => constant(local, name));
    assert.equal(new Set(localPaths).size, names.length);
    for (const path of localPaths) assert.ok(!railwayPaths.includes(path), `Shared flash path: ${path}`);
    assert.notEqual(namespace(local), namespace(online));
    assert.ok(namespace(local).length <= 15, 'ESP32 NVS namespace exceeds 15 characters');
    const eventPrefix = (source) => source.match(/String eventId = "([^"]+)"/)[1];
    assert.notEqual(eventPrefix(local), eventPrefix(online));
    assert.doesNotMatch(local, /LittleFS\.format\s*\(/, 'Local mode must never format Railway flash storage');
});

test('local testing retains the exact TFT, RTC, RFID, fingerprint and indicator pins', () => {
    const pins = (source) => [...source.matchAll(/^#define (\w+_PIN) (\d+)$/gm)].map((match) => [match[1], Number(match[2])]);
    assert.ok(pins(online).length >= 15);
    assert.deepEqual(pins(local), pins(online));
});

test('attendance recording, per-class responses, retries and scan behavior remain identical', () => {
    for (const name of ['attendancePayload', 'createAttendanceRecord', 'showAttendanceResponse',
        'sendAttendance', 'syncNextOfflineAttendance', 'processRfidAttendance', 'processFingerprintAttendance',
        'waitSignal', 'showTimeOutWait', 'refreshTimeOutCountdown', 'showClock']) {
        assert.equal(body(local, name), body(online, name), `Local behavior drifted: ${name}`);
    }
});

test('local HTTP stays LAN-only without weakening the Railway TLS client', () => {
    assert.match(local, /WiFiClient localClient;/);
    assert.match(local, /if \(!localServerSettingsValid\(\)\)/);
    assert.ok(local.indexOf('if (!localServerSettingsValid())') < local.indexOf('http.begin(localClient'));
    assert.match(local, /setFollowRedirects\(HTTPC_DISABLE_FOLLOW_REDIRECTS\)/);
    assert.doesNotMatch(local, /up\.railway\.app|setInsecure\s*\(/);
    assert.match(localSettings, /address\[0\] == 10/);
    assert.match(localSettings, /address\[0\] == 172 && address\[1\] >= 16 && address\[1\] <= 31/);
    assert.match(localSettings, /address\[0\] == 192 && address\[1\] == 168/);
    assert.match(online, /WiFiClientSecure secureClient;/);
    assert.match(online, /setCACert\(HARDWARE_ROOT_CA\)/);
    assert.match(online, /https:\/\/dcsattendance\.up\.railway\.app/);
    assert.match(local, /String\("LOCAL \/ "\) \+ heading/);
});


test('WAIT never lights the green success LED or reports a recorded OUT', () => {
    for (const firmware of [online, local]) {
        const signal = body(firmware, 'waitSignal');
        assert.match(signal, /digitalWrite\(GREEN_LED_PIN, LOW\)/);
        assert.match(signal, /digitalWrite\(YELLOW_LED_PIN, HIGH\)/);
        assert.doesNotMatch(signal, /digitalWrite\(GREEN_LED_PIN, HIGH\)/);
        const response = body(firmware, 'showAttendanceResponse');
        const waitBranch = response.slice(response.indexOf('if (action == "WAIT")'), response.indexOf('return;', response.indexOf('if (action == "WAIT")')));
        assert.match(waitBranch, /OUT NOT RECORDED/);
        assert.match(waitBranch, /waitSignal\(\)/);
        assert.doesNotMatch(waitBranch, /doneSignal\(\)|successSignal\(\)/);
        assert.match(body(firmware, 'showTimeOutWait'), /DISPLAY_WARNING/);
        assert.match(body(firmware, 'showClock'), /timeOutAvailableAt > time\(nullptr\)/);
    }
});
