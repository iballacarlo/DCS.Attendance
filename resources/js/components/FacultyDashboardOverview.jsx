import { Link } from '@inertiajs/react';
import { ArrowRight, CalendarDays, CheckCircle2, Clock3, FileText, GraduationCap, LogIn, LogOut } from 'lucide-react';
import { formatDate, time12 } from '../lib/format';

const labels = { upcoming: 'Upcoming', awaiting_in: 'Awaiting IN', checked_in: 'Checked in' };

export default function FacultyDashboardOverview({ entrance = [], sessions = [], date, linked = true }) {
    if (!linked) return <section className="panel faculty-link-notice"><h2>Faculty profile not linked</h2><p>Contact the administrator to link your faculty record and view your attendance and schedule.</p></section>;

    const school = entrance[0];
    const schoolState = school?.time_out ? 'out' : school?.time_in ? 'in' : 'empty';
    const schoolLabel = { out: 'Checked out', in: 'Checked in', empty: 'Not yet recorded' }[schoolState];
    const SchoolIcon = schoolState === 'out' ? CheckCircle2 : schoolState === 'in' ? LogIn : Clock3;
    const required = sessions.filter((s) => s.schedule_type === 'class' && s.status !== 'not_required');
    const ordered = [...required].sort((a, b) => a.start_time.localeCompare(b.start_time));
    const current = ordered.find((s) => s.is_current && s.status !== 'finished');
    const next = current || ordered.find((s) => ['upcoming', 'checked_in'].includes(s.status));

    return <>
        <div className="faculty-overview-grid">
            <section className="panel faculty-overview-card" aria-labelledby="my-school-title">
                <header><span className="faculty-overview-icon"><LogIn size={21} aria-hidden="true" /></span><div><h2 id="my-school-title">My school attendance</h2><p>{formatDate(date)}</p></div></header>
                <span className={`faculty-school-status is-${schoolState}`}><SchoolIcon size={16} aria-hidden="true" />{schoolLabel}</span>
                <dl className="faculty-school-times"><div><dt><LogIn size={15} aria-hidden="true" />School IN</dt><dd>{school?.time_in ? time12(school.time_in) : '—'}</dd></div><div><dt><LogOut size={15} aria-hidden="true" />School OUT</dt><dd>{school?.time_out ? time12(school.time_out) : '—'}</dd></div></dl>
                <Link className="faculty-overview-link" href="/entrance">Attendance history<ArrowRight size={15} aria-hidden="true" /></Link>
            </section>
            <section className="panel faculty-overview-card" aria-labelledby="next-class-title">
                <header><span className="faculty-overview-icon is-blue"><GraduationCap size={21} aria-hidden="true" /></span><div><h2 id="next-class-title">{current ? 'Current class' : 'Next class'}</h2><p>{current ? 'Scheduled now' : 'Your next scheduled session'}</p></div></header>
                {next ? <div className="faculty-next-class"><span className={`faculty-school-status is-${next.status === 'checked_in' ? 'in' : 'empty'}`}><Clock3 size={16} aria-hidden="true" />{labels[next.status] || next.status}</span><h3>{next.subject || 'Class session'}</h3><p>{[next.section, next.class_mode === 'laboratory' ? 'Laboratory' : next.class_mode === 'lecture' ? 'Lecture' : ''].filter(Boolean).join(' · ')}</p><p className="faculty-next-time"><Clock3 size={16} aria-hidden="true" />{time12(next.start_time)} – {time12(next.end_time)}</p></div>
                    : <p className="faculty-overview-empty">{required.length ? 'No more scheduled classes today' : 'No required classes today'}</p>}
            </section>
        </div>
        <nav className="faculty-dashboard-shortcuts" aria-label="Faculty shortcuts">{[
            { href: '/my-schedule', label: 'My schedule', Icon: CalendarDays },
            { href: '/attendance/dtr', label: 'Daily Time Record', Icon: FileText },
            { href: '/leave', label: 'Leave', Icon: CalendarDays },
        ].map(({ href, label, Icon }) => <Link href={href} key={href}><Icon size={18} aria-hidden="true" />{label}<ArrowRight size={15} aria-hidden="true" /></Link>)}</nav>
    </>;
}
