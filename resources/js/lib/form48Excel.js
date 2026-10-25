import { FORM48, form48Cells } from './form48Layout.js';

export async function createForm48Workbook(dtr) {
    const { default: ExcelJS } = await import('exceljs');
    const workbook = new ExcelJS.Workbook();
    workbook.creator = 'DCS Payroll System';
    const sheet = workbook.addWorksheet('Form 48', {
        views: [{ showGridLines: false }],
        pageSetup: {
            paperSize: 9, orientation: 'portrait', fitToPage: true, fitToWidth: 1, fitToHeight: 1,
            horizontalCentered: true, verticalCentered: false,
            margins: { left: 20 / 25.4, right: 20 / 25.4, top: 10 / 25.4, bottom: 20 / 25.4, header: 0, footer: 0 },
            printArea: 'A1:I54',
        },
    });
    for (let col = 0; col < 9; col++) {
        const pixels = (FORM48.columns[col + 1] - FORM48.columns[col]) * 96 / 25.4;
        sheet.getColumn(col + 1).width = (pixels - 5) / 7;
    }
    for (let row = 0; row < FORM48.rows.length - 1; row++) sheet.getRow(row + 1).height = (FORM48.rows[row + 1] - FORM48.rows[row]) * 72 / 25.4;
    for (const cell of form48Cells(dtr)) {
        const row = cell.row + 1, col = cell.col + 1;
        if (cell.endCol != null || cell.endRow != null) sheet.mergeCells(row, col, (cell.endRow ?? cell.row) + 1, (cell.endCol ?? cell.col) + 1);
        const target = sheet.getCell(row, col);
        target.value = cell.value ?? null;
        target.font = { name: 'Arial', size: cell.size, bold: !!cell.bold, color: { argb: 'FF111111' } };
        target.alignment = { horizontal: cell.align, vertical: 'middle', indent: cell.padding ? 1 : 0, wrapText: String(cell.value ?? '').includes('\n'), shrinkToFit: !String(cell.value ?? '').includes('\n') };
        target.protection = { locked: false };
        if (cell.format) target.numFmt = cell.format;
        const edge = { style: 'thin', color: { argb: 'FF111111' } };
        if (cell.border === 'box') target.border = { left: edge, right: edge, top: edge, bottom: edge };
        if (cell.border === 'bottom') target.border = { bottom: edge };
    }
    return workbook;
}

export async function downloadForm48Excel(dtr, filename) {
    const workbook = await createForm48Workbook(dtr);
    const data = await workbook.xlsx.writeBuffer();
    const url = URL.createObjectURL(new Blob([data], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
    const anchor = document.createElement('a'); anchor.href = url; anchor.download = filename; anchor.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 60000);
}
