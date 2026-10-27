import { Link, router, usePage } from '@inertiajs/react';
import AppLayout from '../../Layouts/AppLayout';
import { fullName, time12 } from '../../lib/format';

export default function EntranceIndex({ logs, date }) {
    const admin = usePage().props.auth.user.role === 'admin';
    return <AppLayout title="School Attendance">
        <section className="panel"><div className="panel-heading"><h2>{admin ? 'Daily school attendance' : 'My school attendance'}</h2><Link href="/attendance/dtr">View DTR</Link></div>
            <div className="list-filters"><label>Date<input type="date" value={date} onChange={(e) => e.target.value && router.get('/entrance', { date: e.target.value })} /></label></div>
            <div className="table-wrap"><table><thead><tr><th>Faculty</th><th>School IN</th><th>School OUT</th><th>Status</th><th>Source</th></tr></thead><tbody>{logs.length ? logs.map((log) => <tr key={log.id}><td>{fullName(log.employee)}</td><td>{time12(log.time_in)}</td><td>{time12(log.time_out)}</td><td><span className="badge">{log.time_out ? 'Complete' : 'Incomplete'}</span></td><td>Simulated RFID</td></tr>) : <tr><td colSpan={5}>No school attendance for this date.</td></tr>}</tbody></table></div>
        </section>
    </AppLayout>;
}
