import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import useRealtimeReload from '../hooks/useRealtimeReload';
import AppLayout from '../Layouts/AppLayout';
import AdminDashboardOverview from '../components/AdminDashboardOverview';
import '../../css/adminDashboard.css';
import FacultyDashboardOverview from '../components/FacultyDashboardOverview';
import FacultyTodaySchedule from '../components/FacultyTodaySchedule';
import '../../css/facultyToday.css';
import { fullName, formatDate } from '../lib/format';

export default function Dashboard({ employeeCount, entrance, presentToday, incompleteToday, adminOverview, classAnalytics, todaySchedule, todayScheduleSummary, scheduleView, scheduleAsOf, scheduleDate, contractWarnings }) {
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
        : ['classAnalytics', 'entrance', 'presentToday', 'incompleteToday', 'scheduleDate', 'adminOverview', 'employeeCount', 'contractWarnings'], 15000);
    return <AppLayout title="Dashboard">
        <div className="dashboard-content">
        {!faculty && <AdminDashboardOverview employeeCount={employeeCount} presentToday={presentToday} entrance={entrance} overview={adminOverview} report={classAnalytics} date={scheduleDate} />}
        {faculty && <FacultyDashboardOverview entrance={entrance} sessions={todaySchedule} date={scheduleDate} linked={linked} />}
        {faculty && linked && <FacultyTodaySchedule sessions={scheduleView?.items ?? todaySchedule} summary={scheduleView?.summary ?? todayScheduleSummary} date={scheduleView?.date ?? scheduleDate} today={scheduleDate} asOf={scheduleAsOf} onDateChange={changeScheduleDate} loading={scheduleLoading} />}
        {contractWarnings.length > 0 && <section className="panel"><h2>{faculty ? 'Your contract ends soon' : 'Contracts ending within 7 days'}</h2>{contractWarnings.map((e) => <p key={e.id}>{fullName(e)} · {formatDate(e.contract_end)}</p>)}</section>}
        </div>
    </AppLayout>;
}
