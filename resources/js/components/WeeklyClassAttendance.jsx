import { useEffect, useMemo, useState } from 'react';
import { CalendarDays, ChevronLeft, ChevronRight } from 'lucide-react';
import { formatDate, time12 } from '../lib/format';
import { plottingDays } from '../lib/weeklyPlotting';

export const weeklyStatusKey = (row) => row.status === 'missed'
    ? 'absent'
    : row.status === 'incomplete'
        ? 'review'
        : +row.late_minutes > 0 && +row.early_departure_minutes > 0
            ? 'late-undertime'
            : +row.late_minutes > 0
                ? 'late'
                : +row.early_departure_minutes > 0
                    ? 'undertime'
                    : row.status === 'complete'
                        ? 'on-time'
                        : 'upcoming';

const labels = {
    'on-time': 'On time & full class',
    late: 'Late',
    undertime: 'Undertime',
    'late-undertime': 'Late & undertime',
    absent: 'Absent',
    review: 'For review',
    upcoming: 'Upcoming',
};

const dayIndex = (date, weekStart) => Math.round((new Date(`${date}T00:00:00`) - new Date(`${weekStart}T00:00:00`)) / 86400000);
const attendanceTime = (value) => value ? time12(value) : '—';

export default function WeeklyClassAttendance({ rows = [], weekStart, moveWeek, onWeekChange, admin = false, onCorrect }) {
    const [selectedKey, setSelectedKey] = useState(null);
    const classRows = useMemo(() => rows.filter((row) => !row.schedule_type || row.schedule_type === 'class'), [rows]);
    const selected = classRows.find((row) => row.key === selectedKey) || null;
    const days = useMemo(() => plottingDays.map((name, index) => ({
            name,
            rows: classRows
                .filter((row) => dayIndex(row.date, weekStart) === index)
                .sort((first, second) => first.scheduled_start.localeCompare(second.scheduled_start)),
        })).filter((day) => day.rows.length).map((day) => ({
            ...day,
            rangeStart: day.rows[0].scheduled_start,
            rangeEnd: day.rows.reduce((latest, row) => row.scheduled_end > latest ? row.scheduled_end : latest, day.rows[0].scheduled_end),
        })), [classRows, weekStart]);

    useEffect(() => {
        if (selectedKey && !classRows.some((row) => row.key === selectedKey)) setSelectedKey(null);
    }, [classRows, selectedKey]);

    return <section className="panel weekly-attendance-panel">
        <div className="panel-heading">
            <div><h2>Weekly class attendance</h2><p>{formatDate(weekStart)} – {formatDate(moveWeek(weekStart, 5))}</p></div>
            <div className="week-nav" role="group" aria-label="Change attendance week">
                <button className="week-nav-icon" type="button" aria-label="Previous week" title="Previous week" onClick={() => onWeekChange(moveWeek(weekStart, -7))}><ChevronLeft size={20} /></button>
                <button className="week-nav-icon" type="button" aria-label="Current week" title="Current week" onClick={() => onWeekChange(moveWeek(new Date().toISOString().slice(0, 10), 0))}><CalendarDays size={18} /></button>
                <button className="week-nav-icon" type="button" aria-label="Next week" title="Next week" onClick={() => onWeekChange(moveWeek(weekStart, 7))}><ChevronRight size={20} /></button>
            </div>
        </div>
        {days.length ? <div className="weekly-plot-attendance-scroll" tabIndex="0" aria-label="Weekly class attendance plotting; scroll horizontally on smaller screens">
            <table className="weekly-plot-attendance">
                <colgroup>{days.map((day) => <col key={day.name} />)}</colgroup>
                <thead><tr>{days.map((day) => <th className="weekly-day-header-cell" key={day.name}><strong>{day.name}</strong><small>{formatDate(day.rows[0].date)}</small><span>{time12(day.rangeStart)} – {time12(day.rangeEnd)}</span></th>)}</tr></thead>
                <tbody><tr>{days.map((day) => <td className="weekly-plot-day" key={day.name}>
                    <div className="weekly-plot-day-blocks">{day.rows.map((row) => {
                        const status = weeklyStatusKey(row);
                        const visualStatus = row.school_attendance_warning ? 'no-school' : status;
                        const active = row.key === selectedKey;
                        return <button type="button" className={`weekly-plot-session status-${visualStatus}${active ? ' is-selected' : ''}`} aria-expanded={active} onClick={() => setSelectedKey(active ? null : row.key)} key={row.key}>
                            <span className="weekly-session-heading"><strong>{row.subject}</strong><span className="weekly-block-status">{labels[status]}</span></span>
                            <span className="weekly-session-location"><strong>{row.section}</strong><span>{row.room || 'Room not assigned'}</span></span>
                            <span>{row.class_mode === 'laboratory' ? 'Laboratory' : 'Lecture'} · <strong>{time12(row.scheduled_start)} – {time12(row.scheduled_end)}</strong></span>
                            <span className="weekly-session-taps"><span>IN <strong>{attendanceTime(row.time_in)}</strong></span><span>OUT <strong>{attendanceTime(row.time_out)}</strong></span></span>
                            <span className="weekly-session-minutes"><span>Late: {row.late_minutes ?? 0} min</span><span>Undertime: {row.early_departure_minutes ?? 0} min</span></span>
                            {row.school_attendance_warning && <span className="weekly-school-warning">No School IN</span>}
                        </button>;
                    })}</div>
                </td>)}</tr></tbody>
            </table>
        </div> : <div className="weekly-empty-state"><strong>No scheduled classes this week</strong><p>Use Previous or Next to view another week.</p></div>}
        {selected && <div className="weekly-session-details" aria-live="polite">
            <div><small>Selected session</small><h3>{selected.subject} · {selected.section}</h3><p>{selected.faculty} · {selected.room || 'Room not assigned'} · {formatDate(selected.date)}</p></div>
            <dl>
                <div><dt>Schedule</dt><dd>{time12(selected.scheduled_start)} – {time12(selected.scheduled_end)}</dd></div>
                <div><dt>Actual IN</dt><dd>{attendanceTime(selected.time_in)}</dd></div>
                <div><dt>Actual OUT</dt><dd>{attendanceTime(selected.time_out)}</dd></div>
                <div><dt>Late</dt><dd>{selected.late_minutes ?? 0} min</dd></div>
                <div><dt>Undertime</dt><dd>{selected.early_departure_minutes ?? 0} min</dd></div>
                <div><dt>Status</dt><dd>{labels[weeklyStatusKey(selected)]}</dd></div>
            </dl>
            {selected.school_attendance_warning && <p className="school-warning">Warning: No School IN was recorded before this class tap.</p>}
            {admin && selected.log_id && selected.time_in && !selected.time_out && <button onClick={() => onCorrect({ id: selected.log_id, employee_name: selected.faculty, subject: selected.subject, room_code: selected.room })}>Correct missing OUT</button>}
        </div>}
    </section>;
}
