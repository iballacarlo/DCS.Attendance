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
