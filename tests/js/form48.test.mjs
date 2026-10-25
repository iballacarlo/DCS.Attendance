import test from 'node:test';
import assert from 'node:assert/strict';
import ExcelJS from 'exceljs';
import { jsPDF } from 'jspdf';
import { createForm48Workbook } from '../../resources/js/lib/form48Excel.js';
import { cellText, form48Cells, formTime, scheduleRange } from '../../resources/js/lib/form48Layout.js';
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
    assert.equal(cellText(cells.find((c) => c.row === 22 && c.col === 8))[0].x, 167);
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
    assert.equal(sheet.pageSetup.printArea, 'A1:I54');
    assert.equal(sheet.views[0].showGridLines, false);
    assert.equal(sheet.getCell('B44').value, 31);
    assert.equal(sheet.getCell('B14').value, 1);
    assert.equal(sheet.getCell('C16').numFmt, 'h:mm');
    assert.equal(sheet.getCell('C16').value.toISOString(), '1899-12-30T08:06:00.000Z');
    assert.equal(sheet.getCell('F16').value.toISOString(), '1899-12-30T19:07:00.000Z');
    assert.equal(sheet.getCell('G16').value, null);
    assert.equal(sheet.getCell('H16').value, 30);
    assert.equal(sheet.getCell('I16').value, null);
    assert.equal(sheet.getCell('I23').value, 6);
    assert.equal(sheet.getCell('I23').numFmt, '0');
    assert.equal(sheet.getCell('A16').alignment.horizontal, 'right');
    assert.equal(sheet.getCell('I23').alignment.horizontal, 'left');
    assert.equal(sheet.getCell('C16').protection.locked, false);
    assert.equal(sheet.getCell('A16').border.left, undefined);
    assert.equal(sheet.getCell('I23').border.left, undefined);
    sheet.getCell('C16').value = 8 / 24;
    sheet.getCell('H16').value = 24;
    const edited = new ExcelJS.Workbook();
    await edited.xlsx.load(await workbook.xlsx.writeBuffer());
    assert.equal(edited.worksheets[0].getCell('C16').value.toISOString(), '1899-12-30T08:00:00.000Z');
    assert.equal(edited.worksheets[0].getCell('H16').value, 24);
    assert.equal(edited.worksheets[0].getCell('G16').formula, undefined);
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
