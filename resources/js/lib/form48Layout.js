import { fullName } from './format.js';

// Coordinates in millimetres on A4. Every renderer uses these same cell boundaries.
export const FORM48 = {
    width: 210, height: 297,
    columns: [20, 45, 54, 73, 92, 111, 130, 147.5, 165, 190],
    rows: [10, 18, 24, 31, 38, 45, 50, 55, 61, 66, 71, 75, 82, 89,
        ...Array.from({ length: 32 }, (_, i) => 93 + i * 4),
        222, 226, 230, 234, 242, 249, 254, 261, 267],
};

export const formTime = (value) => value ? `${Number(String(value).slice(0, 2))}:${String(value).slice(3, 5)}` : '';
export const scheduleRange = (value) => value ? value.replace(/(\d{1,2}):(\d{2})\s*(AM|PM)/g, (_, hour, minute) => `${Number(hour)}${minute === '00' ? '' : `:${minute}`}`) : '';
const timeValue = (value) => value ? String(value).split(':').reduce((total, part, index) => total + Number(part) / [24, 1440, 86400][index], 0) : null;

export function form48Cells(dtr) {
    const cells = [];
    const add = (row, col, value = '', options = {}) => cells.push({ row, col, value, size: 9, align: 'center', ...options });
    const name = fullName(dtr.employee).toUpperCase();
    add(0, 1, 'Civil Service Form No. 48', { endCol: 7, align: 'left', size: 10 });
    add(2, 1, 'DAILY TIME RECORD', { endCol: 7, bold: true, size: 11 });
    add(4, 1, name, { endCol: 7, bold: true, size: Math.min(11, Math.max(7, 630 / name.length)), border: 'bottom' });
    add(5, 1, '(NAME)', { endCol: 7, size: 9 });
    add(7, 1, 'For the month of', { endCol: 3, align: 'left' });
    add(7, 4, dtr.cutoff.label, { endCol: 7, bold: true, border: 'bottom' });
    add(8, 1, 'Official hours for arrival', { endCol: 3, align: 'left' });
    add(9, 1, 'and departure', { endCol: 3 });
    add(8, 4, 'Regular days', { endCol: 5, align: 'left' });
    add(9, 4, 'Saturdays', { endCol: 5, align: 'left' });
    add(8, 6, '', { endCol: 7, border: 'bottom' });
    add(9, 6, '', { endCol: 7, border: 'bottom' });
    add(11, 1, 'Day', { endRow: 12, bold: true, border: 'box' });
    add(11, 2, 'AM', { endCol: 3, bold: true, border: 'box' });
    add(11, 4, 'PM', { endCol: 5, bold: true, border: 'box' });
    add(11, 6, 'UNDER\nTIME', { endCol: 7, bold: true, border: 'box', size: 8.5 });
    ['Arrival', 'Departure', 'Arrival', 'Departure', 'Hours', 'Minutes'].forEach((text, i) => add(12, i + 2, text, { bold: true, border: 'box', size: 8 }));
    for (const day of dtr.form_rows) {
        const row = day.day + 12;
        add(row, 0, scheduleRange(day.schedule_label), { size: 8, align: 'right', padding: 2 });
        add(row, 1, day.day, { border: 'box' });
        ['am_arrival', 'am_departure', 'pm_arrival', 'pm_departure'].forEach((field, i) => add(row, i + 2, timeValue(day[field]), { text: formTime(day[field]), format: 'h:mm', border: 'box' }));
        add(row, 6, day.undertime_hours > 0 ? day.undertime_hours : null, { border: 'box', format: '0' });
        add(row, 7, day.undertime_minutes, { border: 'box', format: '0' });
        const hours = day.margin_hours == null ? null : Number(Number(day.margin_hours).toFixed(2));
        const wholeHours = Number.isInteger(hours);
        add(row, 8, day.margin_hours, { format: wholeHours ? '0' : '0.00', text: hours == null ? '' : hours.toFixed(wholeHours ? 0 : 2), align: 'left', padding: 2 });
    }
    for (let col = 1; col <= 7; col++) add(44, col, '', { border: 'box' });
    [
        'I CERTIFY on my honor that above is a true and correct',
        'report of the hours of work performed, record of which was',
        'made daily at the time of arrival at and departure from office.',
    ].forEach((text, i) => add(46 + i, 1, text, { endCol: 7, size: 8.5 }));
    add(49, 1, '', { endCol: 7, border: 'bottom' });
    add(50, 1, 'Verified as to the prescribed office hours:', { endCol: 7, bold: true, size: 8.5 });
    add(52, 1, dtr.signatory?.name || '', { endCol: 7, bold: true, size: 10, border: 'bottom' });
    add(53, 1, 'In Charge', { endCol: 7, size: 9 });
    return cells;
}

export function cellBounds(cell) {
    const x = FORM48.columns[cell.col], y = FORM48.rows[cell.row];
    return { x, y, width: FORM48.columns[(cell.endCol ?? cell.col) + 1] - x, height: FORM48.rows[(cell.endRow ?? cell.row) + 1] - y };
}

export function cellText(cell) {
    const bounds = cellBounds(cell);
    const lines = String(cell.text ?? cell.value ?? '').split('\n');
    const lineHeight = cell.size * 25.4 / 72 * 1.05;
    return lines.map((text, index) => ({ text,
        x: cell.align === 'left' ? bounds.x + (cell.padding ?? 0.5) : cell.align === 'right' ? bounds.x + bounds.width - (cell.padding ?? 0.5) : bounds.x + bounds.width / 2,
        y: bounds.y + bounds.height / 2 + cell.size * 25.4 / 72 * 0.34 + (index - (lines.length - 1) / 2) * lineHeight,
    }));
}
