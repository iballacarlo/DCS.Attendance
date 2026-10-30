import { Link, router } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import SemesterPicker from '../../components/SemesterPicker';
import CutoffPicker from '../../components/CutoffPicker';
import AttendanceStatus, { AttendanceStatusFilter } from '../../components/AttendanceStatus';
import AttendancePagination from '../../components/AttendancePagination';
import FacultyCombobox from '../../components/FacultyCombobox';
import { fullName, time12, formatDate } from '../../lib/format';

export default function AttendanceIndex({ employees, logs, filters }) {
    const reload = (values) => router.get('/attendance', { ...filters, ...values, page: 1 }, { preserveScroll: true });
    return <AppLayout title="Class Attendance" subtitle="Hardware records for classroom monitoring. These taps do not contribute to the DTR."><section className="panel"><div className="panel-heading"><h2>Class attendance records</h2>{employees.length > 0 && <Link href="/class-reports">Reports &amp; analytics</Link>}</div><div className="list-filters"><SemesterPicker compact value={filters.term_id} /><CutoffPicker termId={filters.term_id} value={filters.cutoff_start} onChange={(p) => reload({ cutoff_start: p.start })} />{employees.length > 0 && <FacultyCombobox employees={employees} selectedId={filters.employee_id} allowClear onSelect={(id) => reload({ employee_id: id })} />}<AttendanceStatusFilter value={filters.status} onChange={(status) => reload({ status })} /></div><div className="table-wrap"><table><thead><tr><th>Date</th><th>Faculty</th><th>Subject / Section</th><th>Schedule</th><th>IN</th><th>OUT</th><th>Status</th></tr></thead><tbody>{logs.data.length ? logs.data.map((l) => <tr key={l.id}><td>{formatDate(l.attendance_date)}</td><td>{fullName(l.employee)}</td><td>{l.subject} / {l.section}</td><td>{time12(l.scheduled_start)} – {time12(l.scheduled_end)}</td><td>{time12(l.time_in)}</td><td>{time12(l.time_out)}</td><td><AttendanceStatus record={l} /></td></tr>) : <tr><td colSpan={7}>No class attendance in this cutoff.</td></tr>}</tbody></table></div><AttendancePagination records={logs} perPage={filters.per_page} onPerPage={(per_page) => reload({ per_page })} label="Class attendance pages" /></section></AppLayout>;
}
