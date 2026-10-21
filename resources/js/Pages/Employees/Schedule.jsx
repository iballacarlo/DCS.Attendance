import { Link, useForm } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, Check, ChevronLeft, ChevronRight, Clock3, Edit3, GraduationCap, MousePointer2, Plus, Save, Trash2, X } from 'lucide-react';
import { useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import { fullName, time12 } from '../../lib/format';

const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const types = { class: 'Class', consultation: 'Consultation', lunch_break: 'Lunch break' };
const minutes = (time) => { const [hours, mins] = time.split(':').map(Number); return hours * 60 + mins; };
const clock = (value) => `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
const blank = (day) => ({ day_of_week: day, schedule_type: 'class', start_time: '08:00', end_time: '09:00', subject: '', section: '', class_mode: 'lecture' });
const scheduleBlocks = (employee) => [...(employee.schedules || []), ...(employee.schedule_breaks || [])].map((block) => ({
    ...block, start_time: block.start_time.slice(0, 5), end_time: block.end_time.slice(0, 5), subject: block.subject || '', section: block.section || '', class_mode: block.class_mode || '',
}));
const hoursLabel = (blocks) => {
    const hours = blocks.filter((block) => block.schedule_type === 'class').reduce((sum, block) => sum + minutes(block.end_time) - minutes(block.start_time), 0) / 60;
    return `${Number(hours.toFixed(2))} ${hours === 1 ? 'hour' : 'hours'}`;
};

export default function EmployeeSchedule({ employee, readOnly = false, scheduleOptions }) {
    const { subjects, sections, classModes } = scheduleOptions;
    const blockTypeLabel = (block) => block.schedule_type === 'class' ? (classModes[block.class_mode] || 'Class · Not specified') : types[block.schedule_type];
    const form = useForm({ blocks: scheduleBlocks(employee) });
    const [day, setDay] = useState(() => Number(form.data.blocks.slice().sort((a, b) => a.day_of_week - b.day_of_week)[0]?.day_of_week) || 1);
    const [draft, setDraft] = useState(() => blank(day));
    const [editing, setEditing] = useState(null);
    const [anchor, setAnchor] = useState(null);
    const [error, setError] = useState('');
    const editor = useRef(null);
    const subjectInput = useRef(null);
    const { ask, dialog } = useConfirmDialog();
    const draftChanged = JSON.stringify(draft) !== JSON.stringify(editing === null ? blank(day) : form.data.blocks[editing]);
    const dayBlocks = form.data.blocks.map((block, index) => ({ ...block, index })).filter((block) => Number(block.day_of_week) === day).sort((a, b) => a.start_time.localeCompare(b.start_time));
    const firstMinute = Math.min(7 * 60, ...dayBlocks.map((block) => Math.floor(minutes(block.start_time) / 60) * 60));
    const lastMinute = Math.max(21 * 60, ...dayBlocks.map((block) => Math.ceil(minutes(block.end_time) / 60) * 60));
    const slots = Array.from({ length: (lastMinute - firstMinute) / 30 }, (_, i) => clock(firstMinute + i * 30));
    const reset = (nextDay = day) => { setEditing(null); setDraft(blank(nextDay)); setAnchor(null); setError(''); };
    const focusEditor = () => { editor.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); subjectInput.current?.focus({ preventScroll: true }); };
    const discardDraft = async () => !draftChanged || await ask({ title: 'Discard block edits?', message: 'This block has changes that have not been added to the schedule yet.', confirmLabel: 'Discard Edits', tone: 'warning' });
    const selectDay = async (nextDay) => {
        if (nextDay === day || !await discardDraft()) return;
        setDay(nextDay); reset(nextDay);
    };
    const edit = async (index) => {
        if (editing === index) { focusEditor(); return; }
        if (!await discardDraft()) return;
        setEditing(index); setDraft({ ...form.data.blocks[index] }); setError(''); setAnchor(null); focusEditor();
    };
    const newBlock = async () => { if (await discardDraft()) { reset(); focusEditor(); } };
    const plot = async (slot) => {
        if (readOnly) return;
        if (anchor !== null) {
            const start = Math.min(minutes(anchor), minutes(slot));
            const end = Math.max(minutes(anchor), minutes(slot)) + 30;
            setDraft((current) => ({ ...current, start_time: clock(start), end_time: clock(end) }));
            setAnchor(null); focusEditor(); return;
        }
        if (!await discardDraft()) return;
        setEditing(null); setAnchor(slot); setError('');
        setDraft({ ...blank(day), start_time: slot, end_time: clock(minutes(slot) + 30) });
    };
    const add = () => {
        if (!draft.start_time || !draft.end_time || draft.end_time <= draft.start_time) { setError('Choose an end time after the start time.'); return; }
        const existing = editing !== null ? form.data.blocks[editing] : null;
        if (draft.schedule_type === 'class' && ['subject', 'section', 'class_mode'].some((field) => !draft[field].trim() && (!existing || existing[field]))) {
            setError('Select the subject, section, and lecture/laboratory type for this class.'); subjectInput.current?.focus(); return;
        }
        if (form.data.blocks.some((block, index) => index !== editing && Number(block.day_of_week) === Number(draft.day_of_week) && draft.start_time < block.end_time && draft.end_time > block.start_time)) {
            setError('This time overlaps another class, consultation, or break.'); return;
        }
        const block = { ...draft };
        if (editing !== null && (existing.schedule_type === 'lunch_break') !== (draft.schedule_type === 'lunch_break')) delete block.id;
        form.setData('blocks', editing === null ? [...form.data.blocks, block] : form.data.blocks.map((item, index) => index === editing ? block : item));
        setDay(Number(block.day_of_week)); reset(Number(block.day_of_week));
    };
    const remove = async (block) => {
        if (!await ask({ title: 'Remove schedule block?', message: `${block.subject || types[block.schedule_type]} on ${days[block.day_of_week - 1]}, ${time12(block.start_time)} – ${time12(block.end_time)}. Save the schedule to apply this removal.`, confirmLabel: 'Remove Block', tone: 'danger' })) return;
        if (editing !== block.index && !await discardDraft()) return;
        form.setData('blocks', form.data.blocks.filter((_, index) => index !== block.index)); reset();
    };
    const save = (event) => {
        event.preventDefault();
        if (draftChanged) { setError(`Select “${editing === null ? 'Add Block' : 'Apply Changes'}” first, or cancel the block edits before saving.`); focusEditor(); return; }
        form.put(`/employees/${employee.id}/schedule`, { preserveScroll: true, onSuccess: (page) => {
            const blocks = scheduleBlocks(page.props.employee);
            form.defaults({ blocks }); form.setData({ blocks }); reset();
        } });
    };

    return <AppLayout title={readOnly ? 'My Schedule' : 'Manage Schedule'} subtitle={`${fullName(employee)} · ${employee.employee_no}`}>
        <form className={`schedule-designer${readOnly ? ' is-readonly' : ''}`} onSubmit={save}>
            <header className="sd-heading">
                <div><span className="sd-eyebrow"><CalendarDays size={16} />Faculty plotting</span><h2>Weekly schedule</h2><p>{readOnly ? 'Choose a day to view your assigned classes and other activities.' : 'Choose a day, add your class details, then save your schedule.'}</p></div>
                {!readOnly && <Link className="sd-back" href="/employees"><ArrowLeft size={16} />Back to Faculty</Link>}
            </header>
            <div className="sd-overview"><span><GraduationCap size={18} /><strong>{form.data.blocks.filter((block) => block.schedule_type === 'class').length}</strong> classes / week</span><span><Clock3 size={18} /><strong>{hoursLabel(form.data.blocks)}</strong> of class time / week</span><span><CalendarDays size={18} /><strong>{new Set(form.data.blocks.map((block) => Number(block.day_of_week))).size}</strong> scheduled days</span></div>
            <nav className="sd-day-tabs" aria-label="Schedule days">{days.map((name, index) => <button className={`sd-control sd-day-tab${day === index + 1 ? ' is-active' : ''}`} type="button" key={name} aria-current={day === index + 1 ? 'true' : undefined} onClick={() => selectDay(index + 1)}><span>{name}</span><small>{form.data.blocks.filter((block) => Number(block.day_of_week) === index + 1).length} blocks</small></button>)}</nav>
            <div className="sd-workspace">
                <section className="sd-calendar" aria-label={`${days[day - 1]} timetable`}>
                    <div className="sd-section-heading"><div><h3>{days[day - 1]}</h3><p>{dayBlocks.length} blocks · {hoursLabel(dayBlocks)} of class time</p></div><div className="sd-day-arrows"><button className="sd-control sd-icon-button" type="button" disabled={day === 1} onClick={() => selectDay(day - 1)} aria-label="Previous day"><ChevronLeft size={18} /></button><button className="sd-control sd-icon-button" type="button" disabled={day === 6} onClick={() => selectDay(day + 1)} aria-label="Next day"><ChevronRight size={18} /></button></div></div>
                    <div className="sd-legend"><span><i className="is-class" />Lecture</span><span><i className="is-laboratory" />Laboratory</span><span><i className="is-consultation" />Consultation</span><span><i className="is-lunch_break" />Break</span></div>
                    {!readOnly && <div className={`sd-plot-hint${anchor ? ' is-selecting' : ''}`} role="status"><MousePointer2 size={15} />{anchor ? `Start: ${time12(anchor)}. Select the last time slot for this block.` : 'Click two empty time slots to plot a block, or select a class to edit.'}{anchor && <button className="sd-control sd-text-button" type="button" onClick={() => reset()}>Cancel</button>}</div>}
                    <div className="sd-timeline-scroll" key={day}><div className="sd-timeline" style={{ height: slots.length * 40 }}>
                        {slots.map((slot) => <div className="sd-time-row" key={slot}><span className="sd-time-label">{time12(slot)}</span>{readOnly ? <span className="sd-empty-slot" /> : <button className={`sd-control sd-empty-slot${anchor === slot ? ' is-anchor' : ''}`} type="button" onClick={() => plot(slot)} aria-label={`Plot ${days[day - 1]} at ${time12(slot)}`}><Plus size={13} /></button>}</div>)}
                        {dayBlocks.map((block) => {
                            const style = { top: ((minutes(block.start_time) - firstMinute) / 30) * 40, height: Math.max(30, ((minutes(block.end_time) - minutes(block.start_time)) / 30) * 40 - 3) };
                            const content = <><span className="sd-block-type">{blockTypeLabel(block)}</span><strong>{block.subject || (block.schedule_type === 'class' ? 'Not specified' : types[block.schedule_type])}</strong><span>{block.schedule_type === 'class' ? `${block.section || 'Not specified'} · ` : ''}{time12(block.start_time)} – {time12(block.end_time)}</span>{!readOnly && <Edit3 size={14} className="sd-block-edit-icon" />}</>;
                            const compact = minutes(block.end_time) - minutes(block.start_time) <= 30 ? ' is-short' : '';
                            const title = `${blockTypeLabel(block)} · ${block.subject || types[block.schedule_type]} · ${block.section || ''} · ${time12(block.start_time)} – ${time12(block.end_time)}`;
                            return readOnly ? <div key={block.index} className={`sd-timeline-block is-${block.schedule_type}${block.class_mode === 'laboratory' ? ' is-laboratory' : ''}${compact}`} style={style} title={title}>{content}</div> : <button className={`sd-control sd-timeline-block is-${block.schedule_type}${block.class_mode === 'laboratory' ? ' is-laboratory' : ''}${compact}${editing === block.index ? ' is-editing' : ''}`} type="button" key={block.index} style={style} title={title} onClick={() => edit(block.index)} aria-label={`Edit ${block.subject || types[block.schedule_type]}, ${time12(block.start_time)} to ${time12(block.end_time)}`}>{content}</button>;
                        })}
                    </div></div>
                </section>
                {!readOnly && <section className="sd-editor" ref={editor}>
                    <div className="sd-editor-heading"><span className="sd-editor-icon">{editing === null ? <Plus size={21} /> : <Edit3 size={21} />}</span><div><h3>{editing === null ? 'Add a block' : 'Edit block'}</h3><p>{editing === null ? 'Fill in the details or select time slots.' : 'Update the details, then apply your changes.'}</p></div></div>
                    <div className="sd-editor-fields">
                        <label>Day<select value={draft.day_of_week} onChange={(e) => setDraft({ ...draft, day_of_week: Number(e.target.value) })}>{days.map((name, index) => <option key={name} value={index + 1}>{name}</option>)}</select></label>
                        <label>Block type<select value={draft.schedule_type} onChange={(e) => setDraft({ ...draft, schedule_type: e.target.value })}>{Object.entries(types).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
                        <div className="sd-time-inputs"><label>Start time<input type="time" value={draft.start_time} onChange={(e) => setDraft({ ...draft, start_time: e.target.value })} /></label><span>to</span><label>End time<input type="time" value={draft.end_time} onChange={(e) => setDraft({ ...draft, end_time: e.target.value })} /></label></div>
                        {draft.schedule_type === 'class' ? <><label>Class delivery<select value={draft.class_mode} onChange={(e) => setDraft({ ...draft, class_mode: e.target.value })}><option value="">Select lecture or laboratory</option>{Object.entries(classModes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label><label>Subject<span className="sd-required">Required for new classes</span><select ref={subjectInput} value={draft.subject} onChange={(e) => setDraft({ ...draft, subject: e.target.value })}><option value="">Select subject</option>{draft.subject && !subjects.includes(draft.subject) && <option value={draft.subject}>{draft.subject} (existing)</option>}{subjects.map((subject) => <option key={subject} value={subject}>{subject}</option>)}</select></label><label>Section<select value={draft.section} onChange={(e) => setDraft({ ...draft, section: e.target.value })}><option value="">Select section</option>{draft.section && !sections.includes(draft.section) && <option value={draft.section}>{draft.section} (existing)</option>}{[1, 2, 3, 4].map((year) => <optgroup key={year} label={`BSIT ${year} — Year ${year}`}>{sections.filter((section) => section.startsWith(`BSIT ${year}`)).map((section) => <option key={section} value={section}>{section}</option>)}</optgroup>)}</select></label></> : <p className="sd-editor-note">{types[draft.schedule_type]} is shown in the schedule and does not count toward paid class hours.</p>}
                    </div>
                    {error && <p className="sd-error" role="alert">{error}</p>}
                    <div className="sd-editor-actions"><button className="sd-control sd-button sd-primary" type="button" onClick={add}>{editing === null ? <Plus size={17} /> : <Check size={17} />}{editing === null ? 'Add Block' : 'Apply Changes'}</button>{(editing !== null || draftChanged) && <button className="sd-control sd-button sd-secondary" type="button" onClick={() => reset()}><X size={16} />Cancel</button>}</div>
                    <p className="sd-editor-footnote">Added blocks are saved when you select <strong>Save Schedule</strong>.</p>
                </section>}
            </div>
            <section className="sd-agenda"><div className="sd-section-heading"><div><h3>{days[day - 1]} blocks</h3><p>All classes and activities for the selected day.</p></div>{!readOnly && <button className="sd-control sd-button sd-secondary" type="button" onClick={newBlock}><Plus size={16} />New Block</button>}</div>
                {dayBlocks.length ? <div className="sd-agenda-list">{dayBlocks.map((block) => <article className={`sd-agenda-item is-${block.schedule_type}${block.class_mode === 'laboratory' ? ' is-laboratory' : ''}${editing === block.index ? ' is-editing' : ''}`} key={block.index}><span className="sd-agenda-marker" /><div className="sd-agenda-time"><strong>{time12(block.start_time)}</strong><span>{time12(block.end_time)}</span></div><div className="sd-agenda-details"><small>{blockTypeLabel(block)}</small><h4>{block.subject || (block.schedule_type === 'class' ? 'Not specified' : types[block.schedule_type])}</h4><span>{block.schedule_type === 'class' ? block.section || 'Not specified' : 'No paid class hours'}</span></div>{!readOnly && <div className="sd-row-actions"><button className="sd-control sd-button sd-secondary" type="button" onClick={() => edit(block.index)} aria-label={`Edit ${block.subject || types[block.schedule_type]}`}><Edit3 size={15} />Edit</button><button className="sd-control sd-button sd-remove" type="button" onClick={() => remove(block)} aria-label={`Remove ${block.subject || types[block.schedule_type]}`}><Trash2 size={15} />Remove</button></div>}</article>)}</div> : <div className="sd-empty-state"><CalendarDays size={28} /><h4>No blocks on {days[day - 1]}</h4><p>{readOnly ? 'No classes or activities have been assigned for this day.' : 'Add your first class using the editor or select a time on the timetable.'}</p></div>}
            </section>
            {Object.entries(form.errors).map(([key, message]) => <p className="sd-error" role="alert" key={key}>{message}</p>)}
            {!readOnly && <footer className="sd-save-bar"><div className={form.isDirty || draftChanged ? 'has-changes' : ''} role="status" aria-live="polite"><span className="sd-save-indicator" /><strong>{draftChanged ? 'Block edits waiting to be applied' : form.isDirty ? 'Unsaved schedule changes' : 'Schedule is up to date'}</strong><small>{draftChanged ? 'Add or apply the block details first, then save the schedule.' : form.isDirty ? 'Save to apply your added, edited, or removed blocks.' : 'Changes will appear in the faculty’s schedule after saving.'}</small></div><button className="sd-control sd-button sd-primary" type="submit" disabled={form.processing || (!form.isDirty && !draftChanged)}><Save size={17} />{form.processing ? 'Saving…' : 'Save Schedule'}</button></footer>}
        </form>
        {dialog}
    </AppLayout>;
}
