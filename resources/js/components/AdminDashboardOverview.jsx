import { Link } from '@inertiajs/react';
import { ArrowRight, AlertCircle, CheckCircle2, Clock3, FileText, GraduationCap, CalendarDays, LogIn, LogOut, UsersRound } from 'lucide-react';
import { fullName, formatDate, time12 } from '../lib/format';

export default function AdminDashboardOverview({ employeeCount = 0, entrance = [], presentToday = 0, overview = {}, report, date }) {
    const summary = report?.summary || {};
    const total = Number(summary.ended) || 0;
    const trend = [...(report?.trend || [])].filter((day) => Number(day.ended) > 0).sort((a, b) => a.label.localeCompare(b.label));
    const recent = entrance.slice(0, 5);
    const metrics = [
        { label: 'On time & full class', count: summary.on_time || 0, tone: 'green', Icon: CheckCircle2 },
        { label: 'Late', count: summary.late || 0, tone: 'amber', Icon: Clock3 },
        { label: 'Undertime', count: summary.undertime || 0, tone: 'blue', Icon: LogOut },
        { label: 'Absent', count: summary.missed || 0, tone: 'red', Icon: AlertCircle },
    ];
    return <div className="admin-dashboard-overview">
        <div className="admin-today-metrics" aria-label="Admin priorities">
            {[
                { label: 'COS Faculty', value: employeeCount, detail: 'Faculty records currently active', Icon: UsersRound },
                { label: 'Faculty Present Today', value: presentToday, detail: 'Faculty with a recorded school IN today', Icon: LogIn },
                { label: 'Scheduled Classes Today', value: overview.classes_today || 0, detail: 'Required class sessions for today', Icon: CalendarDays },
            ].map(({ label, value, detail, Icon, href }) => <article className="panel admin-overview-metric" key={label}><span className="admin-overview-icon"><Icon size={20} aria-hidden="true" /></span><h2>{label}</h2><strong>{value}</strong><p>{detail}{href && <> · <Link href={href}>View records</Link></>}</p></article>)}
        </div>

        <section className="panel admin-class-summary" aria-labelledby="admin-class-title">
            <div className="admin-overview-heading"><div><h2 id="admin-class-title">Class attendance</h2><p>Last 7 days{report?.filters && ` · ${formatDate(report.filters.start_date)} – ${formatDate(report.filters.end_date)}`}</p></div><Link href="/class-reports">Open detailed reports<ArrowRight size={16} aria-hidden="true" /></Link></div>
            <div className="admin-class-metrics">{metrics.map(({ label, count, tone, Icon }) => <article className={`admin-class-metric is-${tone}`} key={label}><span><Icon size={17} aria-hidden="true" />{label}</span><strong>{count}<small>{count === 1 ? ' class' : ' classes'}</small></strong><div className="admin-summary-track" aria-hidden="true"><span style={{ width: `${total ? Math.min(100, count / total * 100) : 0}%` }} /></div></article>)}</div>
            <div className="admin-summary-footnote"><span>{total ? `Based on ${total} finished classes in the last 7 days` : 'No finished classes'}</span></div>
            <p className="admin-summary-note">A class can be both late and undertime. Classes for review are included in the total.</p>
        </section>

        <div className="admin-monitoring-grid">
            <section className="panel admin-recent-attendance" aria-labelledby="admin-school-title">
                <div className="admin-overview-heading"><div><h2 id="admin-school-title">School attendance today</h2><p>{formatDate(date)} · Latest {recent.length} of {entrance.length} records</p></div><Link href="/entrance">View all<ArrowRight size={15} aria-hidden="true" /></Link></div>
                {recent.length ? <ul className="admin-school-list">{recent.map((log) => <li key={log.id}><strong>{fullName(log.employee)}</strong><dl><div><dt>School IN</dt><dd>{log.time_in ? time12(log.time_in) : '—'}</dd></div><div><dt>School OUT</dt><dd>{log.time_out ? time12(log.time_out) : '—'}</dd></div></dl></li>)}</ul> : <p className="admin-overview-empty">No school attendance recorded today.</p>}
            </section>
            <section className="panel admin-record-trend" aria-labelledby="admin-trend-title">
                <div className="admin-overview-heading"><div><h2 id="admin-trend-title">Class records by day</h2><p>Complete IN/OUT records out of eligible finished classes</p></div></div>
                {trend.length ? <ul className="admin-trend-list">{trend.map((day) => <li key={day.label}><span>{formatDate(day.label)}</span><div className="admin-trend-track" role="img" aria-label={`${formatDate(day.label)}: ${day.complete}/${day.ended} finished classes have complete IN/OUT records`}><span style={{ width: `${Math.min(100, Number(day.complete) / Number(day.ended) * 100)}%` }} /></div><strong>{day.complete}/{day.ended}</strong></li>)}</ul> : <p className="admin-overview-empty">No finished classes in the last 7 days.</p>}
            </section>
        </div>

        <nav className="admin-dashboard-shortcuts" aria-label="Admin shortcuts">{[
            { href: '/attendance/dtr', label: 'Daily Time Records', Icon: FileText },
            { href: '/leave', label: 'Review leave requests', Icon: CheckCircle2 },
            { href: '/employees', label: 'Manage faculty', Icon: GraduationCap },
        ].map(({ href, label, Icon }) => <Link key={href} href={href}><Icon size={18} aria-hidden="true" />{label}<ArrowRight size={15} aria-hidden="true" /></Link>)}</nav>
    </div>;
}
