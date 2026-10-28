import { Link, router, usePage } from '@inertiajs/react';
import useRealtimeReload from '../hooks/useRealtimeReload';
import AppLayout from '../Layouts/AppLayout';
import ClassAnalytics from '../components/ClassAnalytics';
import { fullName, time12, formatDate } from '../lib/format';

export default function Dashboard({ employeeCount, entrance, presentToday, incompleteToday, classAnalytics, todaySchedule, scheduleDate, contractWarnings }) {
    const faculty = usePage().props.auth.user.role === 'faculty';
    useRealtimeReload(['classAnalytics', 'entrance', 'presentToday', 'incompleteToday'], 15000, !faculty);
    return <AppLayout title="Dashboard">
        <div className="dashboard-content">
        <div className="attendance-metrics">{!faculty && <article className="panel"><small>Active COS faculty</small><strong>{employeeCount}</strong></article>}<article className="panel"><small>School arrivals today</small><strong>{presentToday}</strong></article><article className="panel"><small>Awaiting school OUT</small><strong>{incompleteToday}</strong></article></div>
        <div className="panel dashboard-school-attendance"><div className="panel-heading"><h2>School attendance · {formatDate(scheduleDate)}</h2><Link href="/entrance">{faculty ? 'My attendance' : 'View school attendance'}</Link></div><div className="table-wrap"><table><thead><tr><th>Faculty</th><th>School IN</th><th>School OUT</th></tr></thead><tbody>{entrance.length ? entrance.map((log) => <tr key={log.id}><td>{fullName(log.employee)}</td><td>{time12(log.time_in)}</td><td>{time12(log.time_out)}</td></tr>) : <tr><td colSpan={3}>No school arrivals recorded today.</td></tr>}</tbody></table></div><div className="dashboard-panel-footer"><Link href="/attendance/dtr">Open Daily Time Record</Link></div></div>
        {faculty && <section className="panel"><h2>Today’s schedule</h2>{todaySchedule.length ? todaySchedule.map((s) => <p key={`${s.schedule_type}-${s.id}`}><strong>{s.subject || s.schedule_type.replaceAll('_', ' ')}</strong> · {time12(s.start_time)} – {time12(s.end_time)} {s.section}</p>) : <p>No assigned sessions today.</p>}</section>}
        {classAnalytics && <section className="dashboard-class-attendance"><div className="panel-heading"><div><h2>Class attendance · Last 7 days</h2></div><Link href="/class-reports">Reports &amp; analytics</Link></div><ClassAnalytics report={classAnalytics} onSelect={(selection) => router.get('/class-reports', { ...classAnalytics.filters, ...selection })} /></section>}
        {contractWarnings.length > 0 && <section className="panel"><h2>Contracts ending within 7 days</h2>{contractWarnings.map((e) => <p key={e.id}>{fullName(e)} · {formatDate(e.contract_end)}</p>)}</section>}
        </div>
    </AppLayout>;
}
