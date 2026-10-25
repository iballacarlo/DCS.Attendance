import { cellBounds, cellText, form48Cells } from './form48Layout.js';

export function drawForm48(pdf, dtr) {
    pdf.setTextColor(17); pdf.setDrawColor(17); pdf.setLineWidth(0.2);
    for (const cell of form48Cells(dtr)) {
        const box = cellBounds(cell);
        if (cell.border === 'box') pdf.rect(box.x, box.y, box.width, box.height);
        if (cell.border === 'bottom') pdf.line(box.x, box.y + box.height, box.x + box.width, box.y + box.height);
        pdf.setFont('helvetica', cell.bold ? 'bold' : 'normal'); pdf.setFontSize(cell.size);
        for (const line of cellText(cell)) pdf.text(line.text, line.x, line.y, { align: cell.align });
    }
    return pdf;
}
