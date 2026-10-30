import DateInput from './DateInput';
import { AlertCircle, Check, CheckCircle2, ChevronLeft, ChevronRight, Circle, Clock3, Coffee, GraduationCap, LogIn, LogOut, UsersRound } from 'lucide-react';
import { formatDate, time12 } from '../lib/format';

const states = {
    upcoming: { label: 'Upcoming', Icon: Clock3 },
    awaiting_in: { label: 'Awaiting IN', Icon: AlertCircle },
    checked_in: { label: 'Checked in', Icon: LogIn },
    finished: { label: 'Finished', Icon: CheckCircle2 },
    for_review: { label: 'For review', Icon: AlertCircle },
    absent: { label: 'Absent', Icon: AlertCircle },
    not_required: { label: 'Not required', Icon: Circle },
};

function ClassSteps({ session }) {
    const steps = [
        { label: 'IN', done: !!session.time_in, time: session.time_in, Icon: LogIn },
        { label: 'OUT', done: !!session.time_out, time: session.time_out, Icon: LogOut },
        { label: 'Finished', done: session.status === 'finished', Icon: CheckCircle2 },
    ];
    return <ol className="faculty-class-steps" aria-label="Class attendance progress">
        {steps.map(({ label, done, time, Icon }) => <li key={label} className={done ? 'is-recorded' : ''}>
            <span className="faculty-step-icon" aria-hidden="true">{done ? <Check size={15} /> : <Icon size={15} />}</span>
            <strong>{label}</strong><span>{time ? time12(time) : label === 'Finished' && done ? 'Done' : '—'}</span>
        </li>)}
    </ol>;
}

export function shiftScheduleDate(date, days) {
    const shifted = new Date(`${date}T12:00:00Z`);
    shifted.setUTCDate(shifted.getUTCDate() + days);
    return shifted.toISOString().slice(0, 10);
}

export default function FacultyTodaySchedule({ sessions = [], summary = { finished: 0, total: 0 }, date, today = date, asOf, onDateChange, loading = false }) {
    const isToday = date === today;
    return <section className="panel faculty-day-schedule" aria-labelledby="faculty-day-title" aria-busy={loading}>
        <div className="panel-heading"><div><h2 id="faculty-day-title">{isToday ? 'Today’s schedule' : 'Schedule & attendance'}</h2><p><time dateTime={date}>{formatDate(date)}</time></p></div>
            <span className="faculty-day-summary">{summary.total ? `Finished: ${summary.finished}/${summary.total} classes` : isToday ? 'No required classes today' : 'No required classes on this date'}</span>
        </div>
        {onDateChange && <div className="faculty-schedule-navigation" role="group" aria-label="Schedule date">
            <button type="button" aria-label="Previous day" title="Previous day" disabled={loading || date <= '1900-01-01'} onClick={() => onDateChange(shiftScheduleDate(date, -1))}><ChevronLeft size={18} aria-hidden="true" /></button>
            <label><span className="faculty-date-label">View date</span><DateInput value={date} min="1900-01-01" max="2100-12-31" disabled={loading} onChange={(event) => { const value = event.target.value; if (/^\d{4}-\d{2}-\d{2}$/.test(value) && value >= '1900-01-01' && value <= '2100-12-31') onDateChange(value); }} /></label>
            <button type="button" aria-label="Next day" title="Next day" disabled={loading || date >= '2100-12-31'} onClick={() => onDateChange(shiftScheduleDate(date, 1))}><ChevronRight size={18} aria-hidden="true" /></button>
            <button type="button" className="faculty-schedule-today" disabled={loading} onClick={() => onDateChange(null)}>Today</button>
        </div>}
        {sessions.length ? <ol className="faculty-day-timeline">{sessions.map((session) => {
            const isClass = session.schedule_type === 'class';
            const state = isClass ? states[session.status] || states.upcoming : null;
            const Icon = isClass ? GraduationCap : session.schedule_type === 'consultation' ? UsersRound : Coffee;
            return <li key={session.key} className={`faculty-day-entry is-${session.status || 'scheduled'}${session.is_current ? ' is-current' : ''}`}>
                <span className="faculty-day-node" aria-hidden="true"><Icon size={17} /></span>
                <article className="faculty-session-card">
                    <div className="faculty-session-time"><Clock3 size={14} aria-hidden="true" /><span>{time12(session.start_time)} – {time12(session.end_time)}</span>{session.is_current && <strong>Now</strong>}</div>
                    <div className="faculty-session-heading"><div><h3>{isClass ? session.subject || 'Class session' : session.schedule_type === 'consultation' ? 'Consultation' : 'Lunch break'}</h3>
                        {isClass && <p>{[session.section, session.class_mode === 'laboratory' ? 'Laboratory' : session.class_mode === 'lecture' ? 'Lecture' : ''].filter(Boolean).join(' · ')}</p>}
                    </div>{state && <span className="faculty-session-status"><state.Icon size={14} aria-hidden="true" />{state.label}</span>}</div>
                    {isClass && <ClassSteps session={session} />}
                    {session.status === 'finished' && (session.late_minutes > 0 || session.undertime_minutes > 0) && <div className="faculty-session-flags">
                        {session.late_minutes > 0 && <span>Late · {session.late_minutes} min</span>}
                        {session.undertime_minutes > 0 && <span>Undertime · {session.undertime_minutes} min</span>}
                    </div>}
                    {session.exclusion && <p className="faculty-session-exclusion">{session.exclusion}</p>}
                </article>
            </li>;
        })}</ol> : <p className="faculty-day-empty">{isToday ? 'No assigned sessions today.' : 'No assigned sessions on this date.'}</p>}
        {asOf && <small className="faculty-day-updated">Updated <time dateTime={asOf}>{time12(asOf.slice(11, 19))}</time></small>}
    </section>;
}
