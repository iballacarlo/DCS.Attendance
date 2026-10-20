import { router } from '@inertiajs/react';
import { Download, Printer } from 'lucide-react';
import { useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { fullName } from '../../lib/format';

const displayTime = (value) => value ? new Date(`2000-01-01T${value}:00`).toLocaleTimeString('en-PH', { hour: 'numeric', minute: '2-digit' }) : '';
const dateText = (value) => new Date(`${value}T00:00:00`).toLocaleDateString('en-PH', { month: 'long', day: 'numeric', year: 'numeric' });
const filePart = (value) => String(value || '').replace(/[^a-zA-Z0-9-]+/g, '-').replace(/^-|-$/g, '');
const cutoffStart = (value) => {
    const [year, month, day] = String(value).split('-').map(Number);
    return `${year}-${String(month).padStart(2, '0')}-${day <= 15 ? '01' : '16'}`;
};

export default function DtrShow({ dtr, employees, canSelectFaculty }) {
    const formRef = useRef(null);
    const [pdfAction, setPdfAction] = useState(null);
    const employee = dtr.employee;
    const cutoff = dtr.cutoff;
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
        return pdf;
    };
    const download = async () => {
        setPdfAction('download');
        try { const pdf = await createPdf(); pdf.save(`dtr-${filePart(employee.employee_no)}-${cutoff.start_date}-to-${cutoff.end_date}.pdf`); } finally { setPdfAction(null); }
    };
    const print = async () => {
        const popup = window.open('', '_blank');
        if (!popup) return;
        setPdfAction('print');
        try { const pdf = await createPdf(); pdf.autoPrint(); popup.location.href = pdf.output('bloburl'); } catch (error) { popup.close(); throw error; } finally { setPdfAction(null); }
    };

    return <AppLayout title="Daily Time Record" subtitle="Civil Service Form No. 48 generated from verified attendance and paid calendar events.">
        <div className="dtr-export-toolbar print-hidden">
            {canSelectFaculty && <label>Faculty<select value={employee.id} onChange={(e) => reload({ employee_id: e.target.value })}>{employees.map((item) => <option value={item.id} key={item.id}>{fullName(item)} · {item.employee_no}</option>)}</select></label>}
            <label>Cutoff date<input type="date" value={cutoff.start_date} onChange={(e) => reload({ start_date: cutoffStart(e.target.value) })} /></label>
            <span className="dtr-cutoff-chip">{dateText(cutoff.start_date)} – {dateText(cutoff.end_date)}</span>
            <button type="button" onClick={print} disabled={Boolean(pdfAction)}><Printer size={17} />{pdfAction === 'print' ? 'Preparing...' : 'Print'}</button>
            <button type="button" onClick={download} disabled={Boolean(pdfAction)}><Download size={17} />{pdfAction === 'download' ? 'Preparing...' : 'Download PDF'}</button>
        </div>
        <article className="official-dtr" ref={formRef}>
            <header><small>Civil Service Form No. 48</small><div className="official-dtr-brand"><img src="/images/cvsu-logo.png" alt="" /><div><b>CAVITE STATE UNIVERSITY</b><span>Imus Campus</span></div></div><h2>DAILY TIME RECORD</h2><strong>{fullName(employee).toUpperCase()}</strong><span>(NAME)</span></header>
            <section className="official-dtr-meta"><div>For the period of <strong>{dateText(cutoff.start_date)} – {dateText(cutoff.end_date)}</strong></div><div>Official hours for arrival and departure <strong>Based on faculty loading</strong></div></section>
            <table><thead><tr><th className="margin-column"></th><th rowSpan="2">Day</th><th colSpan="2">AM</th><th colSpan="2">PM</th><th colSpan="2">LATE / UNDER TIME</th><th className="margin-column"></th></tr><tr><th className="margin-column">Schedule</th><th>Arrival</th><th>Departure</th><th>Arrival</th><th>Departure</th><th>Hours</th><th>Minutes</th><th className="margin-column">Hours</th></tr></thead>
                <tbody>{dtr.rows.map((row) => <tr key={row.date} className={row.event ? 'is-paid-event' : ''}><td className="margin-column schedule-margin">{row.schedule_label || ''}</td><td>{row.day}</td>{row.event ? <td colSpan="4" className="event-cell">{row.event.label}<small>{row.event.title}</small></td> : <><td>{displayTime(row.am_arrival)}</td><td>{displayTime(row.am_departure)}</td><td>{displayTime(row.pm_arrival)}</td><td>{displayTime(row.pm_departure)}</td></>}<td>{row.variance_hours || ''}</td><td>{row.variance_minutes || ''}</td><td className="margin-column hours-margin">{row.margin_hours === null ? '' : Number(row.margin_hours).toFixed(2)}</td></tr>)}</tbody>
            </table>
            <section className="official-dtr-certification"><p>I CERTIFY on my honor that the above is a true and correct report of the hours of work performed, record of which was made daily at the time of arrival at and departure from office.</p><div className="signature-line"><span>Faculty Signature</span></div><p>Verified as to the prescribed office hours:</p><div className="signature-line"><strong>{dtr.signatory?.name?.toUpperCase() || 'NO SIGNATORY CONFIGURED'}</strong><span>{dtr.signatory?.designation || 'Dean / In Charge'}</span></div></section>
        </article>
    </AppLayout>;
}
