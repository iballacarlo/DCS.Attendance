import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import axios from 'axios';
import { ArrowDownLeft, ArrowUpRight, CheckCircle2, LayoutDashboard, LogOut, Nfc, ScanLine, ShieldCheck, UserRound } from 'lucide-react';
import '../../../css/kiosk.css';
import { scanRfid } from '../../lib/scanRfid';

export default function EntranceKiosk() {
    const { auth, flash } = usePage().props;
    const form = useForm({ identifier: '', occurred_at: '' });
    const input = useRef(null);
    const [clock, setClock] = useState(new Date());
    const [preview, setPreview] = useState(null);
    const [previewError, setPreviewError] = useState('');
    const [revision, setRevision] = useState(0);
    const [checking, setChecking] = useState(false);
    const [scanning, setScanning] = useState(false);
    const [scanMessage, setScanMessage] = useState('');
    const scanner = useRef(null);
    useEffect(() => () => scanner.current?.abort(), []);
    const day = new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila', year: 'numeric', month: '2-digit', day: '2-digit' }).format(clock);
    const key = JSON.stringify([form.data.identifier.trim(), form.data.occurred_at, day]);
    useEffect(() => { const timer = setInterval(() => setClock(new Date()), 1000); return () => clearInterval(timer); }, []);
    useEffect(() => {
        setPreview(null); setPreviewError('');
        if (!form.data.identifier.trim() || form.processing) { setChecking(false); return; }
        setChecking(true);
        const controller = new AbortController();
        const timer = setTimeout(async () => {
            try {
                const { data } = await axios.post('/entrance-kiosk/preview', form.data, { signal: controller.signal });
                if (!controller.signal.aborted) setPreview({ ...data, key });
            } catch (error) {
                if (!controller.signal.aborted) setPreviewError(Object.values(error.response?.data?.errors || {})[0]?.[0] || 'Could not check this RFID. Try again.');
            } finally { if (!controller.signal.aborted) setChecking(false); }
        }, 300);
        return () => { clearTimeout(timer); controller.abort(); };
    }, [key, revision, form.processing]);
    const current = preview?.key === key ? preview : null;
    const next = current?.next_action || (!form.data.identifier.trim() ? 'IN' : null);
    const submit = (event) => {
        event.preventDefault();
        if (!current?.next_action || checking || form.processing) return;
        form.transform((data) => ({ ...data, expected_action: current.next_action }));
        form.post('/entrance-kiosk', {
            preserveScroll: true,
            onSuccess: () => {
                form.reset('identifier');
                setPreview(null);
                setPreviewError('');
                setScanMessage('');
                setChecking(false);
                requestAnimationFrame(() => input.current?.focus());
            },
            onFinish: () => setRevision((value) => value + 1),
        });
    };
    const change = (field, value) => { form.clearErrors(); form.setData(field, value); };
    const startScan = async () => {
        if (scanner.current || form.processing) return;
        const controller = new AbortController();
        scanner.current = controller;
        setScanning(true);
        setScanMessage('Hold the RFID card near the back of this phone.');
        setPreview(null);
        change('identifier', '');
        try {
            const uid = await scanRfid({ signal: controller.signal, onReadingError: setScanMessage });
            if (!controller.signal.aborted) {
                change('identifier', uid);
                setScanMessage('Card scanned. Check the faculty name, then record attendance.');
                setRevision((value) => value + 1);
            }
        } catch (error) {
            if (!controller.signal.aborted) setScanMessage(error.message);
        } finally {
            controller.abort();
            if (scanner.current === controller) scanner.current = null;
            setScanning(false);
        }
    };
    const stopScan = () => { scanner.current?.abort(); setScanMessage('Scan stopped.'); };
    return <>
        <Head title="Entrance Kiosk | DCS Attendance & DTR" />
        <main className="guard-kiosk">
            <header className="guard-kiosk-header"><div className="kiosk-brand"><img src="/images/cvsu-logo.png" alt="Cavite State University" /><span><strong>DCS Attendance &amp; DTR</strong><small>Cavite State University · Imus Campus</small></span></div><div className="kiosk-header-actions"><Link href="/" aria-label="Dashboard"><LayoutDashboard size={17} /><span>Dashboard</span></Link><button type="button" className="kiosk-signout" aria-label="Sign out" onClick={() => router.post('/logout')}><LogOut size={16} /><span>Sign out</span></button></div></header>
            <div className="kiosk-station">
            <aside className="kiosk-welcome">
                <div className="kiosk-station-label"><span /> ENTRANCE STATION</div>
                <div className="kiosk-welcome-body"><div className="kiosk-rfid-mark" role="img" aria-label="Contactless RFID"><Nfc size={48} strokeWidth={1.3} /></div><h1>School<br />attendance.</h1><section className="guard-kiosk-clock" aria-label="Current Philippine time"><strong>{new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(clock)}</strong><span>{new Intl.DateTimeFormat('en-PH', { timeZone: 'Asia/Manila', dateStyle: 'full' }).format(clock)}</span></section></div>
                <div className="kiosk-guard"><span className="kiosk-guard-icon"><ShieldCheck size={19} /></span><div><small>ON DUTY</small><strong>{auth.user.name}</strong></div></div>
            </aside>
            <section className="guard-kiosk-card" aria-labelledby="kiosk-record-title">
                <div className="guard-kiosk-title"><div><span className="kiosk-eyebrow">RFID</span><h2 id="kiosk-record-title">Record attendance</h2></div><span className="kiosk-title-icon"><ScanLine size={23} strokeWidth={1.6} /></span></div>
                {flash?.success && !previewError && !Object.keys(form.errors).length && <div className="guard-kiosk-success" role="status"><CheckCircle2 size={18} /><span>{flash.success}</span></div>}
                <form onSubmit={submit}>
                    <div className="kiosk-nfc-controls"><button type="button" className="kiosk-scan-button" onClick={scanning ? stopScan : startScan} disabled={form.processing}><Nfc size={19} />{scanning ? 'Stop scanning' : 'Scan RFID'}</button><span role="status" aria-live="polite">{scanMessage}</span></div>
                    <label>RFID UID<span className="kiosk-uid-field"><ScanLine size={20} strokeWidth={1.6} /><input ref={input} autoFocus autoComplete="off" value={form.data.identifier} onChange={(e) => { setScanMessage(''); change('identifier', e.target.value.toUpperCase()); }} placeholder="Type or scan RFID UID" required maxLength={100} disabled={scanning || form.processing} /></span></label>
                    <div className={`guard-kiosk-preview${previewError ? ' has-error' : ''}${current ? ' has-faculty' : ''}`} role="status"><UserRound size={19} /><span>{checking ? 'Checking RFID...' : previewError || (current ? `${current.employee}${!current.next_action ? ' · Attendance complete' : ''}` : 'Waiting for RFID')}</span>{current && !current.next_action && <CheckCircle2 size={18} />}</div>
                    <div className="guard-kiosk-actions" aria-label="Automatic attendance direction">{['IN', 'OUT'].map((action) => <span key={action} className={`guard-kiosk-direction is-${action.toLowerCase()}${next === action ? ' active' : ''}`} aria-current={next === action ? 'step' : undefined}>{action === 'IN' ? <ArrowDownLeft size={23} /> : <ArrowUpRight size={23} />}<span><small>{action === 'IN' ? 'ARRIVAL' : 'DEPARTURE'}</small><strong>School {action}</strong></span>{next === action && <span className="kiosk-active-dot" />}</span>)}</div>
                    <div className="guard-kiosk-test"><label>Test date/time (optional)<input type="datetime-local" max={`${day}T23:59`} value={form.data.occurred_at} onChange={(e) => change('occurred_at', e.target.value)} /></label>{form.data.occurred_at && <button type="button" onClick={() => change('occurred_at', '')}>Use current time</button>}</div>
                    {Object.keys(form.errors).length > 0 && <div className="guard-kiosk-error" role="alert">{Object.values(form.errors).map((error, i) => <p key={i}>{error}</p>)}</div>}
                    <button className={`guard-kiosk-submit${next === 'OUT' ? ' is-out' : ''}`} type="submit" disabled={form.processing || checking || !current?.next_action}><span>{form.processing ? 'Recording...' : current && !current.next_action ? 'Attendance complete' : `Record School ${next || 'Attendance'}`}</span>{current && !current.next_action ? <CheckCircle2 size={19} /> : <ArrowUpRight size={19} />}</button>
                </form>
            </section>
            </div>
            <footer><span>Department of Computer Studies</span><span>Philippine Standard Time</span></footer>
        </main>
    </>;
}
