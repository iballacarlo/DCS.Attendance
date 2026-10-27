export const attendanceStatuses = [
    { key: 'complete', label: 'Complete records', short: 'Complete', description: 'Both IN and OUT recorded', tone: 'complete' },
    { key: 'incomplete', label: 'Missing IN or OUT', short: 'Incomplete', description: 'A tap is missing from the pair', tone: 'incomplete' },
    { key: 'missed', label: 'No recorded taps', short: 'No taps', description: 'No attendance record for a finished class', tone: 'missed' },
];

const dateFormat = (date, options) => new Intl.DateTimeFormat('en-PH', { timeZone: 'UTC', ...options }).format(date);
export const shortChartDate = (iso) => dateFormat(new Date(`${iso}T00:00:00Z`), { month: 'short', day: 'numeric' });

// Long date ranges use calendar buckets so the trend remains readable.
// Combine counts before computing percentages; never average daily rates.
export function attendanceTrend(trend) {
    const days = [...trend].sort((a, b) => a.label.localeCompare(b.label));
    if (!days.length) return { unit: 'day', rows: [] };
    const span = (Date.parse(days.at(-1).label) - Date.parse(days[0].label)) / 86400000 + 1;
    const unit = span <= 14 ? 'day' : span <= 90 ? 'week' : 'month';
    const buckets = new Map();
    for (const day of days) {
        const date = new Date(`${day.label}T00:00:00Z`);
        if (unit === 'week') date.setUTCDate(date.getUTCDate() - (date.getUTCDay() + 6) % 7);
        const key = unit === 'month' ? day.label.slice(0, 7) : date.toISOString().slice(0, 10);
        if (!buckets.has(key)) buckets.set(key, { key, start_date: day.label, end_date: day.label, complete: 0, incomplete: 0, missed: 0, ended: 0 });
        const bucket = buckets.get(key);
        bucket.end_date = day.label;
        for (const field of ['complete', 'incomplete', 'missed', 'ended']) bucket[field] += Number(day[field]) || 0;
    }
    return { unit, rows: [...buckets.values()].map((row) => ({ ...row,
        label: unit === 'month' ? dateFormat(new Date(`${row.key}-01T00:00:00Z`), { month: 'short', year: 'numeric' }) : shortChartDate(row.key),
    })) };
}

export function sessionPage(rows, page = 1, perPage = 10, status = '') {
    const filtered = status ? rows.filter((row) => row.status === status && (!['complete', 'incomplete', 'missed'].includes(status) || row.ended)) : rows;
    const size = [10, 25, 50].includes(Number(perPage)) ? Number(perPage) : 10;
    const pages = Math.max(1, Math.ceil(filtered.length / size));
    const current = Math.max(1, Math.min(Number(page) || 1, pages));
    const start = (current - 1) * size;
    return { rows: filtered.slice(start, start + size), current, pages, total: filtered.length, from: filtered.length ? start + 1 : 0, to: Math.min(start + size, filtered.length) };
}

export function attendanceOverview(summary) {
    const due = Number(summary.ended) || 0;
    const complete = Number(summary.complete) || 0;
    return {
        due, complete,
        needsReview: (Number(summary.incomplete) || 0) + (Number(summary.missed) || 0),
        notYetFinished: Math.max(0, (Number(summary.expected) || 0) - due),
        rate: due ? Math.round(complete / due * 1000) / 10 : null,
    };
}
