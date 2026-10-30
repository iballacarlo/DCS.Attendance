import { formatDate } from './format.js';

export function displayInputDate(value, withTime = false) {
    if (!value) return '';
    return formatDate(value) + (withTime ? ` ${String(value).slice(11, 16)}` : '');
}

export function parseInputDate(text, withTime = false) {
    if (!text) return '';
    const match = (withTime ? /^(\d{2})\/(\d{2})\/(\d{4}) (\d{2}):(\d{2})$/ : /^(\d{2})\/(\d{2})\/(\d{4})$/).exec(text);
    if (!match) return null;
    const [, month, day, year, hour, minute] = match;
    const date = new Date(`${year}-${month}-${day}T12:00:00Z`);
    if (Number.isNaN(date.getTime()) || date.getUTCFullYear() !== Number(year)
        || date.getUTCMonth() + 1 !== Number(month) || date.getUTCDate() !== Number(day)
        || (withTime && (Number(hour) > 23 || Number(minute) > 59))) return null;
    return `${year}-${month}-${day}${withTime ? `T${hour}:${minute}` : ''}`;
}

export function inputDateError(text, min, max, withTime = false) {
    const iso = parseInputDate(text, withTime);
    if (iso === '' || (iso && (!min || iso >= min) && (!max || iso <= max))) return '';
    return `Enter a valid date in ${withTime ? 'MM/DD/YYYY HH:mm (24-hour)' : 'MM/DD/YYYY'} format${min ? ` on or after ${displayInputDate(min, withTime)}` : ''}${max ? ` on or before ${displayInputDate(max, withTime)}` : ''}.`;
}
