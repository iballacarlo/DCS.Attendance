import { router } from '@inertiajs/react';
import { FileSpreadsheet, Printer } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Form48 from '../../components/Form48';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import { formatDate, fullName, time12 } from '../../lib/format';
import { documentFilename, openPdfPrintWindow, sendPdfToPrint } from '../../lib/documents';

const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
const displayWorkedHours = (record, hours) => record.time_in || record.time_out ? Number(hours).toFixed(2) : '';

export default function DtrShow({ dtr, employees, canSelectFaculty }) {
    const [action, setAction] = useState(null);
    const { ask, dialog } = useConfirmDialog();
    const { employee, cutoff } = dtr;
    const [year, month, day] = cutoff.start_date.split('-');
    const [yearDraft, setYearDraft] = useState(year);
    const [yearError, setYearError] = useState('');
    useEffect(() => { setYearDraft(year); setYearError(''); }, [cutoff.start_date]);
    const sessions = dtr.rows.flatMap((row) => row.sessions.map((session) => ({ ...session, date: row.date })));
    const reload = (values) => router.get('/attendance/dtr', { employee_id: employee.id, start_date: cutoff.start_date, ...values }, { preserveState: true, preserveScroll: true });
    const changeDate = (nextYear = yearDraft, nextMonth = month, nextDay = day) => {
        if (!/^\d{4}$/.test(nextYear) || Number(nextYear) < 1900 || Number(nextYear) > 2100) {
            setYearError('Enter a year from 1900 to 2100.'); return;
        }
        reload({ start_date: `${nextYear}-${nextMonth}-${nextDay}` });
    };
    const applyYear = () => {
        if (!/^\d{4}$/.test(yearDraft) || Number(yearDraft) < 1900 || Number(yearDraft) > 2100) {
            setYearError('Enter a year from 1900 to 2100.'); return;
        }
        setYearError('');
        if (yearDraft !== year) changeDate(yearDraft);
    };
    const exportDocument = async (kind, operation) => {
        if (action) return;
        const popup = operation === 'print' ? openPdfPrintWindow() : null;
        if (operation === 'print' && !popup) {
            await ask({ title: 'Print window blocked', message: 'Allow pop-ups for this site, then select Print again.', confirmLabel: 'Got It', cancelLabel: null }); return;
        }
        setAction(`${kind}-${operation}`);
        try {
            const filename = documentFilename(fullName(employee), kind === 'breakdown' ? 'CLASS_ATTENDANCE' : 'DTR_FORM_48', cutoff.start_date, cutoff.end_date, operation === 'excel' ? 'xlsx' : 'pdf');
            if (operation === 'excel') {
                const { downloadForm48Excel } = await import('../../lib/form48Excel');
                await downloadForm48Excel(dtr, filename);
            } else {
                const { jsPDF } = await import('jspdf');
                const pdf = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
                if (kind === 'breakdown') {
                    const { appendDtrBreakdown } = await import('../../lib/dtrBreakdownPdf');
                    appendDtrBreakdown(pdf, dtr, { standalone: true });
                } else {
                    const { drawForm48 } = await import('../../lib/form48Pdf');
                    drawForm48(pdf, dtr);
                }
                sendPdfToPrint(pdf, popup);
            }
        } catch {
            popup?.close();
            await ask({ title: 'Could not prepare document', message: 'The document could not be prepared. Please try again.', confirmLabel: 'Close', cancelLabel: null, tone: 'danger' });
        } finally { setAction(null); }
    };
    const exportButton = (kind, operation, label, Icon) => <button className="ui-control ui-action ui-primary" type="button" disabled={!!action} onClick={() => exportDocument(kind, operation)}><Icon size={16} />{action === `${kind}-${operation}` ? 'Preparing...' : label}</button>;

    return <AppLayout title="Daily Time Record" subtitle="Civil Service Form No. 48 and separate class attendance reports.">
        <div className="dtr-export-toolbar dtr-form-controls print-hidden">
            {canSelectFaculty && <label className="dtr-faculty-select">Faculty<select disabled={!!action} value={employee.id} onChange={(e) => reload({ employee_id: e.target.value })}>{employees.map((item) => <option value={item.id} key={item.id}>{fullName(item)} · {item.employee_no}</option>)}</select></label>}
            <label>Month<select disabled={!!action} value={month} onChange={(e) => changeDate(undefined, e.target.value)}>{months.map((name, i) => <option value={String(i + 1).padStart(2, '0')} key={name}>{name}</option>)}</select></label>
            <label className="dtr-year-control">Year<input aria-label="DTR year" type="number" min="1900" max="2100" step="1" disabled={!!action} value={yearDraft} onChange={(e) => setYearDraft(e.target.value)} onBlur={applyYear} onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); e.currentTarget.blur(); } }} aria-invalid={!!yearError} aria-describedby={yearError ? 'dtr-year-error' : undefined} /></label>
            <label>Cutoff<select disabled={!!action} value={day} onChange={(e) => changeDate(undefined, month, e.target.value)}><option value="01">1–15</option><option value="16">16–month end</option></select></label>
            <div className="dtr-form-export-actions">{exportButton('form', 'print', 'Print DTR', Printer)}{exportButton('form', 'excel', 'Download Excel', FileSpreadsheet)}</div>
            {yearError && <p className="dtr-year-error" id="dtr-year-error" role="alert">{yearError}</p>}
            <p className="dtr-excel-note">Excel cells can be edited manually after download. Changes to that file do not update attendance or payroll in the system.</p>
        </div>
        <div className="dtr-sheet-viewport"><Form48 dtr={dtr} /></div>
        <section className="panel class-session-report print-hidden">
            <div className="panel-heading"><div><h2>Class Attendance Breakdown</h2><p>Total worked hours: {Number(dtr.summary.total_hours).toFixed(2)}. Printed separately from Form 48.</p></div><div className="ui-heading-actions">{exportButton('breakdown', 'print', 'Print Breakdown', Printer)}</div></div>
            <div className="table-wrap"><table><thead><tr><th>Date</th><th>Subject</th><th>Section</th><th>Schedule</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th></tr></thead><tbody>{sessions.length ? sessions.map((s) => <tr key={s.id}><td>{formatDate(s.date)}</td><td>{s.schedule_type === 'consultation' ? 'Consultation' : s.subject || 'Not specified'}{s.class_mode && <small>{s.class_mode === 'lecture' ? 'Lecture' : 'Laboratory'}</small>}{s.reconstructed && <small>Reconstructed from legacy daily attendance</small>}</td><td>{s.schedule_type === 'consultation' ? '—' : s.section || 'Not specified'}</td><td>{time12(s.scheduled_start)}–{time12(s.scheduled_end)}</td><td>{time12(s.time_in)}</td><td>{time12(s.time_out)}</td><td>{displayWorkedHours(s, s.hours)}</td><td>{s.status}</td></tr>) : <tr><td colSpan={8} className="empty-row">No class attendance recorded for this cutoff.</td></tr>}</tbody></table></div>
        </section>
        {dialog}
    </AppLayout>;
}
