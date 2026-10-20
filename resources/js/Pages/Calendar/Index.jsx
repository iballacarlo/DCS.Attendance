import { router, useForm } from '@inertiajs/react';
import { CalendarDays, Edit3, Plus, Save, Trash2, UserRoundCheck } from 'lucide-react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import { fullName } from '../../lib/format';

const blankEvent = { title: '', type: 'regular_holiday', start_date: '', end_date: '', is_paid: true, scope_type: 'campus', department: '', employee_ids: [], remarks: '' };
const blankSignatory = { name: '', designation: 'Dean / In Charge', effective_from: '', effective_to: '' };
const eventLabels = { regular_holiday: 'Regular Holiday', special_holiday: 'Special Holiday', asynchronous: 'Asynchronous Day', suspension: 'Suspension' };

export default function CalendarIndex({ year, events, employees, departments, signatories, canManage }) {
    const [editingEvent, setEditingEvent] = useState(null);
    const [editingSignatory, setEditingSignatory] = useState(null);
    const eventForm = useForm(blankEvent);
    const signatoryForm = useForm(blankSignatory);
    const editEvent = (event) => {
        setEditingEvent(event.id);
        eventForm.setData({
            title: event.title, type: event.type, start_date: event.start_date.slice(0, 10), end_date: event.end_date.slice(0, 10),
            is_paid: Boolean(event.is_paid), scope_type: event.scope_type, department: event.department || '',
            employee_ids: event.employees.map((employee) => employee.id), remarks: event.remarks || '',
        });
    };
    const submitEvent = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { eventForm.setData(blankEvent); setEditingEvent(null); } };
        editingEvent ? eventForm.put(`/school-calendar/events/${editingEvent}`, options) : eventForm.post('/school-calendar/events', options);
    };
    const editSignatory = (item) => {
        setEditingSignatory(item.id);
        signatoryForm.setData({ name: item.name, designation: item.designation, effective_from: item.effective_from.slice(0, 10), effective_to: item.effective_to?.slice(0, 10) || '' });
    };
    const submitSignatory = (e) => {
        e.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { signatoryForm.setData(blankSignatory); setEditingSignatory(null); } };
        editingSignatory ? signatoryForm.put(`/school-calendar/signatories/${editingSignatory}`, options) : signatoryForm.post('/school-calendar/signatories', options);
    };
    const changeYear = (next) => router.get('/school-calendar', { year: next }, { preserveState: true });

    return <AppLayout title="School Calendar" subtitle="Manage paid academic events and the authorized DTR signatory.">
        <div className="calendar-year-toolbar"><button type="button" onClick={() => changeYear(year - 1)}>Previous</button><strong>{year}</strong><button type="button" onClick={() => changeYear(year + 1)}>Next</button></div>
        {canManage && <section className="calendar-management-grid">
            <form className="panel calendar-event-form" onSubmit={submitEvent}>
                <div className="panel-heading"><div><span className="bento-eyebrow">Paid attendance rule</span><h2>{editingEvent ? 'Edit calendar event' : 'Add calendar event'}</h2></div><CalendarDays size={22} /></div>
                <label>Event title<input value={eventForm.data.title} onChange={(e) => eventForm.setData('title', e.target.value)} required /></label>
                <div className="form-pair"><label>Type<select value={eventForm.data.type} onChange={(e) => { const type = e.target.value; eventForm.setData((current) => ({ ...current, type, is_paid: type === 'regular_holiday' ? true : current.is_paid })); }}>{Object.entries(eventLabels).map(([value, label]) => <option value={value} key={value}>{label}</option>)}</select></label><label>Coverage<select value={eventForm.data.scope_type} onChange={(e) => eventForm.setData('scope_type', e.target.value)}><option value="campus">Entire campus</option><option value="department">Department</option><option value="faculty">Selected faculty</option></select></label></div>
                <div className="form-pair"><label>Start date<input type="date" value={eventForm.data.start_date} onChange={(e) => eventForm.setData('start_date', e.target.value)} required /></label><label>End date<input type="date" min={eventForm.data.start_date || undefined} value={eventForm.data.end_date} onChange={(e) => eventForm.setData('end_date', e.target.value)} required /></label></div>
                {eventForm.data.scope_type === 'department' && <label>Department<select value={eventForm.data.department} onChange={(e) => eventForm.setData('department', e.target.value)} required><option value="">Select department</option>{departments.map((department) => <option key={department}>{department}</option>)}</select></label>}
                {eventForm.data.scope_type === 'faculty' && <label>Faculty<select multiple className="calendar-faculty-select" value={eventForm.data.employee_ids.map(String)} onChange={(e) => eventForm.setData('employee_ids', [...e.target.selectedOptions].map((option) => Number(option.value)))} required>{employees.map((employee) => <option value={employee.id} key={employee.id}>{fullName(employee)}</option>)}</select><small>Hold Ctrl to select multiple faculty members.</small></label>}
                <label>Remarks<textarea rows="2" value={eventForm.data.remarks} onChange={(e) => eventForm.setData('remarks', e.target.value)} /></label>
                <label className="check-label"><input type="checkbox" checked={eventForm.data.is_paid} disabled={eventForm.data.type === 'regular_holiday'} onChange={(e) => eventForm.setData('is_paid', e.target.checked)} /> {eventForm.data.type === 'regular_holiday' ? 'Paid scheduled day (required for regular holidays)' : 'Paid scheduled day'}</label>
                <div className="form-actions">{editingEvent && <button type="button" className="secondary" onClick={() => { setEditingEvent(null); eventForm.setData(blankEvent); }}>Cancel</button>}<button disabled={eventForm.processing}><Save size={17} />{eventForm.processing ? 'Saving...' : 'Save Event'}</button></div>
            </form>
            <form className="panel calendar-signatory-form" onSubmit={submitSignatory}>
                <div className="panel-heading"><div><span className="bento-eyebrow">Certification</span><h2>{editingSignatory ? 'Edit signatory period' : 'Add DTR signatory'}</h2></div><UserRoundCheck size={22} /></div>
                <label>Name<input value={signatoryForm.data.name} onChange={(e) => signatoryForm.setData('name', e.target.value)} required /></label>
                <label>Designation<input value={signatoryForm.data.designation} onChange={(e) => signatoryForm.setData('designation', e.target.value)} required /></label>
                <div className="form-pair"><label>Effective from<input type="date" value={signatoryForm.data.effective_from} onChange={(e) => signatoryForm.setData('effective_from', e.target.value)} required /></label><label>Effective to<input type="date" min={signatoryForm.data.effective_from || undefined} value={signatoryForm.data.effective_to} onChange={(e) => signatoryForm.setData('effective_to', e.target.value)} /></label></div>
                <div className="form-actions">{editingSignatory && <button type="button" className="secondary" onClick={() => { setEditingSignatory(null); signatoryForm.setData(blankSignatory); }}>Cancel</button>}<button disabled={signatoryForm.processing}><Plus size={17} />Save Signatory</button></div>
            </form>
        </section>}
        <section className="panel calendar-event-list"><div className="panel-heading"><h2>{year} Calendar Events</h2><span>{events.length} event(s)</span></div>{events.length ? events.map((event) => <article className={`calendar-event-item is-${event.type}`} key={event.id}><span className="calendar-event-date"><strong>{new Date(`${event.start_date.slice(0, 10)}T00:00:00`).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })}</strong><small>{event.start_date.slice(0, 10) === event.end_date.slice(0, 10) ? 'One day' : `to ${event.end_date.slice(0, 10)}`}</small></span><div><span>{eventLabels[event.type]}</span><h3>{event.title}</h3><p>{event.scope_type === 'campus' ? 'Entire campus' : event.scope_type === 'department' ? event.department : `${event.employees.length} selected faculty`} · {event.is_paid ? 'Paid' : 'Not paid'}</p></div>{canManage && <div className="row-actions"><button type="button" title="Edit" onClick={() => editEvent(event)}><Edit3 size={16} /></button><button type="button" title="Delete" onClick={() => window.confirm(`Delete ${event.title}?`) && router.delete(`/school-calendar/events/${event.id}`, { preserveScroll: true })}><Trash2 size={16} /></button></div>}</article>) : <p className="empty-row">No events for this year.</p>}</section>
        <section className="panel signatory-history"><div className="panel-heading"><h2>DTR Signatory History</h2></div>{signatories.length ? signatories.map((item) => <div className="signatory-item" key={item.id}><div><strong>{item.name}</strong><span>{item.designation}</span><small>{item.effective_from.slice(0, 10)} to {item.effective_to?.slice(0, 10) || 'present'}</small></div>{canManage && <div className="row-actions"><button type="button" onClick={() => editSignatory(item)}><Edit3 size={16} /></button><button type="button" onClick={() => window.confirm(`Delete ${item.name}?`) && router.delete(`/school-calendar/signatories/${item.id}`, { preserveScroll: true })}><Trash2 size={16} /></button></div>}</div>) : <p className="empty-row">No DTR signatory has been configured.</p>}</section>
    </AppLayout>;
}
