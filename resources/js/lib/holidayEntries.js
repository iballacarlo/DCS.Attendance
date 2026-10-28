export const holidayTypes = { suspension: 'Suspension', regular_holiday: 'Regular holiday', special_holiday: 'Special holiday' };
export const entryTypes = { ...holidayTypes, official_school_activity: 'School activity', asynchronous: 'Asynchronous day' };

export function holidayEntries(manual, regular, year, type = '', search = '') {
    const first = `${year}-01-01`;
    const last = `${year}-12-31`;
    const query = search.trim().toLowerCase();
    const automatic = regular.filter((holiday) => !manual.some((entry) => entry.type === 'regular_holiday' && entry.scope_type === 'campus'
        && entry.start_date.slice(0, 10) <= holiday.start_date.slice(0, 10) && entry.end_date.slice(0, 10) >= holiday.end_date.slice(0, 10)));
    return [...manual, ...automatic].filter((entry) => entry.start_date.slice(0, 10) <= last && entry.end_date.slice(0, 10) >= first
        && (!type || entry.type === type) && (!query || `${entry.title} ${entryTypes[entry.type] || ''} ${entry.department || ''} ${entry.remarks || ''}`.toLowerCase().includes(query)))
        .sort((a, b) => a.start_date.localeCompare(b.start_date) || a.title.localeCompare(b.title));
}
