import test from 'node:test';
import assert from 'node:assert/strict';
import ExcelJS from 'exceljs';
import { makeClassReportPdf, makeClassReportWorkbook, reportValues } from '../../resources/js/lib/classReportExport.js';

export function reportFixture(count = 80, long = false) {
    const summary = { expected: count, ended: count, complete: count, incomplete: 0, missed: 0, excluded: 0, completion_rate: count ? 100 : null, on_time: 0, late: count, undertime: count, total_late_minutes: count * 5, total_undertime_minutes: count * 10 };
    const breakdown = [{ label: long ? 'Faculty name with many components '.repeat(8) : 'Dela Cruz, Juan', ...summary }];
    return {
        filters: { start_date: '2026-10-01', end_date: '2026-10-15', employee_id: 123, subject: 'DCIT 21', section: 'BSIT 1A', class_mode: 'lecture' },
        summary, trend: [{ label: '2026-10-05', ...summary }], faculty_breakdown: breakdown, subject_breakdown: [{ label: 'DCIT 21', ...summary }],
        rows: Array.from({ length: count }, (_, i) => ({ key: String(i), date: '2026-10-05', faculty: breakdown[0].label,
            subject: long ? 'Historical subject title '.repeat(10) : 'DCIT 21', section: 'BSIT 1A', class_mode: 'lecture',
            scheduled_start: '08:00:00', scheduled_end: '10:00:00', time_in: '08:05:00', time_out: '09:50:00',
            late_minutes: 5, early_departure_minutes: 10, status: 'complete', ended: true, exclusion: null })),
    };
}

test('Excel report preserves applied filters, all rows, summaries, and breakdowns after reopening', async () => {
    const report = reportFixture();
    const workbook = await makeClassReportWorkbook(report);
    const reopened = new ExcelJS.Workbook();
    await reopened.xlsx.load(await workbook.xlsx.writeBuffer());
    assert.equal(reopened.worksheets.length, 5);
    assert.equal(reopened.getWorksheet('Sessions').rowCount, report.rows.length + 1);
    assert.deepEqual(reopened.getWorksheet('Sessions').getRow(2).values.slice(1), reportValues(report.rows[0]));
    assert.equal(reopened.getWorksheet('Summary').getCell('B5').value, report.summary.expected);
    assert.equal(reopened.getWorksheet('Summary').getCell('B11').value, report.summary.completion_rate);
    assert.match(reopened.getWorksheet('Summary').getCell('A2').value, /subject: DCIT 21/);
    assert.equal(reopened.getWorksheet('By Faculty').getCell('D2').value, report.summary.complete);
    assert.equal(reopened.getWorksheet('Sessions').getCell('J2').type, ExcelJS.ValueType.Number);
    assert.equal(reopened.getWorksheet('Summary').getCell('B13').value, 80);
    assert.equal(reopened.getWorksheet('Summary').getCell('B15').value, 400);
    assert.equal(reopened.getWorksheet('By Faculty').getCell('J2').value, 80);
    assert.equal(reopened.getWorksheet('By Faculty').getCell('M2').value, 800);
    assert.equal(reopened.getWorksheet('Sessions').getCell('K1').value, 'Undertime (min)');
    assert.match(reopened.getWorksheet('Sessions').getCell('L2').value, /Late.*Undertime/);
});

test('PDF paginates all details and repeats column headings on later pages', async () => {
    const report = reportFixture();
    const pdf = await makeClassReportPdf(report);
    assert.ok(pdf.getNumberOfPages() > 3);
    const text = pdf.output();
    assert.equal((text.match(/08:05:00/g) || []).length, report.rows.length);
    assert.match(text, /Expected sessions: 80/);
    assert.match(text, /subject: DCIT 21/);
    assert.match(text, /Late classes: 80/);
    assert.match(text, /Total undertime minutes: 800/);
    assert.ok((text.match(/Scheduled|Schedule/g) || []).length > 2);
});

test('empty and long reports export without inventing session rows', async () => {
    for (const report of [reportFixture(0), reportFixture(2, true)]) {
        const pdf = await makeClassReportPdf(report);
        const workbook = await makeClassReportWorkbook(report);
        assert.ok(pdf.getNumberOfPages() >= 2);
        assert.equal(workbook.getWorksheet('Sessions').rowCount, report.rows.length + 1);
    }
});
