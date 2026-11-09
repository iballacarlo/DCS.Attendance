import { Link, router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import SemesterPicker from '../../components/SemesterPicker';
import CutoffPicker from '../../components/CutoffPicker';
import AttendanceStatus, { AttendanceStatusFilter } from '../../components/AttendanceStatus';
import AttendancePagination from '../../components/AttendancePagination';
import FacultyCombobox from '../../components/FacultyCombobox';
import WeeklyClassAttendance from '../../components/WeeklyClassAttendance';
import { fullName, time12, formatDate } from '../../lib/format';

const moveWeek = (value, days) => { const d = new Date(`${value}T00:00:00`); d.setDate(d.getDate() + days); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };

export default function AttendanceIndex({ employees, logs, filters, rooms = [], weekly = { rows: [] } }) {
    const admin = usePage().props.auth.user.role === 'admin';
    const [correcting, setCorrecting] = useState(null);
    const correction = useForm({ time_out: '', reason: '' });
    const reload = (values) => router.get('/attendance', { ...filters, ...values, page: 1 }, { preserveScroll: true });
    const saveCorrection = () => correction.put(`/attendance/${correcting.id}/correction`, { preserveScroll: true, onSuccess: () => { setCorrecting(null); correction.reset(); } });
    return <AppLayout title="Class Attendance" subtitle="Room-based classroom monitoring. Class taps remain separate from the DTR.">
        <WeeklyClassAttendance rows={weekly.rows} weekStart={filters.week_start} moveWeek={moveWeek} onWeekChange={(week_start) => reload({ week_start })} admin={admin} onCorrect={(row) => { setCorrecting(row); correction.setData({ time_out: '', reason: '' }); }} />
        <section className="panel"><div className="panel-heading"><h2>Class attendance records</h2>{admin && <Link href="/class-reports">Reports &amp; analytics</Link>}</div><div className="list-filters"><SemesterPicker compact value={filters.term_id} /><CutoffPicker termId={filters.term_id} value={filters.cutoff_start} onChange={(p) => reload({ cutoff_start: p.start })} />{admin && <FacultyCombobox employees={employees} selectedId={filters.employee_id} allowClear onSelect={(id) => reload({ employee_id: id })} />}<label>Room<select value={filters.room_id || ''} onChange={(e) => reload({ room_id: e.target.value })}><option value="">All rooms</option>{rooms.map((room) => <option key={room.id} value={room.id}>{room.code}</option>)}</select></label><AttendanceStatusFilter value={filters.status} onChange={(status) => reload({ status })} /></div><div className="table-wrap"><table><thead><tr><th>Date</th><th>Faculty</th><th>Subject / Section</th><th>Room</th><th>Schedule</th><th>IN</th><th>OUT</th><th>Status</th>{admin && <th>Action</th>}</tr></thead><tbody>{logs.data.length ? logs.data.map((l) => <tr key={l.id}><td>{formatDate(l.attendance_date)}</td><td>{fullName(l.employee)}</td><td>{l.subject} / {l.section}</td><td>{l.room_code || 'Not assigned'}</td><td>{time12(l.scheduled_start)} – {time12(l.scheduled_end)}</td><td>{time12(l.time_in)}</td><td>{time12(l.time_out)}</td><td><AttendanceStatus record={l} />{l.school_attendance_warning && <small className="school-warning">No School IN</small>}</td>{admin && <td>{l.has_record && l.time_in && !l.time_out && <button onClick={() => { setCorrecting(l); correction.setData({ time_out: '', reason: '' }); }}>Correct OUT</button>}</td>}</tr>) : <tr><td colSpan={admin ? 9 : 8}>No class attendance in this cutoff.</td></tr>}</tbody></table></div><AttendancePagination records={logs} perPage={filters.per_page} onPerPage={(per_page) => reload({ per_page })} label="Class attendance pages" /></section>
        {correcting && <section className="panel correction-panel"><div className="panel-heading"><h2>Correct missing class OUT</h2><button className="ui-secondary" onClick={() => setCorrecting(null)}>Cancel</button></div><p>{correcting.employee_name || fullName(correcting.employee)} · {correcting.subject} · {correcting.room_code}</p><div className="compact-form-row"><label>Actual OUT<input type="time" value={correction.data.time_out} onChange={(e) => correction.setData('time_out', e.target.value)} /></label><label>Valid reason<textarea value={correction.data.reason} onChange={(e) => correction.setData('reason', e.target.value)} maxLength="1000" /></label><button disabled={correction.processing || !correction.data.time_out || correction.data.reason.length < 5} onClick={saveCorrection}>Save correction</button></div>{Object.values(correction.errors).map((error) => <p className="field-error" key={error}>{error}</p>)}</section>}
    </AppLayout>;
}
