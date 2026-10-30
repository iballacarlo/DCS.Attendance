import { formatDate } from './format.js';
export const attendanceStatuses = [
    { key: 'complete', label: 'Complete records', short: 'Complete', description: 'Both IN and OUT recorded', tone: 'complete' },
    { key: 'incomplete', label: 'For review', short: 'For review', description: 'Attendance needs review after the class has ended', tone: 'incomplete' },
    { key: 'missed', label: 'Absent', short: 'Absent', description: 'No attendance record for a finished class', tone: 'missed' },
];

export const sessionStatusLabels = { on_time: 'On time & full class', late: 'Late', undertime: 'Undertime', late_undertime: 'Late & undertime', missed: 'Absent', incomplete: 'For review', complete: 'Complete IN/OUT', upcoming: 'Not started', in_progress: 'Class in progress', excluded: 'Not required' };

export function matchesSessionStatus(row, status) {
    if (!status) return true;
    if (['late', 'undertime', 'on_time', 'late_undertime'].includes(status)) {
        if (!row.ended || row.status !== 'complete') return false;
        if (status === 'late_undertime') return Number(row.late_minutes) > 0 && Number(row.early_departure_minutes) > 0;
        if (status === 'late') return Number(row.late_minutes) > 0;
        if (status === 'undertime') return Number(row.early_departure_minutes) > 0;
        return row.status === 'complete' && row.late_minutes === 0 && row.early_departure_minutes === 0;
    }
    return row.status === status && (!['complete', 'incomplete', 'missed'].includes(status) || row.ended);
}

export function sessionIndicators(row) {
    if (['excluded', 'missed', 'upcoming', 'in_progress'].includes(row.status)) return [{ key: row.status, label: sessionStatusLabels[row.status] }];
    const indicators = [];
    if (row.status === 'incomplete') return [{ key: row.ended ? 'incomplete' : 'in_progress', label: row.ended ? 'For review' : sessionStatusLabels.in_progress }];
    if (Number(row.late_minutes) > 0) indicators.push({ key: 'late', label: `Late · ${row.late_minutes} min` });
    if (Number(row.early_departure_minutes) > 0) indicators.push({ key: 'undertime', label: `Undertime · ${row.early_departure_minutes} min` });
    if (!indicators.length) {
        const onTime = matchesSessionStatus(row, 'on_time');
        indicators.push({ key: onTime ? 'on_time' : 'complete', label: onTime ? 'On time & full class' : 'Complete IN/OUT' });
    }
    return indicators;
}

// A status drilldown exports all matching sessions, including every table page.
export function classReportView(report, status = '') {
    if (!status) return report;
    const rows = report.rows.filter((row) => matchesSessionStatus(row, status));
    const summarize = (items) => {
        const eligible = items.filter((row) => row.status !== 'excluded');
        const ended = eligible.filter((row) => row.ended);
        const complete = ended.filter((row) => row.status === 'complete').length;
        return { expected: eligible.length, ended: ended.length, complete,
            incomplete: ended.filter((row) => row.status === 'incomplete').length,
            missed: ended.filter((row) => row.status === 'missed').length,
            on_time: ended.filter((row) => matchesSessionStatus(row, 'on_time')).length,
            late: ended.filter((row) => matchesSessionStatus(row, 'late')).length,
            undertime: ended.filter((row) => matchesSessionStatus(row, 'undertime')).length,
            total_late_minutes: ended.filter((row) => row.status === 'complete').reduce((total, row) => total + (Number(row.late_minutes) || 0), 0),
            total_undertime_minutes: ended.filter((row) => row.status === 'complete').reduce((total, row) => total + (Number(row.early_departure_minutes) || 0), 0),
            excluded: items.length - eligible.length, completion_rate: ended.length ? Math.round(complete / ended.length * 1000) / 10 : null };
    };
    const breakdown = (field) => {
        const groups = new Map();
        rows.forEach((row) => { const key = row[field]; if (!groups.has(key)) groups.set(key, []); groups.get(key).push(row); });
        return [...groups].map(([value, items]) => ({ value, label: field === 'employee_id' ? items[0].faculty : value || 'Unspecified', ...summarize(items) }));
    };
    return { ...report, filters: { ...report.filters, attendance_status: sessionStatusLabels[status] || status }, rows, summary: summarize(rows), trend: breakdown('date'), faculty_breakdown: breakdown('employee_id'), subject_breakdown: breakdown('subject') };
}

const dateFormat = (date, options) => new Intl.DateTimeFormat('en-PH', { timeZone: 'UTC', ...options }).format(date);
export const shortChartDate = (iso) => formatDate(iso);

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
    const filtered = rows.filter((row) => matchesSessionStatus(row, status));
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
