import test from 'node:test';
import assert from 'node:assert/strict';
import ExcelJS from 'exceljs';
import { jsPDF } from 'jspdf';
import { createForm48Workbook } from '../../resources/js/lib/form48Excel.js';
import { cellBounds, cellText, form48Cells, formTime, scheduleRange } from '../../resources/js/lib/form48Layout.js';
import { drawForm48 } from '../../resources/js/lib/form48Pdf.js';
import { appendDtrBreakdown } from '../../resources/js/lib/dtrBreakdownPdf.js';
import { sampleForm48 } from './form48Fixture.mjs';

test('Form 48 uses 24-hour times and unbordered margin cells', () => {
    assert.equal(formTime('19:07:00'), '19:07');
    assert.equal(formTime('08:06'), '8:06');
    assert.equal(formTime(null), '');
    assert.equal(scheduleRange('7:00 AM-7:00 PM'), '7-7');
    assert.equal(scheduleRange('7:30 AM-1:00 PM'), '7:30-1');
    const cells = form48Cells(sampleForm48());
    assert.equal(cells.filter((c) => c.col === 1 && c.row >= 13 && c.row <= 43).length, 31);
    assert.ok(cells.filter((c) => c.col === 0 || c.col === 8).every((c) => !c.border));
    assert.equal(cells.find((c) => c.row === 15 && c.col === 8).text, '');
    assert.equal(cells.find((c) => c.row === 22 && c.col === 8).text, '6');
    const fractional = sampleForm48();
    fractional.form_rows[9].margin_hours = 7.5;
    assert.equal(form48Cells(fractional).find((c) => c.row === 22 && c.col === 8).text, '7.50');
    assert.equal(cellText(cells.find((c) => c.row === 15 && c.col === 0))[0].x, 43);
    assert.equal(cellText(cells.find((c) => c.row === 22 && c.col === 8))[0].x, 128);
});

test('narrowed form shares the certification width while preserving its position', () => {
    const cells = form48Cells(sampleForm48());
    const certification = cells.filter((cell) => cell.row >= 46 && cell.row <= 48);
    assert.deepEqual(certification.map((cell) => cellText(cell)[0].x), [51, 45.5, 45.5]);
    for (const value of ['DAILY TIME RECORD', 'In Charge', 'Verified as to the prescribed office hours:']) {
        const box = cellBounds(cells.find((cell) => cell.value === value));
        assert.equal(box.x, 45);
        assert.equal(box.width, 81);
    }
    const table = cells.filter((cell) => cell.border === 'box');
    assert.equal(Math.min(...table.map((cell) => cellBounds(cell).x)), 45);
    assert.equal(Math.max(...table.map((cell) => cellBounds(cell).x + cellBounds(cell).width)), 126);
    const pdf = new jsPDF();
    for (const cell of cells.filter((cell) => cell.row === 12)) {
        pdf.setFont('helvetica', 'bold'); pdf.setFontSize(cell.size);
        assert.ok(pdf.getTextWidth(cell.value) <= cellBounds(cell).width - 0.8, `${cell.value} must fit its column`);
    }
    const headers = cells.filter((cell) => cell.row === 12);
    for (const cell of headers.filter((cell) => ['Arrival', 'Hours', 'Minutes'].includes(cell.value))) assert.equal(cellBounds(cell).width, 11);
    for (const cell of headers.filter((cell) => cell.value === 'Departure')) assert.equal(cellBounds(cell).width, 15);
    const regular = cells.find((cell) => cell.value === 'Regular days');
    const saturdays = cells.find((cell) => cell.value === 'Saturdays');
    assert.equal(regular.size, saturdays.size);
    const underline = cellBounds(cells.find((cell) => cell.row === 8 && cell.border === 'bottom'));
    assert.equal(underline.x, 108);
    assert.equal(underline.x + underline.width, 126);
    pdf.setFont('helvetica', 'normal'); pdf.setFontSize(regular.size);
    assert.ok(pdf.getTextWidth(regular.value) < cellBounds(regular).width - 1);
    assert.equal(cellText(regular)[0].x, cellText(saturdays)[0].x);
    assert.equal(cellText(regular)[0].x, 89.5);
    const official = cells.find((cell) => cell.value === 'Official hours for arrival');
    assert.equal(cells.find((cell) => cell.value === 'For the month of').size, official.size);
    assert.equal(cellText(official)[0].x, 45.5);
    assert.equal(cellText(cells.find((cell) => cell.value === 'and departure'))[0].x, 45.5);
    assert.ok(cellText(regular)[0].x + pdf.getTextWidth(regular.value) < underline.x);
    assert.equal(cells.find((cell) => cell.value === '(NAME)').bold, true);
    assert.equal(cellText(cells.find((cell) => cell.value === 'Civil Service Form No. 48'))[0].x, 51);
});

test('Excel round trip preserves native editable values, 31 days, and A4 print settings', async () => {
    const original = await createForm48Workbook(sampleForm48());
    const workbook = new ExcelJS.Workbook();
    await workbook.xlsx.load(await original.xlsx.writeBuffer());
    assert.equal(workbook.worksheets.length, 1);
    const sheet = workbook.getWorksheet('Form 48');
    assert.equal(sheet.pageSetup.paperSize, 9);
    assert.equal(sheet.pageSetup.fitToWidth, 1);
    assert.equal(sheet.pageSetup.fitToHeight, 1);
    assert.equal(sheet.pageSetup.printArea, 'A1:K54');
    assert.equal(sheet.views[0].showGridLines, false);
    assert.equal(sheet.getCell('B44').value, 31);
    assert.equal(sheet.getCell('B14').value, 1);
    assert.equal(sheet.getCell('C16').numFmt, 'h:mm');
    assert.equal(sheet.getCell('C16').value.toISOString(), '1899-12-30T08:06:00.000Z');
    assert.equal(sheet.getCell('G16').value.toISOString(), '1899-12-30T19:07:00.000Z');
    assert.equal(sheet.getCell('H16').value, null);
    assert.equal(sheet.getCell('J16').value, 30);
    assert.equal(sheet.getCell('K16').value, null);
    assert.equal(sheet.getCell('K23').value, 6);
    assert.equal(sheet.getCell('K23').numFmt, '0');
    assert.equal(sheet.getCell('A16').alignment.horizontal, 'right');
    assert.equal(sheet.getCell('K23').alignment.horizontal, 'left');
    assert.equal(sheet.getCell('C16').protection.locked, false);
    assert.equal(sheet.getCell('A16').border.left, undefined);
    assert.equal(sheet.getCell('K23').border.left, undefined);
    assert.equal(sheet.getCell('B47').value.richText[0].text, 'I CERTIFY');
    assert.equal(sheet.getCell('B47').value.richText[0].font.bold, true);
    assert.equal(sheet.getCell('B47').value.richText[1].font.bold, undefined);
    assert.equal(sheet.getCell('B47').alignment.horizontal, 'left');
    assert.equal(sheet.getCell('B47').alignment.indent, 2);
    assert.equal(sheet.getCell('B48').alignment.horizontal, 'left');
    assert.equal(sheet.getCell('B48').alignment.indent || 0, 0);
    assert.equal(sheet.getCell('B54').font.bold, true);
    assert.equal(sheet.getCell('B1').alignment.indent, 2);
    assert.equal(sheet.getCell('B9').alignment.horizontal, 'left');
    assert.equal(sheet.getCell('B10').alignment.horizontal, 'left');
    assert.equal(sheet.getCell('G9').value, 'Regular days');
    assert.equal(sheet.getCell('G10').value, 'Saturdays');
    assert.equal(sheet.getCell('E8').value, sampleForm48().cutoff.label);
    assert.equal(sheet.getCell('B8').font.size, sheet.getCell('B9').font.size);
    assert.equal(sheet.getCell('B6').font.bold, true);
    assert.equal(sheet.getCell('G9').alignment.horizontal, 'left');
    assert.equal(sheet.getCell('G10').alignment.horizontal, 'left');
    sheet.getCell('C16').value = 8 / 24;
    sheet.getCell('J16').value = 24;
    const edited = new ExcelJS.Workbook();
    await edited.xlsx.load(await workbook.xlsx.writeBuffer());
    assert.equal(edited.worksheets[0].getCell('C16').value.toISOString(), '1899-12-30T08:00:00.000Z');
    assert.equal(edited.worksheets[0].getCell('J16').value, 24);
    assert.equal(edited.worksheets[0].getCell('H16').formula, undefined);
});

test('Form PDF and standalone breakdown each start on their own first page', () => {
    const dtr = sampleForm48();
    const form = drawForm48(new jsPDF(), dtr);
    assert.equal(form.getNumberOfPages(), 1);
    const breakdown = new jsPDF();
    appendDtrBreakdown(breakdown, dtr, { standalone: true });
    assert.equal(breakdown.getNumberOfPages(), 1);
    const empty = new jsPDF();
    appendDtrBreakdown(empty, { ...dtr, rows: [], summary: { total_hours: 0 } }, { standalone: true });
    assert.equal(empty.getNumberOfPages(), 1);
});

test('February export still has a blank day 31 and rows outside the cutoff', async () => {
    const sheet = (await createForm48Workbook(sampleForm48({ year: 2028, month: 2, second: true }))).worksheets[0];
    assert.equal(sheet.getCell('B44').value, 31);
    for (const address of ['A44', 'C44', 'D44', 'E44', 'F44', 'G44', 'H44', 'I44', 'A14', 'C14', 'I14']) assert.equal(sheet.getCell(address).value || null, null);
});
