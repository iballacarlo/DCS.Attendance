import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import FacultyCombobox from '../../components/FacultyCombobox';
import SemesterPicker from '../../components/SemesterPicker';
import CutoffPicker from '../../components/CutoffPicker';
import ReportAnalytics from '../../components/ReportAnalytics';
import ReportPopover from '../../components/ReportPopover';
import { ChevronDown } from 'lucide-react';
import useDebouncedFilters from '../../hooks/useDebouncedFilters';
import useRealtimeReload from '../../hooks/useRealtimeReload';
import { classReportView, sessionIndicators, sessionPage, sessionStatusLabels } from '../../lib/classAnalytics';
import { fullName, time12, formatDate } from '../../lib/format';
import { openPdfPrintWindow, sendPdfToPrint } from '../../lib/documents';
import { currentCutoff, constrainCutoff } from '../../lib/cutoff';
import '../../../css/classReports.css';

const normalize = (f) => Object.fromEntries(['term_id', 'cutoff_start', 'employee_id', 'subject', 'section', 'class_mode'].map((key) => [key, String(f[key] || '')]));
const labels = sessionStatusLabels;

export default function Classes({ report, employees, subjects, sections }) {
    const { semesterOptions = [] } = usePage().props;
    const initialFilters = useRef(normalize({ cutoff_start: currentCutoff().start }));
    const applied = JSON.stringify(normalize(report.filters));
    const [filters, setFilters] = useState(() => normalize(report.filters));
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [page, setPage] = useState(1);
    const [perPage, setPerPage] = useState(10);
    const [status, setStatus] = useState('');
    const [detailsOpen, setDetailsOpen] = useState(false);
    const hasFilterChanges = JSON.stringify(normalize(filters)) !== JSON.stringify(initialFilters.current);
    const pending = JSON.stringify(filters) !== applied;
    useDebouncedFilters('/class-reports', filters, { enabled: !busy, delay: 450 });
    const refreshing = useRealtimeReload(['report', 'subjects', 'sections'], 15000, !pending && !busy);
    useEffect(() => { setFilters(JSON.parse(applied)); setPage(1); setStatus(''); setDetailsOpen(false); }, [applied]);
    const update = (field, value) => setFilters((old) => ({ ...old, [field]: String(value || ''),
        ...(['employee_id', 'cutoff_start'].includes(field) ? { subject: '', section: '' } : field === 'subject' ? { section: '' } : {}),
    }));
    const openReview = () => { setStatus('incomplete'); setPage(1); setDetailsOpen(true); };
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
        <div className="class-report-page">
        <section className="panel print-hidden class-report-toolbar">
            <div className="list-filters class-report-filters">
                <div className="report-period-filters">
                <SemesterPicker compact value={filters.term_id} onChange={(term_id) => setFilters((old) => ({ ...old, term_id, cutoff_start: constrainCutoff(old.cutoff_start, semesterOptions.find((term) => String(term.id) === String(term_id))).start, subject: '', section: '' }))} />
                <CutoffPicker termId={filters.term_id} value={filters.cutoff_start} onChange={(period) => update('cutoff_start', period.start)} />
                </div>
                <div className="report-class-filters">
                <FacultyCombobox employees={employees} selectedId={filters.employee_id} allowClear onSelect={(id) => update('employee_id', id)} />
                {[['subject', 'Subject', subjects], ['section', 'Section', sections], ['class_mode', 'Class mode', ['lecture', 'laboratory']]].map(([field, label, options]) => <label key={field}>{label}<select value={filters[field]} disabled={field !== 'class_mode' && (String(report.filters.term_id || '') !== filters.term_id || String(report.filters.employee_id || '') !== filters.employee_id || report.filters.cutoff_start !== filters.cutoff_start || (field === 'section' && String(report.filters.subject || '') !== filters.subject))} onChange={(e) => update(field, e.target.value)}><option value="">All</option>{options.map((o) => <option key={o} value={o}>{o}</option>)}</select></label>)}
                <button type="button" className="ui-control ui-action ui-secondary report-action-reset" disabled={busy || !hasFilterChanges} onClick={() => setFilters({ ...initialFilters.current })}>Reset filters</button>
                </div>
            </div>
            <div className="report-update-bar">
                <div className="report-context"><strong>{formatDate(report.filters.start_date)} - {formatDate(report.filters.end_date)}</strong><span>{[['Faculty', employees.find((e) => String(e.id) === String(report.filters.employee_id)) && fullName(employees.find((e) => String(e.id) === String(report.filters.employee_id)))], ['Subject', report.filters.subject], ['Section', report.filters.section], ['Mode', report.filters.class_mode]].filter(([, v]) => v).map(([k, v]) => `${k}: ${v}`).join(' | ') || 'All faculty, subjects, sections, and modes'}</span>{pending && <small role="status">Updating filters...</small>}</div>
                <div className="report-export-control">{status && <small>Export: {labels[status]}</small>}<ReportPopover label={busy ? 'Preparing report...' : 'Export report'} className="report-export-menu">
                    {[['print', 'Print'], ['pdf', 'Download PDF'], ['excel', 'Download Excel']].map(([kind, label]) => <button type="button" key={kind} data-close-popover className="ui-control ui-action ui-secondary" disabled={busy || pending} onClick={() => download(kind)}>{label}</button>)}
                </ReportPopover></div>
            </div>
            {error && <p role="alert" className="field-error">{error}</p>}
        </section>
        <div aria-busy={pending || refreshing}><ReportAnalytics key={applied} report={report} onReview={openReview} /></div>
        <section className="panel session-details report-session-details" id="session-details">
            <button type="button" className="report-details-toggle" aria-expanded={detailsOpen} aria-controls="report-session-body" onClick={() => setDetailsOpen((open) => !open)}><span><strong>Session details</strong><small>{details.total} matching sessions{status ? ` | ${labels[status]}` : ''}</small></span><ChevronDown size={20} aria-hidden="true" /></button>
            <div id="report-session-body" hidden={!detailsOpen}>
            <div className="panel-heading"><div><p>{details.from}–{details.to} of {details.total} matching sessions</p></div><div className="session-detail-controls">
                <label>Attendance status<select value={status} onChange={(e) => { setStatus(e.target.value); setPage(1); }}><option value="">All statuses</option>{Object.entries(labels).filter(([value]) => ['on_time', 'late', 'undertime', 'late_undertime', 'missed', 'incomplete'].includes(value)).map(([value, label]) => <option key={value} value={value}>{value === 'on_time' ? 'On time' : label}</option>)}</select></label>
                <label>Rows per page<select value={perPage} onChange={(e) => { setPerPage(Number(e.target.value)); setPage(1); }}>{[10, 25, 50].map((n) => <option key={n}>{n}</option>)}</select></label>
                {status && <button type="button" className="ui-control ui-action ui-secondary" onClick={() => { setStatus(''); setPage(1); }}>Clear status</button>}
            </div></div>
            <div className="table-wrap"><table><thead><tr><th>Date</th><th>Faculty</th><th>Subject / Section</th><th>Mode</th><th>Schedule</th><th>IN</th><th>OUT</th><th>Late (min)</th><th>Undertime (min)</th><th>Status</th></tr></thead><tbody>{details.rows.length ? details.rows.map((r) => <tr key={r.key}><td>{formatDate(r.date)}</td><td>{r.faculty}</td><td>{r.subject || '—'} / {r.section || '—'}</td><td>{r.class_mode || '—'}</td><td>{time12(r.scheduled_start)} – {time12(r.scheduled_end)}</td><td>{time12(r.time_in)}</td><td>{time12(r.time_out)}</td><td>{r.late_minutes ?? '—'}</td><td>{r.early_departure_minutes ?? '—'}</td><td><div className="session-status-list">{sessionIndicators(r).map((indicator) => <span className={`session-status is-${indicator.key}`} key={indicator.key}>{indicator.label}</span>)}</div>{r.exclusion && <small>{r.exclusion}</small>}</td></tr>) : <tr><td colSpan={10}>No class sessions match this view.</td></tr>}</tbody></table></div>
            {details.pages > 1 && <nav className="session-pagination" aria-label="Session details pages"><button disabled={details.current === 1} onClick={() => setPage(details.current - 1)}>Previous</button>{pageNumbers.map((number) => <button key={number} aria-current={number === details.current ? 'page' : undefined} onClick={() => setPage(number)}>{number}</button>)}<button disabled={details.current === details.pages} onClick={() => setPage(details.current + 1)}>Next</button><span>Page {details.current} of {details.pages}</span></nav>}
            </div>
        </section>
        </div>
    </AppLayout>;
}
