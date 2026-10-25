import CutoffPicker from '../../components/CutoffPicker';
import FacultyCombobox from '../../components/FacultyCombobox';
import { Link, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import Pagination from '../../components/Pagination';
import useDebouncedFilters from '../../hooks/useDebouncedFilters';
import useRealtimeReload from '../../hooks/useRealtimeReload';
import { formatDate, fullName, time12 } from '../../lib/format';

const Errors = ({ errors }) => Object.entries(errors).map(([key, message]) => <p key={key} className="field-error" role="alert">{message}</p>);

export default function AttendanceIndex({ employees, logs, dtr, filters }) {
    const { auth, facultyChoices } = usePage().props;
    const faculty = auth.user.role === 'faculty';
    const canGenerate = auth.user.role === 'payroll_staff';
    const [listFilters, setListFilters] = useState(filters);
    useDebouncedFilters('/attendance', listFilters);
    useRealtimeReload(faculty ? ['logs', 'dtr'] : ['logs'], 5000);
    const change = (key, value) => setListFilters((f) => ({ ...f, [key]: value }));
    const cutoffEnd = dtr?.cutoff.end_date || (() => { const d = new Date(`${filters.cutoff_start}T12:00:00`); return filters.cutoff_start.slice(0, 8) + (d.getDate() === 1 ? '15' : String(new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate())); })();
    const test = useForm({ target_mode: 'selected', employee_ids: employees[0] ? [String(employees[0].id)] : [], generation_mode: 'fill_missing', start_date: filters.cutoff_start, end_date: cutoffEnd });
    const summary = dtr?.summary;
    return <AppLayout title="Attendance" subtitle="Class attendance and actual worked hours per cutoff.">
        <div className="panel attendance-toolbar"><div className="attendance-filter-fields">
            <CutoffPicker value={listFilters.cutoff_start} onChange={(period) => change('cutoff_start', period.start)} />
            {!faculty && <FacultyCombobox employees={facultyChoices} selectedId={listFilters.employee_id} allowClear label="Search Faculty" onSelect={(id) => setListFilters((current) => ({ ...current, employee_id: id, search: '' }))} />}
            <label>Status<select value={listFilters.status} onChange={(e) => change('status', e.target.value)}><option value="">All statuses</option><option value="present">Complete</option><option value="late_undertime">Late/Undertime</option></select></label>
            <label>Method<select value={listFilters.method} onChange={(e) => change('method', e.target.value)}><option value="">All methods</option><option value="rfid">RFID</option><option value="fingerprint">Fingerprint</option></select></label>
            <button className="ui-control ui-action ui-secondary" type="button" onClick={() => setListFilters({ ...listFilters, search: '', employee_id: '', status: '', method: '' })}>Clear Filters</button>
        </div><div className="attendance-toolbar-actions"><Link className="button ui-control ui-action ui-primary" href={`/attendance/dtr?start_date=${listFilters.cutoff_start}${listFilters.employee_id ? `&employee_id=${listFilters.employee_id}` : ''}`}>Preview / Download DTR</Link>{faculty && <Link className="button ui-control ui-action ui-secondary" href="/my-schedule">My Schedule</Link>}</div></div>
        {summary && <section className="panel"><h2>{formatDate(dtr.cutoff.start_date)} – {formatDate(dtr.cutoff.end_date)}</h2><div className="dtr-summary-grid"><div><span>Worked hours</span><strong>{Number(summary.total_hours).toFixed(2)}</strong></div><div><span>Days present</span><strong>{summary.days_present}</strong></div><div><span>Absent days</span><strong>{summary.absent_days}</strong></div></div><p>Completed class and consultation taps count toward pay. Breaks, gaps, and calendar/leave entries do not add paid hours.</p></section>}
        {canGenerate && <form className="panel compact-form" onSubmit={(e) => { e.preventDefault(); test.post('/attendance/generate-test'); }}><h2>Generate Test Class Attendance</h2><CutoffPicker value={test.data.start_date} onChange={(period) => test.setData({ ...test.data, start_date: period.start, end_date: period.end })} /><label>Generate for<select value={test.data.target_mode} onChange={(e) => test.setData('target_mode', e.target.value)}><option value="selected">Selected faculty</option><option value="all">All faculty</option></select></label>{test.data.target_mode === 'selected' && <FacultyCombobox employees={employees} multiple selectedIds={test.data.employee_ids} onSelect={(ids) => test.setData('employee_ids', ids)} />}<label>Existing sessions<select value={test.data.generation_mode} onChange={(e) => test.setData('generation_mode', e.target.value)}><option value="fill_missing">Keep existing; fill missing</option><option value="overwrite">Replace all logs in selected range</option></select></label><Errors errors={test.errors} /><button className="ui-control ui-action ui-primary" disabled={test.processing}>Generate Test Sessions</button></form>}
        <section className="panel"><h2>{faculty ? 'My Class Attendance' : 'Class Attendance Logs'}</h2><div className="table-wrap"><table><thead><tr>{!faculty && <th>Faculty</th>}<th>Date</th><th>Subject</th><th>Section</th><th>Schedule</th><th>Time In</th><th>Time Out</th><th>Method</th><th>Hours</th><th>Status</th></tr></thead><tbody>{logs.data.length ? logs.data.map((log) => <tr key={log.id}>{!faculty && <td>{fullName(log.employee)}</td>}<td>{formatDate(log.attendance_date)}</td><td>{log.schedule_type === 'consultation' ? 'Consultation' : log.subject || 'Not specified'}{log.class_mode && <small>{log.class_mode === 'lecture' ? 'Lecture' : 'Laboratory'}</small>}{log.legacy_source_id && <small>Reconstructed from legacy daily attendance</small>}</td><td>{log.schedule_type === 'consultation' ? '—' : log.section || 'Not specified'}</td><td>{time12(log.scheduled_start)}–{time12(log.scheduled_end)}</td><td>{time12(log.time_in)}</td><td>{time12(log.time_out)}</td><td>{log.method_in || '-'} / {log.method_out || '-'}</td><td>{Number(log.total_hours).toFixed(2)}</td><td>{log.display_status ? <span className="badge" data-status={log.display_status}>{log.display_status === 'late_undertime' ? 'Late/Undertime' : 'Complete'}</span> : '—'}</td></tr>) : <tr><td colSpan={faculty ? 9 : 10} className="empty-row">No class attendance matches these filters.</td></tr>}</tbody></table></div><Pagination links={logs.links} /></section>
    </AppLayout>;
}
