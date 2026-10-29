import { Link, router, usePage } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import CutoffPicker from '../../components/CutoffPicker';
import FacultyCombobox from '../../components/FacultyCombobox';
import AttendancePagination from '../../components/AttendancePagination';
import { formatDate, fullName, time12 } from '../../lib/format';

export default function EntranceIndex({ logs, filters, employees, canSelectFaculty }) {
    const admin = usePage().props.auth.user.role === 'admin';
    const reload = (values) => router.get('/entrance', { ...filters, ...values, page: 1 }, { preserveScroll: true, preserveState: true });
    const dtrParams = new URLSearchParams({ start_date: filters.cutoff_start, ...(filters.employee_id ? { employee_id: filters.employee_id } : {}) });
    return <AppLayout title="School Attendance">
        <section className="panel"><div className="panel-heading"><h2>{admin ? 'Daily school attendance' : 'My school attendance'}</h2><Link href={`/attendance/dtr?${dtrParams}`}>View DTR</Link></div>
            <div className="list-filters">
                <CutoffPicker value={filters.cutoff_start} onChange={(period) => reload({ cutoff_start: period.start })} />
                {canSelectFaculty && <FacultyCombobox employees={employees} selectedId={filters.employee_id} allowClear onSelect={(id) => reload({ employee_id: id })} />}
                <label>Status<select value={filters.status} onChange={(event) => reload({ status: event.target.value })}><option value="">All statuses</option><option value="complete">Complete</option><option value="incomplete">Incomplete</option></select></label>
                <button type="button" className="ui-control ui-action ui-secondary" onClick={() => router.get('/entrance', {}, { preserveScroll: true })}>Reset filters</button>
            </div>
            <div className="table-wrap"><table><thead><tr><th>Date</th><th>Faculty</th><th>School IN</th><th>School OUT</th><th>Status</th><th>Source</th></tr></thead><tbody>{logs.data.length ? logs.data.map((log) => <tr key={log.id}><td>{formatDate(log.attendance_date)}</td><td>{fullName(log.employee)}</td><td>{time12(log.time_in)}</td><td>{time12(log.time_out)}</td><td><span className="badge">{log.time_in && log.time_out ? 'Complete' : 'Incomplete'}</span></td><td>RFID</td></tr>) : <tr><td colSpan={6}>No school attendance matches these filters.</td></tr>}</tbody></table></div>
            <AttendancePagination records={logs} perPage={filters.per_page} onPerPage={(per_page) => reload({ per_page })} label="School attendance pages" />
        </section>
    </AppLayout>;
}
