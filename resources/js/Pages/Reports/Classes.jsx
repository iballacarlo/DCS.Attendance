import { useEffect, useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import FacultyCombobox from '../../components/FacultyCombobox';
import ClassAnalytics from '../../components/ClassAnalytics';
import useDebouncedFilters from '../../hooks/useDebouncedFilters';
import useRealtimeReload from '../../hooks/useRealtimeReload';
import { classReportView, sessionIndicators, sessionPage, sessionStatusLabels } from '../../lib/classAnalytics';
import { fullName, time12 } from '../../lib/format';
import { openPdfPrintWindow, sendPdfToPrint } from '../../lib/documents';

const normalize = (f) => Object.fromEntries(['start_date', 'end_date', 'employee_id', 'subject', 'section', 'class_mode'].map((key) => [key, String(f[key] || '')]));
const labels = sessionStatusLabels;

export default function Classes({ report, employees, subjects, sections }) {
    const initialFilters = useRef(normalize(report.filters));
    const applied = JSON.stringify(normalize(report.filters));
    const [filters, setFilters] = useState(() => normalize(report.filters));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(10);
    const [status, setStatus] = useState('');
    const pending = JSON.stringify(filters) !== applied;
    const validRange = filters.start_date && filters.end_date && filters.end_date >= filters.start_date && (Date.parse(filters.end_date) - Date.parse(filters.start_date)) / 86400000 <= 366;
    useDebouncedFilters('/class-reports', filters, { enabled: !!validRange && !busy, delay: 450 });
    const refreshing = useRealtimeReload(['report'], 15000, !pending && !busy);
    useEffect(() => { setPage(1); setStatus(''); }, [applied]);
    const update = (field, value) => setFilters((old) => ({ ...old, [field]: String(value || '') }));
    const selectChart = (selection) => {
        setFilters((old) => ({ ...old, ...Object.fromEntries(Object.entries(selection).map(([key, value]) => [key, String(value || '')])) }));
        setPage(1); setStatus('');
    };
    const selectStatus = (value) => {
        setStatus((old) => old === value ? '' : value); setPage(1);
        document.getElementById('session-details')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    const details = sessionPage(report.rows, page, perPage, status);
    const firstPage = Math.max(1, Math.min(details.current - 2, details.pages - 4));
    const pageNumbers = Array.from({ length: Math.min(5, details.pages) }, (_, i) => firstPage + i);
    const download = async (kind) => {
        const popup = kind === 'print' ? openPdfPrintWindow() : null;
        if (kind === 'print' && !popup) { setError('Allow pop-ups for this site, then select Print again.'); return; }
        setBusy(true); setError('');
        try {
            const { exportClassReport, makeClassReportPdf } = await import('../../lib/classReportExport');
            const exportView = classReportView(report, status);
            if (kind === 'print') sendPdfToPrint(await makeClassReportPdf(exportView), popup);
            else await exportClassReport(exportView, kind);
        } catch { popup?.close(); setError('Could not prepare the report. Please try again.'); }
        finally { setBusy(false); }
    };
    return <AppLayout title="Class Reports & Analytics" >
        <section className="panel print-hidden">
            <div className="list-filters">
                <label>From<input type="date" value={filters.start_date} required onChange={(e) => update('start_date', e.target.value)} /></label>
                <label>To<input type="date" value={filters.end_date} required onChange={(e) => update('end_date', e.target.value)} /></label>
                <FacultyCombobox employees={employees} selectedId={filters.employee_id} allowClear onSelect={(id) => update('employee_id', id)} />
                {[['subject', 'Subject', subjects], ['section', 'Section', sections], ['class_mode', 'Class mode', ['lecture', 'laboratory']]].map(([field, label, options]) => <label key={field}>{label}<select value={filters[field]} onChange={(e) => update(field, e.target.value)}><option value="">All</option>{options.map((o) => <option key={o} value={o}>{o}</option>)}</select></label>)}
                <button type="button" className="ui-control ui-action ui-secondary" onClick={() => setFilters({ ...initialFilters.current })}>Reset view</button>
            </div>
            <div className="report-update-bar"><span role="status">{!validRange ? 'Choose an ordered date range of up to 366 days.' : pending ? 'Updating filters...' : refreshing ? 'Refreshing attendance...' : ''}</span><div className="ui-heading-actions">{[['print', 'Print'], ['pdf', 'Download PDF'], ['excel', 'Download Excel']].map(([kind, label]) => <button key={kind} className="ui-control ui-action ui-primary" disabled={busy || pending} onClick={() => download(kind)}>{label}</button>)}</div></div>
            {error && <p role="alert" className="field-error">{error}</p>}
        </section>
        <div className="panel-heading"><div><h2>{report.filters.start_date} – {report.filters.end_date}</h2><p>{[['Faculty', employees.find((e) => String(e.id) === String(report.filters.employee_id)) && fullName(employees.find((e) => String(e.id) === String(report.filters.employee_id)))], ['Subject', report.filters.subject], ['Section', report.filters.section], ['Mode', report.filters.class_mode]].filter(([, v]) => v).map(([k, v]) => `${k}: ${v}`).join(' · ') || 'All faculty, subjects, sections, and modes'}</p></div></div>
        <div aria-busy={pending || refreshing}><ClassAnalytics report={report} onSelect={selectChart} onStatusSelect={selectStatus} selectedStatus={status} /></div>
        <section className="panel session-details" id="session-details">
            <div className="panel-heading"><div><h2>Session details</h2><p>{details.from}–{details.to} of {details.total} matching sessions</p></div><div className="session-detail-controls">
                <label>Attendance status<select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}><option value="">All statuses</option>{Object.entries(labels).map(([value, label]) => <option key={value} value={value}>{label}{['complete', 'incomplete', 'missed', 'late', 'undertime', 'on_time'].includes(value) ? ' (finished classes)' : ''}</option>)}</select></label>
                <label>Rows per page<select value={perPage} onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1); }}>{[10, 25, 50].map((n) => <option key={n}>{n}</option>)}</select></label>
                {status && <button type="button" className="ui-control ui-action ui-secondary" onClick={() => { setStatus(''); setPage(1); }}>Clear status</button>}
            </div></div>
            <div className="table-wrap"><table><thead><tr><th>Date</th><th>Faculty</th><th>Subject / Section</th><th>Mode</th><th>Schedule</th><th>IN</th><th>OUT</th><th>Late (min)</th><th>Undertime (min)</th><th>Status</th></tr></thead><tbody>{details.rows.length ? details.rows.map((r) => <tr key={r.key}><td>{r.date}</td><td>{r.faculty}</td><td>{r.subject || '—'} / {r.section || '—'}</td><td>{r.class_mode || '—'}</td><td>{time12(r.scheduled_start)} – {time12(r.scheduled_end)}</td><td>{time12(r.time_in)}</td><td>{time12(r.time_out)}</td><td>{r.late_minutes ?? '—'}</td><td>{r.early_departure_minutes ?? '—'}</td><td><div className="session-status-list">{sessionIndicators(r).map((indicator) => <span className={`session-status is-${indicator.key}`} key={indicator.key}>{indicator.label}</span>)}</div>{r.exclusion && <small>{r.exclusion}</small>}</td></tr>) : <tr><td colSpan={10}>No class sessions match this view.</td></tr>}</tbody></table></div>
            {details.pages > 1 && <nav className="session-pagination" aria-label="Session details pages"><button disabled={details.current === 1} onClick={() => setPage(details.current - 1)}>Previous</button>{pageNumbers.map((number) => <button key={number} aria-current={number === details.current ? 'page' : undefined} onClick={() => setPage(number)}>{number}</button>)}<button disabled={details.current === details.pages} onClick={() => setPage(details.current + 1)}>Next</button><span>Page {details.current} of {details.pages}</span></nav>}
        </section>
    </AppLayout>;
}
