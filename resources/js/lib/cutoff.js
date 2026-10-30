import { dateToday } from './format.js';

export const cutoffMonths = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
export function cutoffPeriod(start) {
    const [year, month, day] = String(start).split('-').map(Number);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(start) || year < 2000 || year > 2100 || month < 1 || month > 12 || ![1, 16].includes(day)) return null;
    const endDay = day === 1 ? 15 : new Date(Date.UTC(year, month, 0)).getUTCDate();
    const end = `${start.slice(0, 8)}${endDay}`;
    const display = (date) => `${date.slice(5, 7)}/${date.slice(8, 10)}/${date.slice(0, 4)}`;
    return { start, end, label: `${display(start)} - ${display(end)}` };
}
export const currentCutoff = () => { const today = dateToday(); return cutoffPeriod(`${today.slice(0, 8)}${Number(today.slice(8)) <= 15 ? '01' : '16'}`); };

export function semesterCutoffs(term) {
    if (!term?.start_date || !term?.end_date) return [];
    const first = term.start_date.slice(0, 10), last = term.end_date.slice(0, 10);
    const [startYear, startMonth] = first.split('-').map(Number);
    const [endYear, endMonth] = last.split('-').map(Number);
    const periods = [];
    for (let monthIndex = startYear * 12 + startMonth - 1; monthIndex <= endYear * 12 + endMonth - 1; monthIndex++) {
        const year = Math.floor(monthIndex / 12), month = String(monthIndex % 12 + 1).padStart(2, '0');
        for (const day of ['01', '16']) {
            const period = cutoffPeriod(`${year}-${month}-${day}`);
            if (period && period.end >= first && period.start <= last) periods.push(period);
        }
    }
    return periods;
}

export function constrainCutoff(value, term) {
    const period = cutoffPeriod(value) || currentCutoff();
    const choices = semesterCutoffs(term);
    if (!choices.length) return period;
    return choices.find((choice) => choice.start === period.start)
        || (period.start < choices[0].start ? choices[0] : choices.at(-1));
}
