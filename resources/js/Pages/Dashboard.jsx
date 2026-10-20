import { Link, usePage } from '@inertiajs/react';
import { ArrowUpRight, CalendarCheck2, CalendarClock, CalendarDays, Clock3, GraduationCap, Wallet } from 'lucide-react';
import { useEffect, useState } from 'react';
import AppLayout from '../Layouts/AppLayout';
import useRealtimeReload from '../hooks/useRealtimeReload';
import { fullName, money, time12 } from '../lib/format';

function MetricTile({ href, icon: Icon, label, value, tone }) {
    return <Link href={href} className={`bento-metric ${tone}`}>
        <span className="metric-icon"><Icon size={19} strokeWidth={1.8} /></span>
        <span className="metric-copy"><small>{label}</small><strong>{value}</strong></span>
        <ArrowUpRight className="metric-arrow" size={17} />
    </Link>;
}

function AcademicTermContext({ canEdit, value }) {
    return <section className="dashboard-academic-context" aria-label="Current academic term">
        <CalendarDays size={18} strokeWidth={1.8} />
        <span>Academic Period</span>
        <strong>{value || '-'}</strong>
        {canEdit && <Link href="/settings/general">Edit <ArrowUpRight size={14} /></Link>}
    </section>;
}

function FacultyDashboardOverview({ academicTermLabel, user }) {
    const [clock, setClock] = useState(new Date());

    useEffect(() => {
        const timer = window.setInterval(() => setClock(new Date()), 1000);
        return () => window.clearInterval(timer);
    }, []);

    const clockTime = new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: true,
    }).format(clock);
    const employee = user.employee;

    return <section className="faculty-dashboard-overview" aria-label="Faculty account overview">
        <div className="faculty-dashboard-identity">
            <h2>{fullName(employee)}</h2>
            <p>{employee?.employee_no || '-'} <i></i> {employee?.department || 'Cavite State University - Imus Campus'}</p>
        </div>
        <div className="faculty-dashboard-term">
            <small>Current Academic Term</small>
            <strong>{academicTermLabel || '-'}</strong>
        </div>
        <div className="faculty-dashboard-clock" aria-label="Current Philippine time">
            <small>Philippine Time</small>
            <strong>{clockTime}</strong>
        </div>
    </section>;
}

export default function Dashboard({ employeeCount, presentToday, openPeriods, attendanceTrend = [], latestPayrolls, recentAttendance, contractWarnings = [] }) {
    const { academicTerm, auth } = usePage().props;
    const isFaculty = auth.user.role === 'faculty';
    const academicTermLabel = academicTerm?.semester && academicTerm?.school_year
        ? `${academicTerm.semester.toUpperCase()} SY ${academicTerm.school_year}`
        : academicTerm?.label;

    useRealtimeReload(isFaculty
        ? ['latestPayrolls', 'recentAttendance', 'contractWarnings']
        : ['employeeCount', 'presentToday', 'openPeriods', 'attendanceTrend', 'latestPayrolls', 'recentAttendance', 'contractWarnings'], 5000);

    return <AppLayout title="Dashboard" subtitle={isFaculty ? 'Your attendance and payroll summary.' : 'Overview of faculty attendance and payroll activity.'}>
        {contractWarnings.length > 0 && <section className="contract-warning" role="status">
            <span className="contract-warning-icon"><CalendarClock size={20} /></span>
            <div><strong>Contract expiration reminder</strong><span>{contractWarnings.map((employee) => `${fullName(employee)} - ${employee.days_remaining === 0 ? 'ends today' : `${employee.days_remaining} day${employee.days_remaining === 1 ? '' : 's'} left`}`).join(' | ')}</span></div>
            {!isFaculty && <Link href="/employees">Review faculty <ArrowUpRight size={15} /></Link>}
        </section>}
        {isFaculty
            ? <FacultyDashboardOverview academicTermLabel={academicTermLabel} user={auth.user} />
            : <AcademicTermContext canEdit={auth.user.role === 'admin'} value={academicTermLabel} />}
        <section className={`bento-dashboard${isFaculty ? ' faculty-bento' : ''}`}>
            {!isFaculty && <>
                <MetricTile href={auth.user.role === 'admin' ? '/employees' : '/attendance'} icon={GraduationCap} label="Faculty" value={employeeCount} tone="metric-green" />
                <MetricTile href="/attendance" icon={Clock3} label="Present today" value={presentToday} tone="metric-gold" />
                <MetricTile href="/payroll" icon={Wallet} label="Open payroll periods" value={openPeriods} tone="metric-blue" />
            </>}

            <section className="bento-panel attendance-bento">
                <header className="bento-heading">
                    <div><span className="bento-eyebrow">{isFaculty ? 'My attendance' : 'Attendance'}</span><h2>{isFaculty ? 'My recent activity' : 'Recent activity'}</h2></div>
                    <Link href="/attendance">View all <ArrowUpRight size={15} /></Link>
                </header>
                <div className="bento-table"><table><thead><tr>{!isFaculty && <th>Faculty</th>}<th>Date</th><th>In</th><th>Out</th><th>Status</th></tr></thead><tbody>
                    {recentAttendance.length ? recentAttendance.map((log) => <tr key={log.id}>{!isFaculty && <td><strong>{fullName(log.employee)}</strong></td>}<td>{log.attendance_date}</td><td>{time12(log.time_in)}</td><td>{time12(log.time_out)}</td><td><span className="badge">{log.status}</span></td></tr>) : <tr><td colSpan={isFaculty ? 4 : 5} className="empty-row">No attendance logs yet.</td></tr>}
                </tbody></table></div>
            </section>

            {!isFaculty && <section className="trend-bento">
                <header className="bento-heading">
                    <div><span className="bento-eyebrow">Seven-day view</span><h2>Attendance pulse</h2></div>
                    <span className="trend-icon"><CalendarCheck2 size={19} /></span>
                </header>
                <div className="trend-chart" aria-label="Attendance during the last seven days">
                    {attendanceTrend.map((day) => {
                        const highest = Math.max(1, ...attendanceTrend.map((item) => item.count));
                        const height = day.count === 0 ? 8 : Math.max(18, (day.count / highest) * 100);
                        return <div className="trend-column" key={day.label} title={`${day.label}: ${day.count}`}>
                            <span className="trend-value">{day.count}</span>
                            <i style={{ height: `${height}%` }} />
                            <small>{day.label}</small>
                        </div>;
                    })}
                </div>
            </section>}

            <section className="bento-panel payroll-bento">
                <header className="bento-heading">
                    <div><span className="bento-eyebrow">{isFaculty ? 'My payroll' : 'Payroll'}</span><h2>{isFaculty ? 'My latest payslips' : 'Latest records'}</h2></div>
                    <Link href="/payroll">{isFaculty ? 'View all' : 'Manage'} <ArrowUpRight size={15} /></Link>
                </header>
                <div className="payroll-stack">
                    {latestPayrolls.length ? latestPayrolls.map((record) => <Link href={`/payroll/records/${record.id}`} className="payroll-item" key={record.id}>
                        <span><strong>{fullName(record.employee)}</strong><small>{record.payroll_period?.period_name}</small></span>
                        <strong className="payroll-amount">{money(record.net_pay)}</strong>
                    </Link>) : <div className="bento-empty">No payroll records yet.</div>}
                </div>
            </section>
        </section>
    </AppLayout>;
}
