import { router, useForm } from '@inertiajs/react';
import { CalendarDays, ChevronLeft, ChevronRight, Edit3, Plus, Save, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import { fullName } from '../../lib/format';

const blankEvent = { title: '', type: 'regular_holiday', start_date: '', end_date: '', is_paid: true, scope_type: 'campus', department: '', employee_ids: [], remarks: '' };
const eventLabels = { regular_holiday: 'Regular Holiday', special_holiday: 'Special Holiday', asynchronous: 'Asynchronous Day', suspension: 'Suspension' };
const weekdays = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
const months = Array.from({ length: 12 }, (_, index) => new Date(2026, index, 1).toLocaleDateString('en-PH', { month: 'long' }));
const iso = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

export default function CalendarIndex({ month, events, employees, departments, canManage }) {
    const [editingEvent, setEditingEvent] = useState(null);
    const form = useForm(blankEvent);
    const { ask, dialog } = useConfirmDialog();
    const monthDate = new Date(`${month}-01T00:00:00`);
    const monthLabel = monthDate.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' });
    const cells = useMemo(() => {
        const first = new Date(monthDate);
        first.setDate(1 - first.getDay());
        return Array.from({ length: 42 }, (_, index) => {
            const date = new Date(first);
            date.setDate(first.getDate() + index);
            const dateValue = iso(date);
            return { date: dateValue, day: date.getDate(), current: date.getMonth() === monthDate.getMonth(), events: events.filter((item) => item.start_date.slice(0, 10) <= dateValue && item.end_date.slice(0, 10) >= dateValue) };
        });
    }, [month, events]);
    const navigate = (offset) => {
        const next = new Date(monthDate);
        next.setMonth(next.getMonth() + offset);
        router.get('/school-calendar', { month: iso(next).slice(0, 7) }, { preserveState: true });
    };
    const jumpTo = (year, monthIndex) => router.get('/school-calendar', { month: `${year}-${String(monthIndex + 1).padStart(2, '0')}` }, { preserveState: true });
    const today = () => router.get('/school-calendar', { month: iso(new Date()).slice(0, 7) }, { preserveState: true });
    const chooseDate = (date) => {
        if (!canManage || editingEvent) return;
        form.setData({ ...blankEvent, start_date: date, end_date: date });
        document.querySelector('.calendar-event-form')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };
    const editEvent = (event) => {
        setEditingEvent(event.id);
        form.setData({ title: event.title, type: event.type, start_date: event.start_date.slice(0, 10), end_date: event.end_date.slice(0, 10), is_paid: Boolean(event.is_paid), scope_type: event.scope_type, department: event.department || '', employee_ids: event.employees.map((employee) => employee.id), remarks: event.remarks || '' });
    };
    const reset = () => { setEditingEvent(null); form.setData(blankEvent); form.clearErrors(); };
    const submit = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: reset };
        editingEvent ? form.put(`/school-calendar/events/${editingEvent}`, options) : form.post('/school-calendar/events', options);
    };
    const remove = async (event) => {
        if (await ask({ title: 'Delete calendar event?', message: `Remove ${event.title} from the school calendar?`, confirmLabel: 'Delete Event', tone: 'danger' })) router.delete(`/school-calendar/events/${event.id}`, { preserveScroll: true });
    };
    const visibleEvents = events.filter((event) => event.start_date.slice(0, 7) <= month && event.end_date.slice(0, 7) >= month);

    return <AppLayout title="School Calendar" subtitle="Review holidays, asynchronous days, and campus suspensions.">
        <section className="school-calendar-shell">
            <header className="school-calendar-toolbar"><button type="button" title="Previous month" onClick={() => navigate(-1)}><ChevronLeft size={19} /></button><div className="calendar-period-controls"><label><span>Month</span><select aria-label="Calendar month" value={monthDate.getMonth()} onChange={(event) => jumpTo(monthDate.getFullYear(), Number(event.target.value))}>{months.map((name, index) => <option value={index} key={name}>{name}</option>)}</select></label><label><span>Year</span><select aria-label="Calendar year" value={monthDate.getFullYear()} onChange={(event) => jumpTo(Number(event.target.value), monthDate.getMonth())}>{Array.from({ length: 9 }, (_, index) => monthDate.getFullYear() - 4 + index).map((year) => <option value={year} key={year}>{year}</option>)}</select></label><button type="button" className="calendar-today-button" onClick={today}>Today</button></div><button type="button" title="Next month" onClick={() => navigate(1)}><ChevronRight size={19} /></button></header>
            <h2 className="calendar-current-period">{monthLabel}</h2>
            <div className="calendar-legend">{Object.entries(eventLabels).map(([type, label]) => <span className={`is-${type}`} key={type}><i></i>{label}</span>)}</div>
            <div className="school-calendar-grid">{weekdays.map((day) => <div className="calendar-weekday" key={day}>{day}</div>)}{cells.map((cell) => <div className={`calendar-day${cell.current ? '' : ' is-outside'}${cell.date === iso(new Date()) ? ' is-today' : ''}`} key={cell.date}><div className="calendar-day-heading"><strong>{cell.day}</strong>{canManage && cell.current && <button type="button" className="calendar-day-add" title={`Add event on ${cell.date}`} aria-label={`Add event on ${cell.date}`} onClick={() => chooseDate(cell.date)}><Plus size={13} /></button>}</div><span>{cell.events.slice(0, 3).map((event) => <i className={`calendar-event-pill is-${event.type}`} key={event.id}>{eventLabels[event.type]}</i>)}{cell.events.length > 3 && <small>+{cell.events.length - 3} more</small>}</span></div>)}</div>
        </section>

        {canManage && <form className="panel calendar-event-form" onSubmit={submit}>
            <div className="panel-heading"><div><span className="bento-eyebrow">Calendar entry</span><h2>{editingEvent ? 'Edit event' : 'Add event'}</h2></div><CalendarDays size={22} /></div>
            <label>Event title<input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} required /></label>
            <div className="form-pair"><label>Type<select value={form.data.type} onChange={(e) => { const type = e.target.value; form.setData((current) => ({ ...current, type, is_paid: type === 'regular_holiday' ? true : current.is_paid })); }}>{Object.entries(eventLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></label><label>Coverage<select value={form.data.scope_type} onChange={(e) => form.setData('scope_type', e.target.value)}><option value="campus">Entire campus</option><option value="department">Department</option><option value="faculty">Selected faculty</option></select></label></div>
            <div className="form-pair"><label>Start date<input type="date" value={form.data.start_date} onChange={(e) => form.setData('start_date', e.target.value)} required /></label><label>End date<input type="date" min={form.data.start_date || undefined} value={form.data.end_date} onChange={(e) => form.setData('end_date', e.target.value)} required /></label></div>
            {form.data.scope_type === 'department' && <label>Department<select value={form.data.department} onChange={(e) => form.setData('department', e.target.value)} required><option value="">Select department</option>{departments.map((department) => <option key={department}>{department}</option>)}</select></label>}
            {form.data.scope_type === 'faculty' && <label>Faculty<select multiple className="calendar-faculty-select" value={form.data.employee_ids.map(String)} onChange={(e) => form.setData('employee_ids', [...e.target.selectedOptions].map((option) => Number(option.value)))} required>{employees.map((employee) => <option value={employee.id} key={employee.id}>{fullName(employee)}</option>)}</select><small>Hold Ctrl to select multiple faculty members.</small></label>}
            <label>Remarks<textarea rows="2" value={form.data.remarks} onChange={(e) => form.setData('remarks', e.target.value)} /></label>
            <label className="check-label"><input type="checkbox" checked={form.data.is_paid} disabled={form.data.type === 'regular_holiday'} onChange={(e) => form.setData('is_paid', e.target.checked)} />{form.data.type === 'regular_holiday' ? 'Paid scheduled day (required)' : 'Paid scheduled day'}</label>
            <div className="form-actions">{editingEvent && <button type="button" className="secondary" onClick={reset}>Cancel</button>}<button disabled={form.processing}><Save size={17} />Save Event</button></div>
        </form>}

        <section className="panel calendar-event-list"><div className="panel-heading"><h2>{monthLabel} Events</h2><span>{visibleEvents.length} event(s)</span></div>{visibleEvents.length ? visibleEvents.map((event) => <article className={`calendar-event-item is-${event.type}`} key={event.id}><span className="calendar-event-date"><strong>{new Date(`${event.start_date.slice(0, 10)}T00:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })}</strong><small>{event.start_date.slice(0, 10) === event.end_date.slice(0, 10) ? 'One day' : `to ${event.end_date.slice(0, 10)}`}</small></span><div><span>{eventLabels[event.type]}</span><h3>{event.title}</h3><p>{event.scope_type === 'campus' ? 'Entire campus' : event.scope_type === 'department' ? event.department : `${event.employees.length} selected faculty`} · {event.is_paid ? 'Paid' : 'Not paid'}</p></div>{canManage && <div className="row-actions"><button type="button" title="Edit" onClick={() => editEvent(event)}><Edit3 size={16} /></button><button type="button" title="Delete" onClick={() => remove(event)}><Trash2 size={16} /></button></div>}</article>) : <p className="empty-row">No events in this month.</p>}</section>
        {dialog}
    </AppLayout>;
}
