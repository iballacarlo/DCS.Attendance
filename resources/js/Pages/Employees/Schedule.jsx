import FacultyCombobox from '../../components/FacultyCombobox';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, CalendarDays, Check, ChevronLeft, ChevronRight, Clock3, Edit3, GraduationCap, MousePointer2, Plus, Save, Trash2, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import WeeklyPlotting from '../../components/WeeklyPlotting';
import { fullName, time12 } from '../../lib/format';
import { weeklyPlotting } from '../../lib/weeklyPlotting';
import { classDetailsForPaste, decodeClassDetails, encodeClassDetails } from '../../lib/classBlockClipboard';

const days = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
const types = { class: 'Class', consultation: 'Consultation', lunch_break: 'Lunch break' };
const minutes = (time) => { const [hours, mins] = time.split(':').map(Number); return hours * 60 + mins; };
const clock = (value) => `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`;
const blank = (day) => ({ day_of_week: day, schedule_type: 'class', start_time: '07:00', end_time: '19:00', subject: '', section: '', class_mode: 'lecture' });
const scheduleBlocks = (employee) => [...(employee.schedules || []), ...(employee.schedule_breaks || [])].map((block) => ({
    ...block, start_time: block.start_time.slice(0, 5), end_time: block.end_time.slice(0, 5), subject: block.subject || '', section: block.section || '', class_mode: block.class_mode || '',
}));
const hoursLabel = (blocks) => {
    const hours = blocks.filter((block) => ['class', 'consultation'].includes(block.schedule_type)).reduce((sum, block) => sum + minutes(block.end_time) - minutes(block.start_time), 0) / 60;
    return `${Number(hours.toFixed(2))} ${hours === 1 ? 'hour' : 'hours'}`;
};

export default function EmployeeSchedule({ employee, readOnly = false, scheduleOptions, facultyOptions = [] }) {
    const { url } = usePage();
    const [view, setView] = useState(() => new URLSearchParams(url.split('?')[1]).get('view') === 'daily' ? 'daily' : 'weekly');
    const { subjectsBySection, subjectNames, sections, classModes, lectureOnlySubjects } = scheduleOptions;
    const blockTypeLabel = (block) => block.schedule_type === 'class' ? (classModes[block.class_mode] || 'Class · Not specified') : types[block.schedule_type];
    const form = useForm({ blocks: scheduleBlocks(employee) });
    const [day, setDay] = useState(() => {
        const requested = Number(new URLSearchParams(url.split('?')[1]).get('day'));
        return Number.isInteger(requested) && requested >= 1 && requested <= 6 ? requested : Number(form.data.blocks.slice().sort((a, b) => a.day_of_week - b.day_of_week)[0]?.day_of_week) || 1;
    });
    useEffect(() => {
        const current = new URL(window.location.href);
        current.searchParams.set('view', view);
        current.searchParams.set('day', String(day));
        const nextUrl = `${current.pathname}${current.search}${current.hash}`;
        if (nextUrl !== url) router.replace({ url: nextUrl, preserveState: true, preserveScroll: true });
    }, [view, day, url]);
    const [draft, setDraft] = useState(() => blank(day));
    const subjects = (subjectsBySection[draft.section] || []).filter((subject) => draft.class_mode !== 'laboratory' || !lectureOnlySubjects.includes(subject));
    const [editing, setEditing] = useState(null);
    const [anchor, setAnchor] = useState(null);
    const [error, setError] = useState('');
    const [switchingFaculty, setSwitchingFaculty] = useState(false);
    const editor = useRef(null);
    const subjectInput = useRef(null);
    const { ask, dialog } = useConfirmDialog();
    const draftChanged = JSON.stringify(draft) !== JSON.stringify(editing === null ? blank(day) : form.data.blocks[editing]);
    const facultyIndex = facultyOptions.findIndex((item) => item.id === employee.id);
    const switchFaculty = async (id) => {
        if (readOnly || switchingFaculty || !id || Number(id) === employee.id) return;
        if ((form.isDirty || draftChanged) && !await ask({ title: 'Leave unsaved schedule?', message: 'Your schedule changes have not been saved. Switching faculty will discard these changes.', confirmLabel: 'Discard and Switch', cancelLabel: 'Stay Here', tone: 'warning' })) return;
        setSwitchingFaculty(true);
        router.get(`/employees/${id}/schedule`, { view, day }, { preserveState: false, preserveScroll: true, onFinish: () => setSwitchingFaculty(false) });
    };
    const dayBlocks = form.data.blocks.map((block, index) => ({ ...block, index })).filter((block) => Number(block.day_of_week) === day).sort((a, b) => a.start_time.localeCompare(b.start_time));
    const firstMinute = 7 * 60;
    const lastMinute = 19 * 60;
    const slots = Array.from({ length: (lastMinute - firstMinute) / 30 }, (_, i) => clock(firstMinute + i * 30));
    const reset = (nextDay = day) => { setEditing(null); setDraft(blank(nextDay)); setAnchor(null); setError(''); };
    const focusEditor = () => { editor.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); (subjectInput.current && !subjectInput.current.disabled ? subjectInput.current : editor.current)?.focus({ preventScroll: true }); };
    const copyClassDetails = (event) => {
        if (readOnly || editing === null || event.target.closest('input, textarea, [contenteditable="true"]') || window.getSelection()?.toString()) return;
        const text = encodeClassDetails(form.data.blocks[editing]);
        if (!text || !event.clipboardData) return;
        event.clipboardData.setData('text/plain', text);
        event.preventDefault();
    };
    const pasteClassDetails = (event) => {
        if (readOnly) return;
        const copied = decodeClassDetails(event.clipboardData?.getData('text/plain'));
        if (!copied) return;
        const details = classDetailsForPaste(copied, lectureOnlySubjects);
        event.preventDefault();
        if (draft.schedule_type !== 'class') { setError('Choose Class as the block type before pasting class details.'); return; }
        if (!(subjectsBySection[details.section] || []).includes(details.subject) || (details.class_mode === 'laboratory' && lectureOnlySubjects.includes(details.subject))) {
            setError('The copied class details are not valid for the current semester and class delivery.'); return;
        }
        setDraft((current) => ({ ...current, ...details }));
        setError('');
    };
    const discardDraft = async () => !draftChanged || await ask({ title: 'Discard block edits?', message: 'This block has changes that have not been added to the schedule yet.', confirmLabel: 'Discard Edits', tone: 'warning' });
    const selectDay = async (nextDay) => {
        if (nextDay === day || !await discardDraft()) return;
        setDay(nextDay); reset(nextDay);
    };
    const edit = async (index) => {
        if (editing === index) { focusEditor(); return; }
        if (!await discardDraft()) return;
        setEditing(index); setDraft({ ...form.data.blocks[index], class_mode: form.data.blocks[index].schedule_type === 'class' ? form.data.blocks[index].class_mode || 'lecture' : form.data.blocks[index].class_mode }); setError(''); setAnchor(null); focusEditor();
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
        if (draft.start_time < '07:00' || draft.end_time > '19:00') { setError('Schedule blocks must be within 7:00 AM and 7:00 PM.'); return; }
        const existing = editing !== null ? form.data.blocks[editing] : null;
        if (draft.schedule_type === 'class' && draft.class_mode === 'laboratory' && lectureOnlySubjects.includes(draft.subject)) {
            setError('This subject is available for Lecture only.'); return;
        }
        if (draft.schedule_type === 'class' && (!existing || existing.subject !== draft.subject || existing.section !== draft.section) && !subjects.includes(draft.subject)) {
            setError('Select a subject for the chosen section and current semester.'); return;
        }
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
            form.setDefaults({ blocks }); form.setData({ blocks }); reset();
        } });
    };

    return <AppLayout title={readOnly ? 'My Schedule' : 'Manage Schedule'} subtitle={`${fullName(employee)} · ${employee.employee_no}`}>
        <form className={`schedule-designer${readOnly ? ' is-readonly' : ''}`} onSubmit={save} onCopy={copyClassDetails}>
            <header className="sd-heading">
                <div><span className="sd-eyebrow"><CalendarDays size={16} />Faculty plotting</span><h2>Weekly schedule</h2><p>{readOnly ? 'View your weekly plotting or inspect one day at a time.' : 'View the weekly plotting, or switch to Manage by Day to edit and save blocks.'}</p></div>
                {!readOnly && <Link className="sd-back" href="/employees"><ArrowLeft size={16} />Back to Faculty</Link>}
            </header>
            
            <div className="sd-overview"><span><GraduationCap size={18} /><strong>{form.data.blocks.filter((block) => block.schedule_type === 'class').length}</strong> classes / week</span><span><Clock3 size={18} /><strong>{Number(weeklyPlotting(form.data.blocks).payableHours.toFixed(2))}</strong> contact hours / week</span><span><CalendarDays size={18} /><strong>{new Set(form.data.blocks.map((block) => Number(block.day_of_week))).size}</strong> scheduled days</span><span>Consultation: <strong>{Number(weeklyPlotting(form.data.blocks).consultationHours.toFixed(2))} hours / week</strong></span></div>
            <div className="sd-view-toolbar"><nav className="sd-view-tabs" aria-label="Schedule view"><button type="button" className={`sd-control sd-button ${view === 'weekly' ? 'sd-primary' : 'sd-secondary'}`} aria-pressed={view === 'weekly'} onClick={() => setView('weekly')}>Weekly Plotting</button><button type="button" className={`sd-control sd-button ${view === 'daily' ? 'sd-primary' : 'sd-secondary'}`} aria-pressed={view === 'daily'} onClick={() => setView('daily')}>{readOnly ? 'Daily View' : 'Manage by Day'}</button></nav><div className="wp-legend" aria-label="Schedule block colors"><span className="wp-lecture">Lecture</span><span className="wp-laboratory">Laboratory</span><span className="wp-consultation">Consultation</span><span className="wp-lunch_break">Break</span></div>{!readOnly && <div className="sd-faculty-switcher"><FacultyCombobox employees={facultyOptions} selectedId={employee.id} disabled={switchingFaculty || form.processing} label={null} onSelect={switchFaculty} /><div className="sd-faculty-arrows"><button type="button" className="sd-control sd-icon-button" aria-label="Previous faculty" disabled={facultyIndex <= 0 || switchingFaculty || form.processing} onClick={() => switchFaculty(facultyOptions[facultyIndex - 1]?.id)}><ChevronLeft size={18} /></button><button type="button" className="sd-control sd-icon-button" aria-label="Next faculty" disabled={facultyIndex < 0 || facultyIndex >= facultyOptions.length - 1 || switchingFaculty || form.processing} onClick={() => switchFaculty(facultyOptions[facultyIndex + 1]?.id)}><ChevronRight size={18} /></button></div></div>}</div>
            {view === 'weekly' ? <WeeklyPlotting blocks={form.data.blocks} subjectNames={subjectNames} /> : <div className="sd-daily-view">
            <nav className="sd-day-tabs" aria-label="Schedule days">{days.map((name, index) => <button className={`sd-control sd-day-tab${day === index + 1 ? ' is-active' : ''}`} type="button" key={name} aria-current={day === index + 1 ? 'true' : undefined} onClick={() => selectDay(index + 1)}><span>{name}</span><small>{form.data.blocks.filter((block) => Number(block.day_of_week) === index + 1).length} blocks</small></button>)}</nav>
            <div className="sd-workspace">
                <section className="sd-calendar" aria-label={`${days[day - 1]} timetable`}>
                    <div className="sd-section-heading"><div><h3>{days[day - 1]}</h3><p>{dayBlocks.length} blocks · {hoursLabel(dayBlocks)} of scheduled DTR time</p></div><div className="sd-day-arrows"><button className="sd-control sd-icon-button" type="button" disabled={day === 1} onClick={() => selectDay(day - 1)} aria-label="Previous day"><ChevronLeft size={18} /></button><button className="sd-control sd-icon-button" type="button" disabled={day === 6} onClick={() => selectDay(day + 1)} aria-label="Next day"><ChevronRight size={18} /></button></div></div>
                    {!readOnly && <div className={`sd-plot-hint${anchor ? ' is-selecting' : ''}`} role="status"><MousePointer2 size={15} />{anchor ? `Start: ${time12(anchor)}. Select the last time slot for this block.` : 'Click two empty time slots to plot a block, or select a class to edit.'}{anchor && <button className="sd-control sd-text-button" type="button" onClick={() => reset()}>Cancel</button>}</div>}
                    <div className="sd-timeline-scroll" key={day}><div className="sd-timeline" style={{ height: slots.length * 40 }}>
                        {slots.map((slot) => <div className="sd-time-row" key={slot}><span className="sd-time-label">{time12(slot)}</span>{readOnly ? <span className="sd-empty-slot" /> : <button className={`sd-control sd-empty-slot${anchor === slot ? ' is-anchor' : ''}`} type="button" onClick={() => plot(slot)} aria-label={`Plot ${days[day - 1]} at ${time12(slot)}`}><Plus size={13} /></button>}</div>)}
                        {dayBlocks.filter((block) => minutes(block.end_time) > firstMinute && minutes(block.start_time) < lastMinute).map((block) => {
                            const start = Math.max(firstMinute, minutes(block.start_time)), end = Math.min(lastMinute, minutes(block.end_time));
                            const style = { top: ((start - firstMinute) / 30) * 40, height: Math.max(1, ((end - start) / 30) * 40 - 3) };
                            const content = <><span className="sd-block-type">{blockTypeLabel(block)}</span><strong>{block.subject || (block.schedule_type === 'class' ? 'Not specified' : types[block.schedule_type])}</strong><span>{block.schedule_type === 'class' ? `${block.section || 'Not specified'} · ` : ''}{time12(block.start_time)} – {time12(block.end_time)}</span>{!readOnly && <Edit3 size={14} className="sd-block-edit-icon" />}</>;
                            const compact = minutes(block.end_time) - minutes(block.start_time) <= 30 ? ' is-short' : '';
                            const title = `${blockTypeLabel(block)} · ${block.subject || types[block.schedule_type]} · ${block.section || ''} · ${time12(block.start_time)} – ${time12(block.end_time)}`;
                            return readOnly ? <div key={block.index} className={`sd-timeline-block is-${block.schedule_type}${block.class_mode === 'laboratory' ? ' is-laboratory' : ''}${compact}`} style={style} title={title}>{content}</div> : <button className={`sd-control sd-timeline-block is-${block.schedule_type}${block.class_mode === 'laboratory' ? ' is-laboratory' : ''}${compact}${editing === block.index ? ' is-editing' : ''}`} type="button" key={block.index} style={style} title={title} onClick={() => edit(block.index)} aria-label={`Edit ${block.subject || types[block.schedule_type]}, ${time12(block.start_time)} to ${time12(block.end_time)}`}>{content}</button>;
                        })}
                    </div></div>
                </section>
                {!readOnly && <section className="sd-editor" ref={editor} tabIndex={-1} onPaste={pasteClassDetails} onClick={(event) => { if (!event.target.closest('input, select, button, textarea')) event.currentTarget.focus({ preventScroll: true }); }}>
                    <div className="sd-editor-heading"><span className="sd-editor-icon">{editing === null ? <Plus size={21} /> : <Edit3 size={21} />}</span><div><h3>{editing === null ? 'Add a block' : 'Edit block'}</h3><p>{editing === null ? 'Fill in the details or select time slots.' : 'Update the details, then apply your changes.'}</p></div></div>
                    <div className="sd-editor-fields">
                        <label>Day<select value={draft.day_of_week} onChange={(e) => setDraft({ ...draft, day_of_week: Number(e.target.value) })}>{days.map((name, index) => <option key={name} value={index + 1}>{name}</option>)}</select></label>
                        <label>Block type<select value={draft.schedule_type} onChange={(e) => setDraft({ ...draft, schedule_type: e.target.value, class_mode: e.target.value === 'class' ? 'lecture' : '' })}>{Object.entries(types).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
                        <div className="sd-time-inputs"><label>Start time<input type="time" min="07:00" max="19:00" value={draft.start_time} onChange={(e) => setDraft({ ...draft, start_time: e.target.value })} /></label><span>to</span><label>End time<input type="time" min="07:00" max="19:00" value={draft.end_time} onChange={(e) => setDraft({ ...draft, end_time: e.target.value })} /></label></div>
                        {draft.schedule_type === 'class' ? <><label>Class delivery<select value={draft.class_mode} onChange={(e) => setDraft({ ...draft, class_mode: e.target.value, subject: e.target.value === 'laboratory' && lectureOnlySubjects.includes(draft.subject) ? '' : draft.subject })}>{Object.entries(classModes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label><label>Section<select value={draft.section} onChange={(e) => setDraft({ ...draft, section: e.target.value, subject: '' })}><option value="">Select section</option>{draft.section && !sections.includes(draft.section) && <option value={draft.section}>{draft.section} (existing)</option>}{[1, 2, 3, 4].map((year) => <optgroup key={year} label={`${year}${['ST', 'ND', 'RD', 'TH'][year - 1]} YEAR`}>{sections.filter((section) => section.startsWith(`BSIT ${year}`)).map((section) => <option key={section} value={section}>{section}</option>)}</optgroup>)}</select></label><label>Subject<select disabled={!draft.section} ref={subjectInput} value={draft.subject} onChange={(e) => setDraft({ ...draft, subject: e.target.value })}><option value="">Select subject</option>{editing !== null && draft.subject === form.data.blocks[editing].subject && draft.section === form.data.blocks[editing].section && draft.subject && !subjects.includes(draft.subject) && !(draft.class_mode === 'laboratory' && lectureOnlySubjects.includes(draft.subject)) && <option value={draft.subject}>{draft.subject} (existing)</option>}{subjects.map((subject) => <option key={subject} value={subject}>{subject}</option>)}</select></label></> : <p className="sd-editor-note">{draft.schedule_type === 'consultation' ? 'Consultation contributes to scheduled DTR hours covered by school IN/OUT. No consultation hardware tap is required.' : 'Lunch and schedule gaps are excluded from DTR hours.'}</p>}
                    </div>
                    {error && <p className="sd-error" role="alert">{error}</p>}
                    <div className="sd-editor-actions"><button className="sd-control sd-button sd-primary" type="button" onClick={add}>{editing === null ? <Plus size={17} /> : <Check size={17} />}{editing === null ? 'Add Block' : 'Apply Changes'}</button>{(editing !== null || draftChanged) && <button className="sd-control sd-button sd-secondary" type="button" onClick={() => reset()}><X size={16} />Cancel</button>}</div>
                    <p className="sd-editor-footnote">Added blocks are saved when you select <strong>Save Schedule</strong>.</p>
                </section>}
            </div>
            <section className="sd-agenda"><div className="sd-section-heading"><div><h3>{days[day - 1]} blocks</h3><p>All classes and activities for the selected day.</p></div>{!readOnly && <button className="sd-control sd-button sd-secondary" type="button" onClick={newBlock}><Plus size={16} />New Block</button>}</div>
                {dayBlocks.length ? <div className="sd-agenda-list">{dayBlocks.map((block) => <article className={`sd-agenda-item is-${block.schedule_type}${block.class_mode === 'laboratory' ? ' is-laboratory' : ''}${editing === block.index ? ' is-editing' : ''}`} key={block.index}><span className="sd-agenda-marker" /><div className="sd-agenda-time"><strong>{time12(block.start_time)}</strong><span>{time12(block.end_time)}</span></div><div className="sd-agenda-details"><small>{blockTypeLabel(block)}</small><h4>{block.subject || (block.schedule_type === 'class' ? 'Not specified' : types[block.schedule_type])}</h4><span>{block.schedule_type === 'class' ? block.section || 'Not specified' : block.schedule_type === 'consultation' ? 'DTR schedule basis' : 'Excluded from DTR hours'}</span></div>{!readOnly && <div className="sd-row-actions"><button className="sd-control sd-button sd-secondary" type="button" onClick={() => edit(block.index)} aria-label={`Edit ${block.subject || types[block.schedule_type]}`}><Edit3 size={15} />Edit</button><button className="sd-control sd-button sd-remove" type="button" onClick={() => remove(block)} aria-label={`Remove ${block.subject || types[block.schedule_type]}`}><Trash2 size={15} />Remove</button></div>}</article>)}</div> : <div className="sd-empty-state"><CalendarDays size={28} /><h4>No blocks on {days[day - 1]}</h4><p>{readOnly ? 'No classes or activities have been assigned for this day.' : 'Add your first class using the editor or select a time on the timetable.'}</p></div>}
            </section>
            </div>}
            {Object.entries(form.errors).map(([key, message]) => <p className="sd-error" role="alert" key={key}>{message}</p>)}
            {!readOnly && <footer className="sd-save-bar"><div className={form.isDirty || draftChanged ? 'has-changes' : ''} role="status" aria-live="polite"><span className="sd-save-indicator" /><strong>{draftChanged ? 'Block edits waiting to be applied' : form.isDirty ? 'Unsaved schedule changes' : 'Schedule is up to date'}</strong><small>{draftChanged ? 'Add or apply the block details first, then save the schedule.' : form.isDirty ? 'Save to apply your added, edited, or removed blocks.' : 'Changes will appear in the faculty’s schedule after saving.'}</small></div><button className="sd-control sd-button sd-primary" type="submit" disabled={form.processing || (!form.isDirty && !draftChanged)}><Save size={17} />{form.processing ? 'Saving…' : 'Save Schedule'}</button></footer>}
        </form>
        {dialog}
    </AppLayout>;
}
