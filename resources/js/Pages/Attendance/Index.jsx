import { Link, useForm, usePage } from '@inertiajs/react';
import { CalendarDays, CalendarRange, CheckCircle2, Clock3, FileDown, LogIn, LogOut, Radio, Search, TimerReset, Users, X } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Pagination from '../../components/Pagination';
import useDebouncedFilters from '../../hooks/useDebouncedFilters';
import useRealtimeReload from '../../hooks/useRealtimeReload';
import { dateToday, fullName, time12 } from '../../lib/format';

function readableDate(value, options = {}) {
    if (!value) return '-';
    return new Date(`${String(value).slice(0, 10)}T00:00:00`).toLocaleDateString('en-PH', {
        month: 'short', day: 'numeric', year: 'numeric', ...options,
    });
}

function methodLabel(value) {
    if (!value) return '-';
    return value === 'rfid' ? 'RFID' : value === 'fingerprint' ? 'Fingerprint' : 'Manual';
}

function statusLabel(log) {
    if (log?.calendar_event) return `Paid day · ${log.calendar_event.label}`;
    if (!log?.time_in) return 'Not timed in';
    if (!log?.time_out) return 'Currently timed in';
    return log.status === 'present' ? 'Complete' : log.status === 'late' ? 'Late' : log.status === 'undertime' ? 'Undertime' : 'Incomplete';
}

function statusClass(log) {
    if (log?.calendar_event) return 'is-complete';
    if (!log?.time_in) return 'is-pending';
    if (!log?.time_out) return 'is-active';
    return ['late', 'undertime', 'incomplete'].includes(log.status) ? 'is-warning' : 'is-complete';
}

function testCutoffOptions() {
    const today = new Date(`${dateToday()}T00:00:00`);
    const options = [];
    for (let offset = 0; offset < 24; offset += 1) {
        const month = new Date(today.getFullYear(), today.getMonth() - offset, 1);
        const year = month.getFullYear();
        const monthIndex = month.getMonth();
        const monthNumber = String(monthIndex + 1).padStart(2, '0');
        const monthName = month.toLocaleDateString('en-PH', { month: 'long' });
        const lastDay = new Date(year, monthIndex + 1, 0).getDate();
        options.push({ value: `${year}-${monthNumber}-16|${year}-${monthNumber}-${lastDay}`, label: `${monthName} 16-${lastDay}, ${year}` });
        options.push({ value: `${year}-${monthNumber}-01|${year}-${monthNumber}-15`, label: `${monthName} 1-15, ${year}` });
    }
    return options;
}

function FacultyDtr({ dtr, logs }) {
    const today = dtr?.today_log;
    const summary = dtr?.summary || {};

    return <div className="faculty-dtr">
        <div className="dtr-page-actions"><Link className="button" href="/attendance/dtr"><FileDown size={17} />Preview / Download DTR</Link></div>
        <section className="dtr-today" aria-label="Today attendance status">
            <div className="dtr-today-heading">
                <div><span>Today</span><h3>{readableDate(dtr?.date, { weekday: 'long' })}</h3></div>
                <strong className={`dtr-state ${statusClass(today)}`}>{today?.time_out ? <CheckCircle2 size={17} /> : <Radio size={17} />}{statusLabel(today)}</strong>
            </div>
            <div className="dtr-time-grid">
                <div><span className="dtr-time-icon is-in"><LogIn size={20} /></span><p>Time In</p><strong>{time12(today?.time_in)}</strong><small>{methodLabel(today?.method_in)}</small></div>
                <div><span className="dtr-time-icon is-out"><LogOut size={20} /></span><p>Time Out</p><strong>{time12(today?.time_out)}</strong><small>{methodLabel(today?.method_out)}</small></div>
                <div><span className="dtr-time-icon is-hours"><Clock3 size={20} /></span><p>Payable Hours</p><strong>{Number(today?.total_hours || 0).toFixed(2)}</strong><small>Computed from schedule</small></div>
                <div><span className="dtr-time-icon is-variance"><TimerReset size={20} /></span><p>Late / Undertime</p><strong>{today?.late_minutes || 0} / {today?.undertime_minutes || 0}</strong><small>Minutes</small></div>
            </div>
            <div className="dtr-schedule-strip">
                <span><CalendarDays size={16} />Today's assigned schedule</span>
                <div>{dtr?.today_schedules?.length ? dtr.today_schedules.map((schedule) => <strong key={schedule.id}>{time12(schedule.start_time)} - {time12(schedule.end_time)}<small>{schedule.type}</small></strong>) : <em>No assigned schedule today</em>}</div>
            </div>
        </section>

        <section className="dtr-cutoff-section">
            <div className="dtr-section-heading"><div><span>Current cutoff</span><h3>{readableDate(dtr?.cutoff?.start_date)} - {readableDate(dtr?.cutoff?.end_date)}</h3></div><CalendarRange size={23} /></div>
            <div className="dtr-summary-grid">
                <div><span>Payable hours</span><strong>{Number(summary.total_hours || 0).toFixed(2)}</strong></div>
                <div><span>Days present</span><strong>{summary.days_present || 0}<small> / {summary.scheduled_days || 0}</small></strong></div>
                <div><span>Absent days</span><strong>{summary.absent_days || 0}</strong></div>
                <div><span>Late minutes</span><strong>{summary.late_minutes || 0}</strong></div>
                <div><span>Undertime minutes</span><strong>{summary.undertime_minutes || 0}</strong></div>
            </div>
        </section>

        <section className="dtr-history">
            <div className="dtr-section-heading"><div><span>Attendance history</span><h3>My Daily Time Record</h3></div><small>Newest records appear first</small></div>
            <div className="table-wrap"><table className="dtr-table"><thead><tr><th>Date</th><th>Time In</th><th>Time Out</th><th>Method</th><th>Hours</th><th>Late</th><th>Undertime</th><th>Status</th></tr></thead><tbody>{logs.data.length ? logs.data.map((log) => <tr key={log.id}><td><strong>{readableDate(log.attendance_date, { weekday: 'short' })}</strong></td><td>{time12(log.time_in)}</td><td>{time12(log.time_out)}</td><td>{methodLabel(log.method_in)} / {methodLabel(log.method_out)}</td><td>{Number(log.total_hours || 0).toFixed(2)}</td><td>{log.late_minutes} min</td><td>{log.undertime_minutes} min</td><td><span className={`dtr-table-status ${statusClass(log)}`}>{statusLabel(log)}</span></td></tr>) : <tr><td colSpan="8" className="empty-row">No attendance records yet.</td></tr>}</tbody></table></div>
            <Pagination links={logs.links} />
        </section>
    </div>;
}

function StaffAttendance({ employees, logs, filters, canManage }) {
    const firstEmployee = employees[0]?.id || '';
    const cutoffOptions = testCutoffOptions();
    const today = dateToday();
    const currentCutoff = cutoffOptions.find((option) => {
        const [start, end] = option.value.split('|');
        return today >= start && today <= end;
    }) || cutoffOptions[0];
    const [defaultStart, defaultEnd] = currentCutoff.value.split('|');
    const tap = useForm({ identifier: '', method: 'rfid' });
    const manual = useForm({ employee_id: firstEmployee, attendance_date: dateToday(), time_in: '', time_out: '', remarks: '' });
    const testLogs = useForm({ target_mode: 'selected', employee_ids: firstEmployee ? [String(firstEmployee)] : [], start_date: defaultStart, end_date: defaultEnd });
    const [facultyPickerSearch, setFacultyPickerSearch] = useState('');
    const [listFilters, setListFilters] = useState({
        search: filters.search || '',
        date_from: filters.date_from || '',
        date_to: filters.date_to || '',
        status: filters.status || '',
        method: filters.method || '',
    });
    useDebouncedFilters('/attendance', listFilters);
    const changeFilter = (key, value) => setListFilters((current) => {
        const next = { ...current, [key]: value };
        if (key === 'date_from' && next.date_to && value > next.date_to) next.date_to = '';
        return next;
    });
    const hasFilters = Object.values(listFilters).some(Boolean);
    const visibleFaculty = employees.filter((employee) => `${fullName(employee)} ${employee.employee_no}`.toLowerCase().includes(facultyPickerSearch.toLowerCase()));
    const toggleTestFaculty = (employeeId) => {
        const id = String(employeeId);
        testLogs.setData('employee_ids', testLogs.data.employee_ids.includes(id)
            ? testLogs.data.employee_ids.filter((value) => value !== id)
            : [...testLogs.data.employee_ids, id]);
    };

    return <>
        <div className="dtr-page-actions"><Link className="button" href="/attendance/dtr"><FileDown size={17} />Generate Faculty DTR</Link></div>
        {canManage && <section className="content-grid">
            <form className="panel compact-form" onSubmit={(e) => { e.preventDefault(); tap.post('/attendance/tap'); }}><h2>RFID / Fingerprint Tap</h2><label>Identifier<input placeholder="Scan RFID UID or fingerprint code" value={tap.data.identifier} onChange={(e) => tap.setData('identifier', e.target.value)} required autoFocus /></label><label>Method<select value={tap.data.method} onChange={(e) => tap.setData('method', e.target.value)}><option value="rfid">RFID</option><option value="fingerprint">Fingerprint</option></select></label><button type="submit" disabled={tap.processing}>Record Tap</button></form>
            <form className="panel compact-form" onSubmit={(e) => { e.preventDefault(); manual.post('/attendance/manual'); }}><h2>Manual Attendance</h2><label>Faculty<select value={manual.data.employee_id} onChange={(e) => manual.setData('employee_id', e.target.value)} required>{employees.map((employee) => <option key={employee.id} value={employee.id}>{fullName(employee)}</option>)}</select></label><label>Date<input type="date" value={manual.data.attendance_date} onChange={(e) => manual.setData('attendance_date', e.target.value)} required /></label><label>Time In<input type="time" value={manual.data.time_in} onChange={(e) => manual.setData('time_in', e.target.value)} /></label><label>Time Out<input type="time" value={manual.data.time_out} onChange={(e) => manual.setData('time_out', e.target.value)} /></label><label>Remarks<input value={manual.data.remarks} onChange={(e) => manual.setData('remarks', e.target.value)} /></label><button type="submit" disabled={manual.processing}>Save Manual Log</button></form>
        </section>}
        {canManage && <section className="panel test-attendance-panel random-attendance-panel">
            <div className="test-attendance-intro"><span className="test-attendance-icon"><Users size={20} /></span><div><h2>Generate Random Test Attendance</h2><p>Creates different outcomes for every selected faculty member. Existing manual, RFID, and fingerprint logs in the cutoff will be replaced; holidays, calendar events, and approved leave remain protected.</p></div></div>
            <form className="test-attendance-form random-attendance-form" onSubmit={(e) => { e.preventDefault(); testLogs.post('/attendance/generate-test'); }}>
                <div className="test-target-control"><span>Generate for</span><div className="test-target-segments"><button type="button" className={testLogs.data.target_mode === 'all' ? 'active' : ''} onClick={() => testLogs.setData('target_mode', 'all')}>All faculty</button><button type="button" className={testLogs.data.target_mode === 'selected' ? 'active' : ''} onClick={() => testLogs.setData('target_mode', 'selected')}>Selected faculty</button></div></div>
                <label>Cutoff Period<select value={`${testLogs.data.start_date}|${testLogs.data.end_date}`} onChange={(e) => { const [start_date, end_date] = e.target.value.split('|'); testLogs.setData({ ...testLogs.data, start_date, end_date }); }}>{cutoffOptions.map((option) => <option value={option.value} key={option.value}>{option.label}</option>)}</select></label>
                {testLogs.data.target_mode === 'selected' && <div className="test-faculty-picker"><div className="test-faculty-picker-heading"><strong>Select faculty</strong><span>{testLogs.data.employee_ids.length} selected</span></div><span className="test-faculty-search"><Search size={15} /><input type="search" value={facultyPickerSearch} onChange={(event) => setFacultyPickerSearch(event.target.value)} placeholder="Search name or employee number" /></span><div className="test-faculty-options">{visibleFaculty.map((employee) => <label key={employee.id}><input type="checkbox" checked={testLogs.data.employee_ids.includes(String(employee.id))} onChange={() => toggleTestFaculty(employee.id)} /><span><strong>{fullName(employee)}</strong><small>{employee.employee_no}</small></span></label>)}</div>{testLogs.errors.employee_ids && <small className="field-error">{testLogs.errors.employee_ids}</small>}</div>}
                <button type="submit" disabled={testLogs.processing || (testLogs.data.target_mode === 'selected' && testLogs.data.employee_ids.length === 0)}>{testLogs.processing ? 'Generating...' : `Generate for ${testLogs.data.target_mode === 'all' ? 'All Faculty' : `${testLogs.data.employee_ids.length} Selected`}`}</button>
            </form>
        </section>}
        <div className="panel"><div className="panel-heading"><h2>Attendance Logs</h2></div><div className="list-filters attendance-log-filters"><label className="filter-search">Search Faculty<span className="search-control"><Search size={16} /><input placeholder="Name, employee number, or email" value={listFilters.search} onChange={(event) => changeFilter('search', event.target.value)} /></span></label><label>From<input type="date" value={listFilters.date_from} onChange={(event) => changeFilter('date_from', event.target.value)} /></label><label>To<input type="date" min={listFilters.date_from || undefined} value={listFilters.date_to} onChange={(event) => changeFilter('date_to', event.target.value)} /></label><label>Status<select value={listFilters.status} onChange={(event) => changeFilter('status', event.target.value)}><option value="">All statuses</option><option value="present">Present</option><option value="late">Late</option><option value="undertime">Undertime</option><option value="incomplete">Incomplete</option><option value="absent">Absent</option></select></label><label>Method<select value={listFilters.method} onChange={(event) => changeFilter('method', event.target.value)}><option value="">All methods</option><option value="rfid">RFID</option><option value="fingerprint">Fingerprint</option><option value="manual">Manual</option></select></label>{hasFilters && <button className="clear-filters" type="button" onClick={() => setListFilters({ search: '', date_from: '', date_to: '', status: '', method: '' })}><X size={15} />Clear</button>}</div><div className="table-wrap"><table><thead><tr><th>Faculty</th><th>Date</th><th>Time In</th><th>Time Out</th><th>Method</th><th>Hours</th><th>Late</th><th>Undertime</th><th>Status</th></tr></thead><tbody>{logs.data.length ? logs.data.map((log) => <tr key={log.id}><td>{fullName(log.employee)}</td><td>{log.attendance_date}</td><td>{time12(log.time_in)}</td><td>{time12(log.time_out)}</td><td>{methodLabel(log.method_in)} / {methodLabel(log.method_out)}</td><td>{log.total_hours}</td><td>{log.late_minutes} min</td><td>{log.undertime_minutes} min</td><td><span className="badge">{log.status}</span></td></tr>) : <tr><td colSpan="9">No attendance logs match the selected filters.</td></tr>}</tbody></table></div><Pagination links={logs.links} /></div>
    </>;
}

export default function AttendanceIndex({ employees, logs, dtr, filters = {} }) {
    const { auth } = usePage().props;
    const isFaculty = auth.user.role === 'faculty';
    const canManage = auth.user.role === 'payroll_staff';
    useRealtimeReload(isFaculty ? ['logs', 'dtr'] : ['logs'], 5000);
    return <AppLayout title="Attendance" subtitle={isFaculty ? 'Review your time records and current cutoff totals.' : canManage ? 'Review and manage faculty attendance records.' : 'Review faculty attendance records.'}>
        {isFaculty ? <FacultyDtr dtr={dtr} logs={logs} /> : <StaffAttendance employees={employees} logs={logs} filters={filters} canManage={canManage} />}
    </AppLayout>;
}
