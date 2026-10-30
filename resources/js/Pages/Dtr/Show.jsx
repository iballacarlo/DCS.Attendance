import SemesterPicker from '../../components/SemesterPicker';
import CutoffPicker from '../../components/CutoffPicker';
import { router } from '@inertiajs/react';
import { FileSpreadsheet, Printer } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Form48 from '../../components/Form48';
import FacultyCombobox from '../../components/FacultyCombobox';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import { formatDate, fullName, time12 } from '../../lib/format';
import { documentFilename, openPdfPrintWindow, sendPdfToPrint } from '../../lib/documents';

export default function DtrShow({ dtr, employees, canSelectFaculty, termSelection = '' }) {
    const [action, setAction] = useState(null);
    const { ask, dialog } = useConfirmDialog();
    const { employee, cutoff } = dtr;
    const reload = (values) => router.get('/attendance/dtr', { term_id: termSelection, employee_id: employee.id, start_date: cutoff.start_date, ...values }, { preserveState: true, preserveScroll: true });
    const exportDocument = async (kind, operation) => {
        if (action) return;
        const popup = operation === 'print' ? openPdfPrintWindow() : null;
        if (operation === 'print' && !popup) {
            await ask({ title: 'Print window blocked', message: 'Allow pop-ups for this site, then select Print again.', confirmLabel: 'Got It', cancelLabel: null }); return;
        }
        setAction(`${kind}-${operation}`);
        try {
            if (operation === 'excel') {
                const filename = documentFilename(fullName(employee), 'DTR_FORM_48', cutoff.start_date, cutoff.end_date, 'xlsx');
                const { downloadForm48Excel } = await import('../../lib/form48Excel');
                await downloadForm48Excel(dtr, filename);
            } else {
                const { jsPDF } = await import('jspdf');
                const pdf = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
                const { drawForm48 } = await import('../../lib/form48Pdf');
                drawForm48(pdf, dtr);

                sendPdfToPrint(pdf, popup);
            }
        } catch {
            popup?.close();
            await ask({ title: 'Could not prepare document', message: 'The document could not be prepared. Please try again.', confirmLabel: 'Close', cancelLabel: null, tone: 'danger' });
        } finally { setAction(null); }
    };
    const exportButton = (kind, operation, label, Icon) => <button className="ui-control ui-action ui-primary" type="button" disabled={!!action} onClick={() => exportDocument(kind, operation)}><Icon size={16} />{action === `${kind}-${operation}` ? 'Preparing...' : label}</button>;

    return <AppLayout title="Daily Time Record">
        <div className="dtr-export-toolbar dtr-form-controls print-hidden">
            {canSelectFaculty && <FacultyCombobox employees={employees} selectedId={employee.id} disabled={!!action} onSelect={(id) => reload({ employee_id: id })} />}
            <SemesterPicker compact value={termSelection} disabled={!!action} />
            <CutoffPicker termId={termSelection} value={cutoff.start_date} disabled={!!action} onChange={(period) => reload({ start_date: period.start })} />
            <div className="dtr-form-export-actions">{exportButton('form', 'print', 'Print DTR', Printer)}{exportButton('form', 'excel', 'Download Excel', FileSpreadsheet)}</div>
        </div>
        {dtr.term_labels?.length > 0 && <p className="print-hidden">{dtr.term_labels.join(' / ')}</p>}
        <div className="dtr-sheet-viewport"><Form48 dtr={dtr} /></div>
        <section className="panel print-hidden">
            <div className="panel-heading"><div><h2>School attendance summary</h2><p>{Number(dtr.summary.total_hours).toFixed(2)} scheduled hours covered · {dtr.summary.days_present} day(s) with school IN · {dtr.summary.incomplete_days} incomplete day(s)</p></div></div>
            <div className="table-wrap"><table><thead><tr><th>Date</th><th>School IN</th><th>School OUT</th><th>Scheduled hours</th><th>Hours covered</th><th>Undertime (min)</th><th>Status</th><th>Source</th></tr></thead><tbody>{dtr.rows.filter((r) => r.has_schedule || r.time_in || r.time_out).map((r) => <tr key={r.date}><td>{formatDate(r.date)}</td><td>{time12(r.time_in)}</td><td>{time12(r.time_out)}</td><td>{Number(r.scheduled_hours).toFixed(2)}</td><td>{r.worked_hours == null ? '—' : Number(r.worked_hours).toFixed(2)}</td><td>{r.shortfall_minutes ?? '—'}</td><td>{r.status.replaceAll('_', ' ')}{r.event && <small>{r.event.title}</small>}</td><td>{r.source === 'simulator' ? 'RFID' : '—'}</td></tr>)}</tbody></table></div>
        </section>
        {dialog}
    </AppLayout>;
}
