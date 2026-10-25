export function sampleForm48({ year = 2026, month = 10, second = false } = {}) {
    const prefix = `${year}-${String(month).padStart(2, '0')}`;
    const days = new Date(Date.UTC(year, month, 0)).getUTCDate();
    const first = second ? 16 : 1, last = second ? days : 15;
    const form_rows = Array.from({ length: 31 }, (_, i) => ({
        day: i + 1, date: i < days ? `${prefix}-${String(i + 1).padStart(2, '0')}` : null,
        in_cutoff: i + 1 >= first && i + 1 <= last, valid_day: i < days,
        schedule_label: null, am_arrival: null, am_departure: null, pm_arrival: null, pm_departure: null,
        undertime_hours: null, undertime_minutes: null, margin_hours: null,
    }));
    Object.assign(form_rows[first + 1], { schedule_label: '8:00 AM-7:00 PM', am_arrival: '08:06', pm_departure: '19:07', undertime_hours: null, undertime_minutes: 30 });
    Object.assign(form_rows[first + 8], { schedule_label: '7:00 AM-7:00 PM', am_arrival: '07:00', pm_departure: '19:00', margin_hours: 6 });
    const date = form_rows[first + 1].date;
    return {
        employee: { first_name: 'Hazel Ann', middle_name: 'L', last_name: 'Gandara', employee_no: 'COS-DEMO' },
        cutoff: { start_date: `${prefix}-${String(first).padStart(2, '0')}`, end_date: `${prefix}-${last}`, label: `${new Date(Date.UTC(year, month - 1, 1)).toLocaleString('en-US', { month: 'long', timeZone: 'UTC' })} ${first}-${last}, ${year}` },
        form_rows, signatory: { name: 'DR. SAMPLE SIGNATORY' }, summary: { total_hours: 11.5 },
        rows: [{ date, time_in: '08:06', time_out: '19:07', payable_hours: 5.5, sessions: [
            { id: 1, subject: 'DCIT 21', section: 'BSIT 1A', class_mode: 'lecture', scheduled_start: '08:00', scheduled_end: '10:00', time_in: '08:06', time_out: '10:00', hours: 1.9, status: 'present' },
            { id: 2, subject: 'ITEC 55', section: 'BSIT 2A', class_mode: 'laboratory', scheduled_start: '10:00', scheduled_end: '12:00', time_in: '10:00', time_out: '12:00', hours: 2, status: 'present' },
            { id: 3, schedule_type: 'consultation', subject: null, section: null, class_mode: null, scheduled_start: '17:00', scheduled_end: '19:00', time_in: '17:24', time_out: '19:07', hours: 1.6, status: 'present' },
        ] }, { date: form_rows[first + 8].date, time_in: '07:00', time_out: '19:00', payable_hours: 6, sessions: [
            { id: 4, subject: 'DCIT 21', section: 'BSIT 1A', class_mode: 'lecture', scheduled_start: '07:00', scheduled_end: '09:00', time_in: '07:00', time_out: '09:00', hours: 2, status: 'present' },
            { id: 5, subject: 'ITEC 55', section: 'BSIT 2A', class_mode: 'laboratory', scheduled_start: '10:00', scheduled_end: '12:00', time_in: '10:00', time_out: '12:00', hours: 2, status: 'present' },
            { id: 6, subject: 'COSC 50', section: 'BSIT 3A', class_mode: 'lecture', scheduled_start: '17:00', scheduled_end: '19:00', time_in: '17:00', time_out: '19:00', hours: 2, status: 'present' },
        ] }],
    };
}
