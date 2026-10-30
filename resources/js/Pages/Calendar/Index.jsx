import YearFilterInput from '../../components/YearFilterInput';
import SettingsTabs from '../../components/SettingsTabs';
import { formatDate } from '../../lib/format';
import { entryTypes, holidayEntries, holidayTypes } from '../../lib/holidayEntries';
import DateInput from '../../components/DateInput';
import { router, useForm } from '@inertiajs/react';
import { Edit3, Plus, Save, Trash2, X } from 'lucide-react';
import { useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import useConfirmDialog from '../../hooks/useConfirmDialog';

const blankEntry = { title: '', type: 'suspension', start_date: '', end_date: '', is_paid: false, scope_type: 'campus', department: '', employee_ids: [], remarks: '' };

export default function CalendarIndex({ year, events, canManage, regularHolidays = [] }) {
    const [editing, setEditing] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [type, setType] = useState('');
    const [search, setSearch] = useState('');
    const [page, setPage] = useState(1);
    const form = useForm(blankEntry);
    const formSection = useRef(null);
    const { ask, dialog } = useConfirmDialog();
    const rows = holidayEntries(events, regularHolidays, year, type, search);
    const basePath = canManage ? '/settings/holidays-suspensions' : '/school-calendar';
    const pages = Math.max(1, Math.ceil(rows.length / 10));
    const current = Math.min(page, pages);
    const visible = rows.slice((current - 1) * 10, current * 10);
    const reset = () => { setEditing(null); setShowForm(false); form.setData({ ...blankEntry }); form.clearErrors(); };
    const openForm = (entry = null) => {
        form.clearErrors();
        setEditing(entry?.id || null);
        form.setData(entry ? { title: entry.title, type: entry.type, start_date: entry.start_date.slice(0, 10), end_date: entry.end_date.slice(0, 10), is_paid: Boolean(entry.is_paid), scope_type: entry.scope_type, department: entry.department || '', employee_ids: (entry.employees || []).map((employee) => employee.id), remarks: entry.remarks || '' } : { ...blankEntry });
        setShowForm(true);
        requestAnimationFrame(() => { formSection.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }); formSection.current?.querySelector('input')?.focus({ preventScroll: true }); });
    };
    const submit = (event) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: reset };
        editing ? form.put(`/school-calendar/events/${editing}`, options) : form.post('/school-calendar/events', options);
    };
    const remove = async (entry) => {
        if (await ask({ title: 'Delete entry?', message: `Remove ${entry.title}?`, confirmLabel: 'Delete Entry', tone: 'danger' })) {
            router.delete(`/school-calendar/events/${entry.id}`, { preserveScroll: true, onSuccess: () => { if (editing === entry.id) reset(); } });
        }
    };
    const typeOptions = { ...holidayTypes, ...Object.fromEntries(events.filter((entry) => !holidayTypes[entry.type]).map((entry) => [entry.type, entryTypes[entry.type] || entry.type])) };
    return <AppLayout title="Holidays & Suspensions">
        {canManage && <SettingsTabs />}
        <section className="panel holiday-register">
            <div className="panel-heading"><h2>Holidays &amp; Suspensions</h2>{canManage && <button type="button" className="ui-control ui-action ui-primary" onClick={() => openForm()}><Plus size={17} />Add Entry</button>}</div>
            <div className="holiday-register-filters">
                <label>Year<YearFilterInput value={year} onYearChange={(next) => { reset(); setPage(1); router.get(basePath, { year: next }, { preserveState: true, preserveScroll: true }); }} /></label>
                <label>Type<select value={type} onChange={(event) => { setType(event.target.value); setPage(1); }}><option value="">All types</option>{Object.entries(typeOptions).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
                <label>Search<input type="search" placeholder="Search holidays or suspensions" value={search} onChange={(event) => { setSearch(event.target.value); setPage(1); }} /></label>
            </div>
            {showForm && canManage && <form ref={formSection} className="holiday-entry-form" onSubmit={submit}>
                <div className="holiday-form-heading"><h3>{editing ? 'Edit entry' : 'Add holiday or suspension'}</h3><button type="button" className="ui-control ui-action ui-secondary" aria-label="Close entry form" onClick={reset}><X size={18} /></button></div>
                <label>Title<input maxLength={150} value={form.data.title} onChange={(event) => form.setData('title', event.target.value)} placeholder="e.g. Class suspension due to typhoon" required /></label>
                <label>Type<select value={form.data.type} onChange={(event) => form.setData((previous) => ({ ...previous, type: event.target.value, is_paid: ['regular_holiday', 'official_school_activity'].includes(event.target.value) }))}>{Object.entries({ ...holidayTypes, ...(editing && !holidayTypes[form.data.type] ? { [form.data.type]: entryTypes[form.data.type] || form.data.type } : {}) }).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
                <div className="holiday-form-pair"><label>Start date<DateInput value={form.data.start_date} onChange={(event) => form.setData((previous) => ({ ...previous, start_date: event.target.value, end_date: previous.end_date && previous.end_date >= event.target.value ? previous.end_date : event.target.value }))} required /></label><label>End date<DateInput value={form.data.end_date} min={form.data.start_date || undefined} onChange={(event) => form.setData('end_date', event.target.value)} required /></label></div>
                <label>Remarks<textarea rows={2} value={form.data.remarks} onChange={(event) => form.setData('remarks', event.target.value)} /></label>
                {Object.keys(form.errors).length > 0 && <div className="field-error" role="alert">{Object.values(form.errors).map((error, index) => <p key={index}>{error}</p>)}</div>}
                <div className="form-actions"><button type="button" className="ui-control ui-action ui-secondary" onClick={reset}>Cancel</button><button type="submit" className="ui-control ui-action ui-primary" disabled={form.processing}><Save size={17} />{form.processing ? 'Saving...' : 'Save Entry'}</button></div>
            </form>}
            <div className="table-wrap"><table><thead><tr><th>Date</th><th>Title</th><th>Type</th><th>Coverage</th>{canManage && <th>Actions</th>}</tr></thead><tbody>{visible.length ? visible.map((entry) => <tr key={entry.id}>
                <td>{formatDate(entry.start_date)}{entry.start_date.slice(0, 10) !== entry.end_date.slice(0, 10) && <small>to {formatDate(entry.end_date)}</small>}</td>
                <td><strong>{entry.title}</strong>{entry.remarks && <small>{entry.remarks}</small>}</td><td><span className="badge">{entryTypes[entry.type] || entry.type}</span></td>
                <td>{entry.scope_type === 'campus' ? 'All DCS faculty' : entry.scope_type === 'department' ? entry.department : `${entry.employees?.length || 0} selected faculty`}</td>
                {canManage && <td>{entry.automatic ? '—' : <div className="holiday-row-actions"><button type="button" className="ui-control ui-action ui-secondary" aria-label={`Edit ${entry.title}`} onClick={() => openForm(entry)}><Edit3 size={15} />Edit</button><button type="button" className="ui-control ui-action ui-danger" aria-label={`Delete ${entry.title}`} onClick={() => remove(entry)}><Trash2 size={15} />Delete</button></div>}</td>}
            </tr>) : <tr><td colSpan={canManage ? 5 : 4}>No holidays or suspensions match this view.</td></tr>}</tbody></table></div>
            <div className="holiday-list-footer"><span>{rows.length ? (current - 1) * 10 + 1 : 0}–{Math.min(current * 10, rows.length)} of {rows.length} entries</span>{pages > 1 && <nav className="session-pagination" aria-label="Holidays and suspensions pages"><button type="button" disabled={current === 1} onClick={() => setPage(current - 1)}>Previous</button><span>{current} / {pages}</span><button type="button" disabled={current === pages} onClick={() => setPage(current + 1)}>Next</button></nav>}</div>
        </section>
        {dialog}
    </AppLayout>;
}
