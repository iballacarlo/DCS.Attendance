import { Head, Link, router, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import axios from 'axios';
import { CheckCircle2, KeyRound, LogOut, ScanLine } from 'lucide-react';
import CutoffPicker from '../../components/CutoffPicker';
import { formatDate, fullName, time12 } from '../../lib/format';
import { generationError, generationKey, generationPayload } from '../../lib/attendanceGenerator';
import '../../../css/attendanceGenerator.css';

export default function GeneratorIndex({ filters, employees }) {
    const { auth, flash } = usePage().props;
    const [selection, setSelection] = useState(filters);
    const [preview, setPreview] = useState(null);
    const [result, setResult] = useState(null);
    const [busy, setBusy] = useState('');
    const [error, setError] = useState('');
    const requestBusy = useRef(false);
    const key = generationKey(selection);
    const change = (field, value) => {
        if (requestBusy.current) return;
        setSelection((old) => ({ ...old, [field]: value })); setPreview(null); setResult(null); setError('');
    };
    const run = async (action) => {
        if (requestBusy.current) return;
        requestBusy.current = true; setBusy(action); setError(''); setResult(null);
        try {
            const { data } = await axios.post(`/tools/attendance-generator/${action}`, action === 'preview' ? selection : generationPayload(selection));
            if (action === 'preview') setPreview({ ...data, key });
            else { setPreview(null); setResult(data.result); }
        } catch (failure) {
            setPreview(null); setError(generationError(failure));
        } finally { requestBusy.current = false; setBusy(''); }
    };
    const summary = result || preview?.preview;

    return <>
        <Head title="Attendance Generator | DCS Attendance & DTR" />
        <main id="generator-workspace">
            <header className="generator-header"><div className="generator-brand"><img src="/images/cvsu-logo.png" alt="CvSU" /><div><strong>DCS Attendance &amp; DTR</strong><span>Attendance Generator</span></div></div>
                <nav aria-label="Account"><span>{auth.user.name}</span><Link href="/account/password"><KeyRound size={16} aria-hidden="true" />Change password</Link><button type="button" onClick={() => router.post('/logout')}><LogOut size={16} aria-hidden="true" />Logout</button></nav>
            </header>
            <section className="generator-panel" aria-labelledby="generator-title">
                <h1 id="generator-title">Generate attendance logs</h1>
                <p className="generator-period">Random mix: 90% on time, 3% late, 3% undertime, 2% late &amp; undertime, 2% absent. Small batches may vary.</p>
                <form onSubmit={(event) => { event.preventDefault(); run('generate'); }}>
                    <div className="generator-filters">
                        <label>Attendance<select value={selection.mode} disabled={!!busy} onChange={(event) => change('mode', event.target.value)}><option value="class">Class attendance</option><option value="school">School IN/OUT</option></select></label>
                        <CutoffPicker value={selection.cutoff_start} disabled={!!busy} onChange={(period) => change('cutoff_start', period.start)} />
                        <label>Faculty<select value={selection.employee_id} disabled={!!busy} onChange={(event) => change('employee_id', event.target.value)}><option value="">All active faculty</option>{employees.map((employee) => <option key={employee.id} value={employee.id}>{fullName(employee)}</option>)}</select></label>
                    </div>
                    <label className="generator-overwrite-option"><input type="checkbox" checked={!!selection.overwrite} disabled={!!busy} onChange={(event) => change('overwrite', event.target.checked)} /><span>Overwrite existing logs <small>Replace existing IN/OUT times in the selected period.</small></span></label>
                    <div className="generator-actions"><button type="button" onClick={() => run('preview')} disabled={!!busy}><ScanLine size={17} aria-hidden="true" />{busy === 'preview' ? 'Checking records…' : 'Preview'}</button>
                        <button type="submit" className="generator-generate" disabled={!!busy}>{busy === 'generate' ? 'Generating…' : selection.overwrite ? 'Generate / overwrite records' : 'Generate missing records'}</button></div>
                </form>
                {error && <p className="generator-error" role="alert">{error}</p>}
                {flash?.success && !summary && <p className="generator-success" role="status">{flash.success}</p>}
            </section>
            {summary && <section className="generator-panel" aria-labelledby="generator-result-title" aria-busy={!!busy}>
                <h2 id="generator-result-title">{result ? <><CheckCircle2 size={21} aria-hidden="true" />Generation complete</> : 'Preview'}</h2>
                <p className="generator-period">{summary.mode === 'class' ? 'Class attendance' : 'School IN/OUT'} · {formatDate(summary.start_date)} – {formatDate(summary.end_date)}</p>
                <div className="generator-summary" role="status"><div><span>{result ? 'Created' : 'New records'}</span><strong>{result ? summary.created : summary.to_create}</strong></div><div><span>{result ? 'Updated' : 'To overwrite'}</span><strong>{result ? summary.updated : summary.to_overwrite}</strong></div><div><span>Skipped items</span><strong>{summary.skipped}</strong></div><div><span>Faculty selected</span><strong>{summary.faculty_count}</strong></div></div>
                {summary.absent > 0 && <p className="generator-period">Absent: {summary.absent} {summary.mode === 'class' ? 'classes' : 'school days'} (no IN/OUT generated).</p>}
                {Object.keys(summary.reasons).length > 0 && <details className="generator-skips"><summary>Skipped items breakdown</summary><ul>{Object.entries(summary.reasons).map(([reason, count]) => <li key={reason}><span>{reason}</span><strong>{count}</strong></li>)}</ul></details>}
                {summary.samples.length > 0 && <><p className="generator-sample-caption">{result ? 'Processed records' : 'Sample records'} · showing up to 10</p><div className="generator-table"><table><thead><tr><th>Faculty</th><th>Date</th><th>Session</th><th>Action</th>{selection.overwrite && <><th>Previous IN</th><th>Previous OUT</th></>}<th>IN</th><th>OUT</th></tr></thead><tbody>{summary.samples.map((sample, index) => <tr key={`${sample.date}:${index}`}><td>{sample.faculty}</td><td>{formatDate(sample.date)}</td><td>{sample.subject}</td><td>{sample.action === 'overwrite' ? result ? 'Updated' : 'Overwrite' : result ? 'Created' : 'New'}</td>{selection.overwrite && <><td>{time12(sample.previous_in)}</td><td>{time12(sample.previous_out)}</td></>}<td>{time12(sample.time_in)}</td><td>{time12(sample.time_out)}</td></tr>)}</tbody></table></div></>}
                {!summary.eligible && !summary.created && !summary.absent && <p>No records are eligible for this selection.</p>}
            </section>}
        </main>
    </>;
}
