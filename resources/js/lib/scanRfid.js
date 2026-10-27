export function normalizeRfidSerial(serial) {
    const uid = String(serial || '').replace(/[:\s-]/g, '').toUpperCase();
    if (!uid || uid.length > 100 || !/^(?:[0-9A-F]{2})+$/.test(uid)) {
        throw new Error('This card does not expose a usable RFID UID. Use another compatible card or enter its registered UID.');
    }
    return uid;
}

// Read the card identifier only. NDEF text and URLs are never used as identity.
export function scanRfid({ signal, onReadingError, environment = window }) {
    if (!environment.isSecureContext) {
        return Promise.reject(new Error('NFC scanning requires HTTPS. Open the secure kiosk address on your phone.'));
    }
    if (!environment.NDEFReader) {
        return Promise.reject(new Error('NFC scanning is unavailable in this browser. Use Chrome on an NFC-enabled Android phone, or enter the RFID UID.'));
    }
    return new Promise((resolve, reject) => {
        const reader = new environment.NDEFReader();
        const finish = (error, uid) => {
            reader.onreading = null;
            reader.onreadingerror = null;
            signal.removeEventListener('abort', cancel);
            if (error) reject(error); else resolve(uid);
        };
        const cancel = () => finish(new DOMException('Scan cancelled', 'AbortError'));
        if (signal.aborted) { cancel(); return; }
        signal.addEventListener('abort', cancel, { once: true });
        reader.onreading = (event) => {
            try { finish(null, normalizeRfidSerial(event.serialNumber)); }
            catch (error) { finish(error); }
        };
        reader.onreadingerror = () => onReadingError?.('Could not read this card. Hold it near the NFC reader or try another compatible card.');
        try {
            reader.scan({ signal }).catch((error) => {
                const messages = {
                    NotAllowedError: 'NFC permission was denied. Allow NFC access and try again.',
                    NotSupportedError: 'NFC is unavailable. Check that this phone has NFC and that it is enabled.',
                    NotReadableError: 'Could not access NFC. Turn NFC on and close other NFC apps, then try again.',
                };
                finish(error.name === 'AbortError' ? error : new Error(messages[error.name] || 'Could not start NFC. Check NFC settings and try again.'));
            });
        } catch (error) { finish(error); }
    });
}
