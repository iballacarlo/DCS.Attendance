import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import useRealtimeReload from '../hooks/useRealtimeReload';
import AppLayout from '../Layouts/AppLayout';
import ClassAnalytics from '../components/ClassAnalytics';
import FacultyDashboardOverview from '../components/FacultyDashboardOverview';
import FacultyTodaySchedule from '../components/FacultyTodaySchedule';
import '../../css/facultyToday.css';
import { fullName, time12, formatDate } from '../lib/format';

export default function Dashboard({ employeeCount, entrance, presentToday, incompleteToday, classAnalytics, todaySchedule, todayScheduleSummary, scheduleView, scheduleAsOf, scheduleDate, contractWarnings }) {
    const user = usePage().props.auth.user;
    const faculty = user.role === 'faculty';
    const linked = !!user.employee;
    const [scheduleLoading, setScheduleLoading] = useState(false);
    const facultyRefresh = ['todaySchedule', 'todayScheduleSummary', 'scheduleView', 'scheduleAsOf', 'scheduleDate', 'entrance', 'contractWarnings'];
    const changeScheduleDate = (date) => {
        setScheduleLoading(true);
        router.get('/', date ? { schedule_date: date } : {}, {
            only: facultyRefresh, preserveState: true, preserveScroll: true,
            onFinish: () => setScheduleLoading(false),
        });
    };
    useRealtimeReload(faculty
        ? facultyRefresh
        : ['classAnalytics', 'entrance', 'presentToday', 'incompleteToday', 'scheduleDate'], 15000);
    return <AppLayout title="Dashboard">
        <div className="dashboard-content">
        {!faculty && <div className="attendance-metrics"><article className="panel"><small>Active COS faculty</small><strong>{employeeCount}</strong></article><article className="panel"><small>School arrivals today</small><strong>{presentToday}</strong></article><article className="panel"><small>Awaiting school OUT</small><strong>{incompleteToday}</strong></article></div>}
        {faculty && <FacultyDashboardOverview entrance={entrance} sessions={todaySchedule} date={scheduleDate} linked={linked} />}
        {faculty && linked && <FacultyTodaySchedule sessions={scheduleView?.items ?? todaySchedule} summary={scheduleView?.summary ?? todayScheduleSummary} date={scheduleView?.date ?? scheduleDate} today={scheduleDate} asOf={scheduleAsOf} onDateChange={changeScheduleDate} loading={scheduleLoading} />}
        {!faculty && <div className="panel dashboard-school-attendance"><div className="panel-heading"><h2>School attendance · {formatDate(scheduleDate)}</h2><Link href="/entrance">View school attendance</Link></div><div className="table-wrap"><table><thead><tr><th>Faculty</th><th>School IN</th><th>School OUT</th></tr></thead><tbody>{entrance.length ? entrance.map((log) => <tr key={log.id}><td>{fullName(log.employee)}</td><td>{time12(log.time_in)}</td><td>{time12(log.time_out)}</td></tr>) : <tr><td colSpan={3}>No school arrivals recorded today.</td></tr>}</tbody></table></div><div className="dashboard-panel-footer"><Link href="/attendance/dtr">Open Daily Time Record</Link></div></div>}
        {!faculty && classAnalytics && <section className="dashboard-class-attendance"><div className="panel-heading"><div><h2>Class attendance · Last 7 days</h2></div><Link href="/class-reports">Reports &amp; analytics</Link></div><ClassAnalytics report={classAnalytics} onSelect={(selection) => router.get('/class-reports', { ...classAnalytics.filters, ...selection })} /></section>}
        {contractWarnings.length > 0 && <section className="panel"><h2>{faculty ? 'Your contract ends soon' : 'Contracts ending within 7 days'}</h2>{contractWarnings.map((e) => <p key={e.id}>{fullName(e)} · {formatDate(e.contract_end)}</p>)}</section>}
        </div>
    </AppLayout>;
}
