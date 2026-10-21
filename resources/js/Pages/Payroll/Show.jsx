import { useForm, usePage } from '@inertiajs/react';
import { Download, FilePenLine, Printer, Save } from 'lucide-react';
import { useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import useRealtimeReload from '../../hooks/useRealtimeReload';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import { documentFilename, openPdfPrintWindow, sendPdfToPrint } from '../../lib/documents';
import { formatDate, fullName } from '../../lib/format';

const earningFields = [
    ['overtime_pay', 'Overtime Pay'],
    ['other_earnings', 'Others'],
    ['increase_amount', 'Increase'],
];

const deductionFields = [
    ['withholding_tax', 'Withholding Tax'],
    ['gsis_deduction', 'GSIS'],
    ['philhealth_deduction', 'PhilHealth'],
    ['pag_ibig_deduction', 'Pag-IBIG'],
    ['multi_purpose_loan', 'Multi-Purpose Loan'],
    ['gsis_loan', 'GSIS Loan'],
    ['gsis_eplus_loan', 'GSIS ePlus Loan'],
    ['fea_dues', 'FEA Dues'],
    ['oba_deduction', 'OBA'],
    ['cra_deduction', 'CRA'],
];

const dateLabel = formatDate;
const cutoffLabel = (start, end) => `${formatDate(start)} - ${formatDate(end)}`;

function amount(value, zeroAsDash = true) {
    const number = Number(value || 0);
    if (zeroAsDash && number === 0) return '-';
    return number.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function PayslipRow({ label, value, emphasis = false, subrow = false }) {
    return <div className={`official-pay-row${emphasis ? ' is-total' : ''}${subrow ? ' is-subrow' : ''}`}>
        <span>{label}</span><strong>{value}</strong>
    </div>;
}

export default function PayrollShow({ record }) {
    const { auth } = usePage().props;
    const period = record.payroll_period;
    const employee = record.employee;
    const canManage = ['admin', 'payroll_staff'].includes(auth.user.role);
    const canEdit = canManage && record.status === 'draft';
    const { ask, dialog } = useConfirmDialog();
    const [editing, setEditing] = useState(false);
    const [pdfAction, setPdfAction] = useState(null);
    const payslipRef = useRef(null);
    useRealtimeReload(['record'], 10000, !editing && !pdfAction);
    const cutoff = cutoffLabel(period?.start_date, period?.end_date);
    const employeeName = fullName(employee).toUpperCase();
    const totalEarnings = Number(record.total_earnings || record.gross_pay || 0);
    const form = useForm(Object.fromEntries(
        [...earningFields, ...deductionFields].map(([field]) => [field, Number(record[field] || 0).toFixed(2)]),
    ));

    const submit = (event) => {
        event.preventDefault();
        form.put(`/payroll/records/${record.id}`, {
            preserveScroll: true,
            onSuccess: () => setEditing(false),
        });
    };

    const createPayslipPdf = async () => {
        const [{ default: html2canvas }, { jsPDF }] = await Promise.all([
            import('html2canvas'),
            import('jspdf'),
        ]);
        const canvas = await html2canvas(payslipRef.current, {
            backgroundColor: '#ffffff',
            logging: false,
            scale: 2,
            useCORS: true,
            windowWidth: 1440,
        });
        const pdf = new jsPDF({ unit: 'mm', format: 'a4', orientation: 'portrait' });
        const maximumWidth = 95;
        const maximumHeight = 138.5;
        let renderWidth = maximumWidth;
        let renderHeight = renderWidth * (canvas.height / canvas.width);
        if (renderHeight > maximumHeight) {
            renderHeight = maximumHeight;
            renderWidth = renderHeight * (canvas.width / canvas.height);
        }
        const x = 10 + ((maximumWidth - renderWidth) / 2);
        pdf.addImage(canvas.toDataURL('image/png'), 'PNG', x, 10, renderWidth, renderHeight, undefined, 'FAST');
        return pdf;
    };

    const printPayslip = async () => {
        const printWindow = openPdfPrintWindow();
        if (!printWindow) {
            await ask({ title: 'Print window blocked', message: 'Allow pop-ups for this site, then select Print again.', confirmLabel: 'Got It', cancelLabel: null });
            return;
        }
        setPdfAction('print');
        try {
            const pdf = await createPayslipPdf();
            sendPdfToPrint(pdf, printWindow);
        } catch (error) {
            printWindow.close();
            await ask({ title: 'Could not print payslip', message: 'The print document could not be prepared. Please try again.', confirmLabel: 'Close', cancelLabel: null, tone: 'danger' });
        } finally {
            setPdfAction(null);
        }
    };

    const download = async () => {
        setPdfAction('download');
        try {
            const pdf = await createPayslipPdf();
            pdf.save(documentFilename(fullName(employee), 'PAYSLIP', period?.start_date, period?.end_date));
        } finally {
            setPdfAction(null);
        }
    };

    return <AppLayout title="Payslip" subtitle={`${period?.period_name || 'Payroll statement'} | Pay date: ${dateLabel(period?.pay_date)}`}>
        <div className="payslip-toolbar print-hidden">
            <span data-status={record.status} className={`badge payroll-status is-${record.status}`}>{record.status}</span>
            {canEdit && <button type="button" className="payslip-edit-button ui-control ui-action ui-secondary" onClick={() => setEditing((value) => !value)}><FilePenLine size={17} />{editing ? 'Close editor' : 'Edit details'}</button>}
            <button className="ui-control ui-action ui-secondary" type="button" onClick={printPayslip} disabled={Boolean(pdfAction)}><Printer size={17} />{pdfAction === 'print' ? 'Preparing...' : 'Print'}</button>
            <button className="ui-control ui-action ui-secondary" type="button" onClick={download} disabled={Boolean(pdfAction)}><Download size={17} />{pdfAction === 'download' ? 'Preparing...' : 'Download PDF'}</button>
        </div>

        {canEdit && editing && <form className="payroll-adjustment-editor print-hidden" onSubmit={submit}>
            <div className="payroll-editor-heading"><div><span>Payroll details</span><h2>Earnings and deductions</h2></div><p>Hours and regular pay come from attendance. Enter only applicable additions and deductions.</p></div>
            <fieldset><legend>Additional earnings</legend><div className="payroll-editor-grid">{earningFields.map(([field, label]) => <label key={field}>{label}<div className="currency-input"><span>PHP</span><input type="number" min="0" max="99999999.99" step="0.01" value={form.data[field]} onChange={(event) => form.setData(field, event.target.value)} required /></div></label>)}</div></fieldset>
            <fieldset><legend>Deductions</legend><div className="payroll-editor-grid">{deductionFields.map(([field, label]) => <label key={field}>{label}<div className="currency-input"><span>PHP</span><input type="number" min="0" max="99999999.99" step="0.01" value={form.data[field]} onChange={(event) => form.setData(field, event.target.value)} required /></div></label>)}</div></fieldset>
            <div className="form-actions"><button className="ui-control ui-action ui-primary" type="submit" disabled={form.processing}><Save size={17} />{form.processing ? 'Saving...' : 'Save Payslip Details'}</button></div>
        </form>}

        <article className="official-payslip" ref={payslipRef}>
            <header className="official-payslip-header">
                <img src="/images/cvsu-logo.png" alt="Cavite State University logo" />
                <div><h2>Cavite State University</h2><strong>Imus Campus</strong><p>Cavite Civic Center, Palico IV, Imus, Cavite</p><p>(046) 471-66-07 / (046) 686-2349</p><a href="https://www.cvsu.edu.ph">www.cvsu.edu.ph</a></div>
                <span className="official-copy-label">OFFICIAL PAYSLIP</span>
            </header>
            <section className="official-employee-details" aria-label="Employee and payroll period details">
                <div><span>Employee Name:</span><strong>{employeeName}</strong></div>
                <div><span>Employee ID No.:</span><strong>{employee?.employee_no || '-'}</strong></div>
                <div><span>Department:</span><strong>{employee?.department || '-'}</strong></div>
                <div><span>Cut-off Date:</span><strong>{cutoff}</strong></div>
            </section>
            <section className="official-pay-columns">
                <div className="official-pay-column" aria-label="Earnings">
                    <div className="official-column-heading"><span>Earnings</span><small>Attendance and additions</small></div>
                    <PayslipRow label="RATE PER HOUR" value={amount(employee?.rate_amount, false)} />
                    <PayslipRow label="TOTAL NO. OF HOURS" value={amount(record.total_hours_worked, false)} />
                    <PayslipRow label="Overtime Pay" value={amount(record.overtime_pay)} />
                    <PayslipRow label="Late/Undertime (mins.)" value={record.late_undertime_minutes || '-'} />
                    <PayslipRow label="Absent (days)" value={Number(record.absent_days || 0) || '-'} />
                    <PayslipRow label="Others" value={amount(record.other_earnings)} />
                    <PayslipRow label="Increase" value={amount(record.increase_amount)} />
                    <PayslipRow label="Total Earnings" value={amount(totalEarnings, false)} emphasis />
                </div>
                <div className="official-pay-column" aria-label="Deductions">
                    <div className="official-column-heading"><span>Deductions</span><small>Contributions, taxes and loans</small></div>
                    <PayslipRow label="Withholding Tax" value={amount(record.withholding_tax)} />
                    <PayslipRow label="GSIS" value={amount(record.gsis_deduction)} />
                    <PayslipRow label="PhilHealth" value={amount(record.philhealth_deduction)} />
                    <PayslipRow label="Pag-IBIG" value={amount(record.pag_ibig_deduction)} />
                    <div className="official-loan-label">LOANS:</div>
                    <PayslipRow label="Multi-Purpose Loan" value={amount(record.multi_purpose_loan)} subrow />
                    <PayslipRow label="GSIS Loan" value={amount(record.gsis_loan)} subrow />
                    <PayslipRow label="GSIS ePlus Loan" value={amount(record.gsis_eplus_loan)} subrow />
                    <PayslipRow label="FEA Dues" value={amount(record.fea_dues)} />
                    <PayslipRow label="OBA" value={amount(record.oba_deduction)} />
                    <PayslipRow label="CRA" value={amount(record.cra_deduction)} />
                    <PayslipRow label="Total Deductions" value={amount(record.total_deductions)} emphasis />
                </div>
            </section>
            <div className="official-net-income"><span>Net Income</span><strong>PHP {amount(record.net_pay, false)}</strong></div>
            <section className="official-government-ids" aria-label="Government identification numbers">
                <div><span>TIN #</span><strong>{employee?.tin_no || '-'}</strong></div>
                <div><span>GSIS #</span><strong>{employee?.gsis_no || '-'}</strong></div>
                <div><span>Pag-IBIG #</span><strong>{employee?.pag_ibig_no || '-'}</strong></div>
                <div><span>PhilHealth #</span><strong>{employee?.philhealth_no || '-'}</strong></div>
            </section>
            <footer className="official-signatories">
                <div><span>Prepared by:</span><i></i><strong>Celine Jane S. Magsumbol</strong><small>Admin Aide VI</small></div>
                <div><span>Noted by:</span><i></i><strong>Analin I. Vasquez</strong><small>Admin Officer I</small></div>
            </footer>
        </article>
        {dialog}
    </AppLayout>;
}
