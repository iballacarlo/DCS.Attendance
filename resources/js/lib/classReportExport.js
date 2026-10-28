import { sessionIndicators } from './classAnalytics.js';

export const reportColumns = ['Date', 'Faculty', 'Subject', 'Section', 'Mode', 'Scheduled IN', 'Scheduled OUT', 'Actual IN', 'Actual OUT', 'Late (min)', 'Undertime (min)', 'Status', 'Exclusion'];
export const reportValues = (r) => [r.date, r.faculty, r.subject || '', r.section || '', r.class_mode || '', r.scheduled_start, r.scheduled_end, r.time_in || '', r.time_out || '', r.late_minutes, r.early_departure_minutes, sessionIndicators(r).map((item) => item.label).join('; '), r.exclusion || ''];
export const reportSummary = (r) => [['Expected sessions', r.summary.expected], ['Eligible ended sessions', r.summary.ended], ['Complete', r.summary.complete], ['Incomplete', r.summary.incomplete], ['Absent / no taps', r.summary.missed], ['Excluded', r.summary.excluded], ['Completion rate (%)', r.summary.completion_rate], ['On time & full class', r.summary.on_time || 0], ['Late classes', r.summary.late || 0], ['Undertime classes', r.summary.undertime || 0], ['Total late minutes', r.summary.total_late_minutes || 0], ['Total undertime minutes', r.summary.total_undertime_minutes || 0]];
export const reportFilterLabel = (r) => Object.entries(r.filters).filter(([, v]) => v != null && v !== '').map(([k, v]) => `${k.replaceAll('_', ' ')}: ${v}`).join(' | ');

export async function makeClassReportWorkbook(report) {
    const { default: ExcelJS } = await import('exceljs');
    const workbook = new ExcelJS.Workbook();
    workbook.creator = 'DCS Attendance & DTR';
    const summary = workbook.addWorksheet('Summary');
    summary.addRow(['DCS Attendance & DTR — Class Attendance Report']);
    summary.addRow([reportFilterLabel(report)]);
    summary.addRow(['Late and undertime use scheduled class times, with no grace period. One class may count in both. Absent = no class taps.']);
    summary.addRow([]);
    reportSummary(report).forEach((row) => summary.addRow(row));
    summary.getColumn(1).width = 65; summary.getColumn(2).width = 24;
    summary.getRow(1).height = 30;
    summary.getRow(2).height = Math.ceil(reportFilterLabel(report).length / 60) * 16 + 8;
    summary.getRow(3).height = 32;
    const details = workbook.addWorksheet('Sessions');
    details.addRow(reportColumns);
    report.rows.forEach((r) => details.addRow(reportValues(r)));
    details.columns.forEach((col, i) => { col.width = [12, 30, 28, 18, 16, 16, 16, 16, 16, 14, 16, 18, 30][i]; });
    details.autoFilter = { from: 'A1', to: 'M1' };
    details.views = [{ state: 'frozen', ySplit: 1 }];
    for (const [title, rows] of [['By Faculty', report.faculty_breakdown], ['By Subject', report.subject_breakdown], ['Daily Trend', report.trend]]) {
        const sheet = workbook.addWorksheet(title);
        sheet.addRow(['Label', 'Expected', 'Ended', 'Complete IN/OUT', 'Incomplete', 'Absent / no taps', 'Excluded', 'Completion rate (%)', 'On time & full class', 'Late', 'Undertime', 'Late minutes', 'Undertime minutes']);
        rows.forEach((r) => sheet.addRow([r.label, r.expected, r.ended, r.complete, r.incomplete, r.missed, r.excluded, r.completion_rate, r.on_time || 0, r.late || 0, r.undertime || 0, r.total_late_minutes || 0, r.total_undertime_minutes || 0]));
        sheet.getColumn(1).width = 40;
        for (let i = 2; i <= 13; i++) sheet.getColumn(i).width = 18;
    }
    workbook.eachSheet((sheet) => {
        sheet.getRow(1).font = { bold: true, color: { argb: 'FFFFFFFF' } };
        sheet.getRow(1).fill = { type: 'pattern', pattern: 'solid', fgColor: { argb: 'FF17543C' } };
        sheet.eachRow((row) => { row.alignment = { vertical: 'top', wrapText: true }; });
        sheet.pageSetup = { orientation: 'landscape', fitToPage: true, fitToWidth: 1, fitToHeight: 0 };
    });
    return workbook;
}

export async function makeClassReportPdf(report) {
    const { jsPDF } = await import('jspdf');
    const pdf = new jsPDF({ orientation: 'landscape', unit: 'mm', format: 'a4' });
    let y = 14;
    const line = (text, size = 9) => {
        pdf.setFontSize(size);
        const lines = pdf.splitTextToSize(String(text), 273);
        for (const item of lines) {
            if (y > 192) { pdf.addPage(); y = 14; }
            pdf.text(item, 12, y); y += size * 0.45 + 1;
        }
    };
    line('DCS Attendance & DTR | Class Attendance Report', 15);
    line(reportFilterLabel(report));
    line('Late and undertime use scheduled class times, with no grace period. One class may count in both. Absent = no class taps.');
    line(reportSummary(report).map(([k, v]) => `${k}: ${v ?? '-'}`).join(' | '));
    for (const [title, rows] of [['Daily trend', report.trend], ['By faculty', report.faculty_breakdown], ['By subject', report.subject_breakdown]]) {
        line(title, 11);
        rows.forEach((r) => line(`${r.label} | Expected ${r.expected} | Ended ${r.ended} | Complete ${r.complete} | Incomplete ${r.incomplete} | Absent ${r.missed} | Excluded ${r.excluded} | Rate ${r.completion_rate ?? '-'}% | On time ${r.on_time || 0} | Late ${r.late || 0} (${r.total_late_minutes || 0} min) | Undertime ${r.undertime || 0} (${r.total_undertime_minutes || 0} min)`));
    }
    const widths = [21, 34, 36, 23, 20, 34, 31, 31, 20, 23];
    const headers = ['Date', 'Faculty', 'Subject', 'Section / Mode', 'Status', 'Schedule', 'IN / OUT', 'Late / Undertime (min)', 'Excluded', 'Reason'];
    const header = () => {
        pdf.setFontSize(7); pdf.setFillColor(23, 84, 60); pdf.rect(12, y - 4, 273, 9, 'F'); pdf.setTextColor(255);
        let x = 12; headers.forEach((v, i) => { pdf.text(v, x + 1, y); x += widths[i]; }); pdf.setTextColor(0); y += 8;
    };
    const detailHeader = () => {
        pdf.setFontSize(9);
        pdf.text(`Class attendance | ${report.filters.start_date} - ${report.filters.end_date}`, 12, 10);
        y = 19; header();
    };
    pdf.addPage(); detailHeader();
    if (!report.rows.length) { pdf.setFontSize(9); pdf.text('No class sessions match the applied filters.', 12, y + 3); }
    report.rows.forEach((r) => {
        const values = [r.date, r.faculty, r.subject || '-', `${r.section || '-'} / ${r.class_mode || '-'}`, sessionIndicators(r).map((item) => item.label.replace(' ? ', ' ')).join('; '), `${r.scheduled_start} - ${r.scheduled_end}`, `${r.time_in || '-'} / ${r.time_out || '-'}`, `${r.late_minutes ?? '-'} / ${r.early_departure_minutes ?? '-'}`, r.exclusion ? 'Yes' : 'No', r.exclusion || '-'];
        const wrapped = values.map((v, i) => pdf.splitTextToSize(String(v), widths[i] - 2));
        const height = Math.max(...wrapped.map((v) => v.length)) * 3.5 + 3;
        if (y + height > 195) { pdf.addPage(); detailHeader(); }
        let x = 12; wrapped.forEach((v, i) => { pdf.text(v, x + 1, y); x += widths[i]; });
        y += height; pdf.setDrawColor(220); pdf.line(12, y - 3, 285, y - 3);
    });
    for (let i = 1; i <= pdf.getNumberOfPages(); i++) { pdf.setPage(i); pdf.setFontSize(8); pdf.text(`${i} / ${pdf.getNumberOfPages()}`, 280, 204, { align: 'right' }); }
    return pdf;
}

export async function exportClassReport(report, kind) {
    const filename = `CLASS_ATTENDANCE_${report.filters.start_date}_${report.filters.end_date}`;
    if (kind === 'pdf') { const pdf = await makeClassReportPdf(report); pdf.save(`${filename}.pdf`); return; }
    const workbook = await makeClassReportWorkbook(report);
    const buffer = await workbook.xlsx.writeBuffer();
    const url = URL.createObjectURL(new Blob([buffer], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
    const anchor = document.createElement('a'); anchor.href = url; anchor.download = `${filename}.xlsx`; anchor.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
