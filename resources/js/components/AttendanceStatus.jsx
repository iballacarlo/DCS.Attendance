import '../../css/attendanceStatus.css';

export const attendanceStatuses = [
    ['on_time', 'On time'], ['late', 'Late'], ['undertime', 'Undertime'],
    ['late_undertime', 'Late & undertime'], ['absent', 'Absent'], ['for_review', 'For review'],
];

export function AttendanceStatusFilter({ value = '', onChange }) {
    return <label>Status<select value={value} onChange={(event) => onChange(event.target.value)}>
        <option value="">All statuses</option>
        {attendanceStatuses.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
        {['present', 'complete', 'incomplete'].includes(value) && <option value={value}>{value === 'present' ? 'On time' : value === 'complete' ? 'Recorded IN/OUT' : 'Missing IN/OUT'}</option>}
    </select></label>;
}

export default function AttendanceStatus({ record }) {
    return <div className="attendance-status-cell">
        <span className={`attendance-status-badge is-${record.attendance_status}`}>{record.attendance_status_label}</span>
        {record.late_minutes_display > 0 && <small>Late: {record.late_minutes_display} min</small>}
        {record.undertime_minutes_display > 0 && <small>Undertime: {record.undertime_minutes_display} min</small>}
        {record.attendance_status === 'for_review' && <small>Missing or invalid IN/OUT</small>}
        {record.status_reason && <small>{record.status_reason}</small>}
    </div>;
}
