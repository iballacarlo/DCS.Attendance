import { formatDate } from '../../lib/format';
import { appendDtrBreakdown } from '../../lib/dtrBreakdownPdf';
import { router } from '@inertiajs/react';
import { Download, Printer } from 'lucide-react';
import { useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import { time12, fullName } from '../../lib/format';
import { documentFilename, openPdfPrintWindow, sendPdfToPrint } from '../../lib/documents';

const displayTime = (value) => value ? new Date(`2000-01-01T${value}:00`).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }) : '';
const dateText = formatDate;
const displayWorkedHours = (record, hours) => record.time_in || record.time_out ? Number(hours).toFixed(2) : '';

export default function DtrShow({ dtr, cutoffOptions, employees, canSelectFaculty }) {
    const formRef = useRef(null);
    const [pdfAction, setPdfAction] = useState(null);
    const { ask, dialog } = useConfirmDialog();
    const employee = dtr.employee;
    const cutoff = dtr.cutoff;
    const sessions = dtr.rows.flatMap((row) => row.sessions.map((session) => ({ ...session, date: row.date })));
    const reload = (values) => router.get('/attendance/dtr', { employee_id: employee.id, start_date: cutoff.start_date, ...values }, { preserveState: true });
    const createPdf = async () => {
        const [{ default: html2canvas }, { jsPDF }] = await Promise.all([import('html2canvas'), import('jspdf')]);
        const canvas = await html2canvas(formRef.current, { backgroundColor: '#ffffff', scale: 2.5, logging: false, useCORS: true, windowWidth: 1000 });
        const pdf = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
        const maxWidth = 190;
        const maxHeight = 277;
        let width = maxWidth;
        let height = width * canvas.height / canvas.width;
        if (height > maxHeight) { height = maxHeight; width = height * canvas.width / canvas.height; }
        pdf.addImage(canvas.toDataURL('image/png'), 'PNG', (210 - width) / 2, 10, width, height, undefined, 'FAST');
        appendDtrBreakdown(pdf, dtr);
        return pdf;
    };
    const download = async () => {
        setPdfAction('download');
        try { const pdf = await createPdf(); pdf.save(documentFilename(fullName(employee), 'DTR', cutoff.start_date, cutoff.end_date)); } finally { setPdfAction(null); }
    };
    const print = async () => {
        const popup = openPdfPrintWindow();
        if (!popup) {
            await ask({ title: 'Print window blocked', message: 'Allow pop-ups for this site, then select Print again.', confirmLabel: 'Got It', cancelLabel: null });
            return;
        }
        setPdfAction('print');
        try {
            const pdf = await createPdf();
            sendPdfToPrint(pdf, popup);
        } catch (error) {
            popup.close();
            await ask({ title: 'Could not print DTR', message: 'The print document could not be prepared. Please try again.', confirmLabel: 'Close', cancelLabel: null, tone: 'danger' });
        } finally { setPdfAction(null); }
    };

    return <AppLayout title="Daily Time Record" subtitle="Daily totals and class sessions computed from actual tap pairs.">
        <div className="dtr-export-toolbar print-hidden">
            {canSelectFaculty && <label>Faculty<select value={employee.id} onChange={(e) => reload({ employee_id: e.target.value })}>{employees.map((item) => <option value={item.id} key={item.id}>{fullName(item)} · {item.employee_no}</option>)}</select></label>}
            <label>Cutoff period<select value={cutoff.start_date} onChange={(e) => reload({ start_date: e.target.value })}>{cutoffOptions.map((option) => <option value={option.value} key={option.value}>{option.label}</option>)}</select></label>
            <button className="ui-control ui-action ui-secondary" type="button" onClick={print} disabled={Boolean(pdfAction)}><Printer size={17} />{pdfAction === 'print' ? 'Preparing...' : 'Print'}</button>
            <button className="ui-control ui-action ui-secondary" type="button" onClick={download} disabled={Boolean(pdfAction)}><Download size={17} />{pdfAction === 'download' ? 'Preparing...' : 'Download PDF'}</button>
        </div>
        <div className="dtr-sheet-viewport"><article className="official-dtr" ref={formRef}>
            <header><small>Civil Service Form No. 48</small><div className="official-dtr-brand"><img src="/images/cvsu-logo.png" alt="" /><div><b>CAVITE STATE UNIVERSITY</b><span>Imus Campus</span></div></div><h2>DAILY TIME RECORD</h2><strong>{fullName(employee).toUpperCase()}</strong><span>(NAME)</span></header>
            <section className="official-dtr-meta"><div>For the period of <strong className="dtr-period">{dateText(cutoff.start_date)} – {dateText(cutoff.end_date)}</strong></div><div>Official hours for arrival and departure</div></section>
            <table><thead><tr><th className="margin-column" rowSpan="2">Schedule</th><th rowSpan="2">Day</th><th colSpan="2">AM</th><th colSpan="2">PM</th><th colSpan="2">LATE / UNDER TIME</th><th className="margin-column" rowSpan="2">Hours</th></tr><tr><th>Arrival</th><th>Departure</th><th>Arrival</th><th>Departure</th><th>Hours</th><th>Minutes</th></tr></thead>
                <tbody>{dtr.rows.map((row) => <tr key={row.date}><td className="margin-column schedule-margin">{row.schedule_label || ''}</td><td>{row.day}</td><td>{displayTime(row.am_arrival)}</td><td>{displayTime(row.am_departure)}</td><td>{displayTime(row.pm_arrival)}</td><td>{displayTime(row.pm_departure)}</td><td></td><td></td><td className="margin-column hours-margin">{displayWorkedHours(row, row.payable_hours)}</td></tr>)}</tbody>
            </table>
            <p className="dtr-cutoff-total">Total worked hours: <strong>{Number(dtr.summary.total_hours).toFixed(2)}</strong></p>
            <section className="official-dtr-certification">
                <p className="certification-statement">I CERTIFY on my honor that the above is a true and correct report of the hours of work performed, record of which was made daily at the time of arrival at and departure from office.</p>
                <div className="faculty-signature-line" aria-hidden="true"></div>
                <p className="verification-statement">Verified as to the prescribed office hours:</p>
                <div className="signatory-line">
                    <strong>{dtr.signatory?.name || 'NO SIGNATORY CONFIGURED'}</strong>
                    <span>In Charge</span>
                </div>
            </section>
        </article></div>
        <section className="panel class-session-report"><h2>Class Attendance Breakdown</h2><p>Total worked hours: {Number(dtr.summary.total_hours).toFixed(2)}. Daily arrival/departure above are summaries; each class pair is listed below.</p><div className="table-wrap"><table><thead><tr><th>Date</th><th>Subject</th><th>Section</th><th>Schedule</th><th>Time In</th><th>Time Out</th><th>Hours</th><th>Status</th></tr></thead><tbody>{sessions.map((s) => <tr key={s.id}><td>{formatDate(s.date)}</td><td>{s.subject || 'Not specified'}{s.class_mode && <small>{s.class_mode === 'lecture' ? 'Lecture' : 'Laboratory'}</small>}{s.reconstructed && <small>Reconstructed from legacy daily attendance</small>}</td><td>{s.section || 'Not specified'}</td><td>{time12(s.scheduled_start)}–{time12(s.scheduled_end)}</td><td>{time12(s.time_in)}</td><td>{time12(s.time_out)}</td><td>{displayWorkedHours(s, s.hours)}</td><td>{s.status}</td></tr>)}</tbody></table></div></section>
        {dialog}
    </AppLayout>;
}
