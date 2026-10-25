import { formatDate, fullName, time12 } from './format.js';

// The same attachment is used by Download PDF and Print.
export function appendDtrBreakdown(pdf, dtr, { standalone = false } = {}) {
    const margin = 12;
    const widths = [23, 32, 21, 29, 23, 23, 15, 20];
    const tableWidth = widths.reduce((sum, width) => sum + width, 0);
    const headers = ['Date', 'Subject / Type', 'Section', 'Schedule', 'Time In', 'Time Out', 'Hours', 'Status'];
    const attachmentPages = [];
    let y;
    let contentTop;
    const font = (bold = false, size = 8) => { pdf.setFont('helvetica', bold ? 'bold' : 'normal'); pdf.setFontSize(size); };
    const textLines = (text, width) => pdf.splitTextToSize(String(text ?? ''), width);
    const drawRow = (cells, { bold = false, fill = null, minHeight = 10, size = 8 } = {}) => {
        font(bold, size);
        const lines = cells.map((cell, index) => textLines(cell, widths[index] - 4));
        const height = Math.max(minHeight, ...lines.map((cell) => cell.length * 3.8 + 4));
        let x = margin;
        if (fill) { pdf.setFillColor(...fill); pdf.rect(x, y, tableWidth, height, 'F'); }
        pdf.setDrawColor(155, 163, 157); pdf.setLineWidth(0.15); pdf.setTextColor(25, 35, 29);
        lines.forEach((cell, index) => {
            pdf.rect(x, y, widths[index], height);
            pdf.text(cell, index === 6 ? x + widths[index] - 2 : x + 2, y + 4.6, { align: index === 6 ? 'right' : 'left' });
            x += widths[index];
        });
        y += height;
        return height;
    };
    const newPage = () => {
        if (!standalone || attachmentPages.length > 0) pdf.addPage();
        attachmentPages.push(pdf.getNumberOfPages());
        pdf.setTextColor(25, 35, 29);
        font(true, 10); pdf.text('CAVITE STATE UNIVERSITY', margin, 14);
        font(false, 8); pdf.text('Imus Campus', margin, 19);
        font(true, 13); pdf.text('CLASS ATTENDANCE BREAKDOWN', margin, 28);
        font(false, 8); pdf.text('Supporting attachment to Civil Service Form No. 48', margin, 33);
        font(true, 9);
        const name = textLines(fullName(dtr.employee), tableWidth);
        pdf.text(name, margin, 41);
        y = 41 + name.length * 4.2;
        font(false, 8);
        pdf.text(`Employee No.: ${dtr.employee.employee_no || '-'}    |    Cutoff: ${formatDate(dtr.cutoff.start_date)} - ${formatDate(dtr.cutoff.end_date)}`, margin, y + 2);
        y += 8;
        drawRow(headers, { bold: true, fill: [234, 240, 236], minHeight: 10 });
        contentTop = y;
    };
    const ensureSpace = (height) => { if (y + height > 267) newPage(); };
    const hours = (session) => session.time_in || session.time_out ? Number(session.hours).toFixed(2) : '';
    const groups = dtr.rows.filter((row) => row.sessions.length);
    newPage();
    for (const day of groups) {
        const entries = day.sessions.map((session) => {
            const consultation = session.schedule_type === 'consultation';
            const type = session.class_mode === 'lecture' ? 'Lecture' : session.class_mode === 'laboratory' ? 'Laboratory' : 'Type not specified';
            const cells = [formatDate(day.date), consultation ? 'Consultation' : `${session.subject || 'Not specified'}\n${type}`,
                consultation ? '-' : session.section || 'Not specified', `${time12(session.scheduled_start)}\n- ${time12(session.scheduled_end)}`,
                session.time_in ? time12(session.time_in) : '', session.time_out ? time12(session.time_out) : '', hours(session),
                session.time_in && session.time_out ? 'Complete' : 'Incomplete'];
            font();
            const height = Math.max(12, ...cells.map((cell, column) => textLines(cell, widths[column] - 4).length * 3.8 + 4));
            return { cells, height };
        });
        // Keep a day's classes together whenever the group fits on one page.
        const dayHeight = entries.reduce((sum, entry) => sum + entry.height, 10);
        if (dayHeight <= 267 - contentTop) ensureSpace(dayHeight);
        entries.forEach(({ cells, height }, index) => {
            // Keep the day's total with its last class, even at a page boundary.
            ensureSpace(height + (index === entries.length - 1 ? 10 : 0));
            drawRow(cells, { minHeight: 12 });
        });
        drawRow([formatDate(day.date), 'Daily total', '', '', '', '', day.time_in || day.time_out ? Number(day.payable_hours).toFixed(2) : '', ''], { bold: true, fill: [245, 247, 245] });
    }
    if (!groups.length) {
        font(false, 9); pdf.text('No class attendance sessions recorded for this cutoff.', margin, y + 8); y += 17;
    }
    ensureSpace(65);
    drawRow(['', 'CUTOFF TOTAL', '', '', '', '', Number(dtr.summary.total_hours).toFixed(2), ''], { bold: true, fill: [234, 240, 236] });
    font(false, 8);
    const notes = [
        'Form 48 shows the earliest time-in and latest time-out each day; this attachment lists every class tap pair.',
        'Hours count completed tap pairs within class and consultation schedules. Gaps and breaks are excluded.',
        'Incomplete pairs receive zero payable hours. Totals use unrounded durations.',
    ];
    for (const note of notes) {
        const lines = textLines(note, tableWidth);
        ensureSpace(lines.length * 4 + 3);
        pdf.text(lines, margin, y + 6); y += lines.length * 4 + 3;
    }
    ensureSpace(24);
    y += 14;
    pdf.setDrawColor(90); pdf.line(margin, y, margin + 78, y); pdf.line(120, y, 198, y);
    font(false, 8); pdf.text('Faculty signature / Date', margin, y + 5); pdf.text('Checked by HR / Date', 120, y + 5);
    const totalPages = pdf.getNumberOfPages();
    for (const page of attachmentPages) {
        pdf.setPage(page);
        font(false, 7); pdf.setTextColor(85);
        pdf.text('Form 48 attachment | Class attendance details', margin, 286);
        pdf.text(`Page ${page} of ${totalPages}`, 198, 286, { align: 'right' });
    }
}
