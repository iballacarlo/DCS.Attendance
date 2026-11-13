import SemesterPicker from '../../components/SemesterPicker';
import '../../../css/semesters.css';
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
const sectionParts = (section = '') => {
    const match = section.match(/^(BSIT|BSCS) ([1-4])([A-F])$/);
    return match ? { course: match[1], year_level: match[2], section_letter: match[3] } : { course: '', year_level: '', section_letter: '' };
};
const withSectionParts = (block) => ({ ...block, ...sectionParts(block.section) });
const cleanDraft = ({ course, year_level, section_letter, building_id, ...block }) => block;
const blank = (day, allowAnyTime = false) => withSectionParts({ day_of_week: day, schedule_type: 'class', start_time: allowAnyTime ? '00:00' : '07:00', end_time: allowAnyTime ? '01:00' : '19:00', subject: '', section: '', building_id: '', room_id: '', class_mode: 'lecture' });
const scheduleBlocks = (employee) => [...(employee.schedules || []), ...(employee.schedule_breaks || [])].map((block) => ({
    ...block, start_time: block.start_time.slice(0, 5), end_time: block.end_time.slice(0, 5), subject: block.subject || '', section: block.section || '', class_mode: block.class_mode || '',
}));
const hoursLabel = (blocks) => {
    const hours = blocks.filter((block) => ['class', 'consultation'].includes(block.schedule_type)).reduce((sum, block) => sum + minutes(block.end_time) - minutes(block.start_time), 0) / 60;
    return `${Number(hours.toFixed(2))} ${hours === 1 ? 'hour' : 'hours'}`;
};

export default function EmployeeSchedule({ hasUnpublishedSchedule = false, employee, readOnly = false, scheduleOptions, facultyOptions = [], selectedSemester = null, scheduleVersions = [], facultyView = false, buildings = [], rooms = [], roomChanges = [], demoMode = false, demoAllowAnyTime = false }) {
    const { url } = usePage();
    const [view, setView] = useState(() => new URLSearchParams(url.split('?')[1]).get('view') === 'daily' ? 'daily' : 'weekly');
    const { subjectsBySection, subjectNames, sections, courses, yearLevels, sectionLetters, classModes, lectureOnlySubjects } = scheduleOptions;
    const roomLabel = (id) => rooms.find((room) => String(room.id) === String(id))?.code || 'Room not assigned';
    const blockTypeLabel = (block) => block.schedule_type === 'class' ? (classModes[block.class_mode] || 'Class · Not specified') : types[block.schedule_type];
    const form = useForm({ blocks: scheduleBlocks(employee), term_id: selectedSemester?.id || '', reason: '' });
    const saveErrors = useRef(null);
    const roomMove = useForm({ faculty_schedule_id: '', session_date: '', building_id: '', room_id: '' });
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
    const [draft, setDraft] = useState(() => blank(day, demoAllowAnyTime));
    const subjects = (subjectsBySection[draft.section] || []).filter((subject) => draft.class_mode !== 'laboratory' || !lectureOnlySubjects.includes(subject));
    const [editing, setEditing] = useState(null);
    const [anchor, setAnchor] = useState(null);
    const [error, setError] = useState('');
    const [switchingFaculty, setSwitchingFaculty] = useState(false);
    const editor = useRef(null);
    const subjectInput = useRef(null);
    const { ask, dialog } = useConfirmDialog();
    const draftChanged = JSON.stringify(cleanDraft(draft)) !== JSON.stringify(editing === null ? cleanDraft(blank(day, demoAllowAnyTime)) : form.data.blocks[editing]);
    const facultyIndex = facultyOptions.findIndex((item) => item.id === employee.id);
    const switchFaculty = async (id) => {
        if (readOnly || switchingFaculty || !id || Number(id) === employee.id) return;
        if ((form.isDirty || draftChanged) && !await ask({ title: 'Leave unsaved schedule?', message: 'Your schedule changes have not been saved. Switching faculty will discard these changes.', confirmLabel: 'Discard and Switch', cancelLabel: 'Stay Here', tone: 'warning' })) return;
        setSwitchingFaculty(true);
        router.get(`/employees/${id}/schedule`, { view, day, term_id: selectedSemester?.id || '' }, { preserveState: false, preserveScroll: true, onFinish: () => setSwitchingFaculty(false) });
    };
    const requestRoom = () => roomMove.post('/my-schedule/room-changes', { preserveScroll: true, onSuccess: () => roomMove.reset() });
    const respondRoom = (id, action) => router.patch(`/my-schedule/room-changes/${id}/respond`, { action }, { preserveScroll: true });
    const cancelRoom = (id) => router.patch(`/my-schedule/room-changes/${id}/cancel`, {}, { preserveScroll: true });
    const dayBlocks = form.data.blocks.map((block, index) => ({ ...block, index })).filter((block) => Number(block.day_of_week) === day).sort((a, b) => a.start_time.localeCompare(b.start_time));
    const firstMinute = demoAllowAnyTime ? 0 : 7 * 60;
    const lastMinute = demoAllowAnyTime ? 24 * 60 : 19 * 60;
    const slots = Array.from({ length: (lastMinute - firstMinute) / 30 }, (_, i) => clock(firstMinute + i * 30));
    const reset = (nextDay = day) => { setEditing(null); setDraft(blank(nextDay, demoAllowAnyTime)); setAnchor(null); setError(''); };
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
        setDraft((current) => withSectionParts({ ...current, ...details }));
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
        const block = form.data.blocks[index];
        const buildingId = rooms.find((room) => String(room.id) === String(block.room_id))?.building_id || '';
        setEditing(index); setDraft(withSectionParts({ ...block, building_id: buildingId, class_mode: block.schedule_type === 'class' ? block.class_mode || 'lecture' : block.class_mode })); setError(''); setAnchor(null); focusEditor();
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
        setDraft({ ...blank(day, demoAllowAnyTime), start_time: slot, end_time: clock(Math.min(minutes(slot) + 30, lastMinute)) });
    };
    const add = () => {
        if (!draft.start_time || !draft.end_time || draft.end_time <= draft.start_time) { setError('Choose an end time after the start time.'); return; }
        if (draft.start_time < (demoAllowAnyTime ? '00:00' : '07:00') || draft.end_time > (demoAllowAnyTime ? '23:59' : '19:00')) { setError(demoAllowAnyTime ? 'Schedule blocks must be within 12:00 AM and 11:59 PM.' : 'Schedule blocks must be within 7:00 AM and 7:00 PM.'); return; }
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
        if (draft.schedule_type === 'class' && !draft.room_id) { setError('Select the room for this class.'); return; }
        if (draft.schedule_type === 'class' && form.data.blocks.some((block, index) => index !== editing && block.schedule_type === 'class' && Number(block.day_of_week) === Number(draft.day_of_week) && draft.start_time < block.end_time && draft.end_time > block.start_time)) {
            setError('This time overlaps another class for this faculty member.'); return;
        }
        const block = cleanDraft(draft);
        if (editing !== null && (existing.schedule_type === 'lunch_break') !== (draft.schedule_type === 'lunch_break')) delete block.id;
        form.setData('blocks', editing === null ? [...form.data.blocks, block] : form.data.blocks.map((item, index) => index === editing ? block : item));
        setDay(Number(block.day_of_week)); reset(Number(block.day_of_week));
    };
    const updateSectionPart = (field, value) => {
        const parts = { course: draft.course, year_level: draft.year_level, section_letter: draft.section_letter, [field]: value };
        const section = parts.course && parts.year_level && parts.section_letter ? `${parts.course} ${parts.year_level}${parts.section_letter}` : '';
        setDraft({ ...draft, ...parts, section, subject: '' });
    };
    const remove = async (block) => {
        if (!await ask({ title: 'Remove schedule block?', message: `${block.subject || types[block.schedule_type]} on ${days[block.day_of_week - 1]}, ${time12(block.start_time)} – ${time12(block.end_time)}. Save the schedule to apply this removal.`, confirmLabel: 'Remove Block', tone: 'danger' })) return;
        if (editing !== block.index && !await discardDraft()) return;
        form.setData('blocks', form.data.blocks.filter((_, index) => index !== block.index)); reset();
    };
    const save = (event) => {
        event.preventDefault();
        if (draftChanged) { setError(`Select “${editing === null ? 'Add Block' : 'Apply Changes'}” first, or cancel the block edits before saving.`); focusEditor(); return; }
        const intent = event.nativeEvent?.submitter?.value || 'draft';
        if (readOnly || form.processing || (intent === 'draft' && !form.isDirty) || (intent === 'publish' && !form.isDirty && !hasUnpublishedSchedule)) return;
        form.transform((data) => ({ ...data, intent }));
        form.put(`/employees/${employee.id}/schedule`, { preserveScroll: true, onError: () => {
            requestAnimationFrame(() => saveErrors.current?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
        }, onSuccess: (page) => {
            const blocks = scheduleBlocks(page.props.employee);
            form.setDefaults({ ...form.data, blocks }); form.setData({ ...form.data, blocks }); reset();
        } });
    };

    return <AppLayout title={readOnly ? 'My Schedule' : 'Manage Schedule'} subtitle={`${fullName(employee)} · ${employee.employee_no}`}>
        <form className={`schedule-designer${readOnly ? ' is-readonly' : ''}`} onSubmit={save} onCopy={copyClassDetails}>
            <header className="sd-heading">
                <div><span className="sd-eyebrow"><CalendarDays size={16} />Faculty plotting</span><h2>Weekly schedule</h2><p>{readOnly ? 'View your weekly plotting or inspect one day at a time.' : 'View the weekly plotting, or switch to Manage by Day to edit and save blocks.'}</p></div>
                {!readOnly && <Link className="sd-back" href="/employees"><ArrowLeft size={16} />Back to Faculty</Link>}
            </header>
            {facultyView && <section className="faculty-room-changes">
                <div><h3>One-session room change</h3><p>Move a future class to a vacant room. If occupied, the other faculty must accept the swap.</p></div>
                <div className="faculty-room-change-fields">
                    <label>Class<select value={roomMove.data.faculty_schedule_id} onChange={(e) => roomMove.setData('faculty_schedule_id', e.target.value)}><option value="">Select class</option>{form.data.blocks.filter((b) => b.schedule_type === 'class' && b.id).map((b) => <option key={b.id} value={b.id}>{days[b.day_of_week - 1]} · {b.subject} · {time12(b.start_time)} · {roomLabel(b.room_id)}</option>)}</select></label>
                    <label>Date<input type="date" value={roomMove.data.session_date} min={new Date().toISOString().slice(0, 10)} onChange={(e) => roomMove.setData('session_date', e.target.value)} /></label>
                    <label>Building<select value={roomMove.data.building_id} onChange={(e) => roomMove.setData({ ...roomMove.data, building_id: e.target.value, room_id: '' })}><option value="">Select building</option>{buildings.map((building) => <option key={building.id} value={building.id}>{building.name}</option>)}</select></label>
                    <label>New room<select disabled={!roomMove.data.building_id} value={roomMove.data.room_id} onChange={(e) => roomMove.setData('room_id', e.target.value)}><option value="">Select room</option>{rooms.filter((room) => String(room.building_id) === String(roomMove.data.building_id)).map((room) => <option key={room.id} value={room.id}>{room.code}</option>)}</select></label>
                    <button type="button" disabled={roomMove.processing || !roomMove.data.faculty_schedule_id || !roomMove.data.session_date || !roomMove.data.room_id} onClick={requestRoom}>Check &amp; request</button>
                </div>
                {Object.values(roomMove.errors).map((message) => <p className="sd-error" key={message}>{message}</p>)}
                {roomChanges.filter((change) => ['pending', 'approved', 'accepted'].includes(change.status)).map((change) => <article className="room-change-request" key={change.id}><div><strong>{change.status === 'pending' && Number(change.swap_employee_id) === Number(employee.id) ? 'Room swap request' : 'Room change'}</strong><span>{change.session_date} · {change.original_room?.code || 'Unassigned'} → {change.room?.code}</span></div><span className={`room-change-status is-${change.status}`}>{change.status}</span>{change.status === 'pending' && Number(change.swap_employee_id) === Number(employee.id) ? <div><button type="button" onClick={() => respondRoom(change.id, 'accept')}>Accept</button><button type="button" className="ui-secondary" onClick={() => respondRoom(change.id, 'reject')}>Reject</button></div> : ['pending', 'approved'].includes(change.status) && Number(change.employee_id) === Number(employee.id) ? <button type="button" className="ui-secondary" onClick={() => cancelRoom(change.id)}>Cancel</button> : null}</article>)}
            </section>}
            
            <div className="semester-schedule-tools">
                {selectedSemester?.status === 'draft' ? <div className="semester-draft-context"><strong>{selectedSemester.label}</strong><Link href={`/settings/semesters?term_id=${selectedSemester.id}`}>Back to semesters</Link></div> : <SemesterPicker value={selectedSemester?.id || ''} legacyOnly compact onChange={(term_id) => router.get(facultyView ? '/my-schedule' : '/employees/' + employee.id + '/schedule', { term_id }, { preserveState: false })} />}
                {selectedSemester?.status === 'closed' && <span className="semester-readonly-status">{selectedSemester.reopened ? 'Reopened for correction' : 'Read only'}</span>}
            {selectedSemester && !readOnly && <>
                {selectedSemester.reopened && <label>Reason for historical correction<input value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} minLength={5} maxLength={1000} required /></label>}
                <button className="sd-control sd-button sd-primary" type="submit" value="publish" disabled={form.processing || draftChanged || (!form.isDirty && !hasUnpublishedSchedule)}>{!form.isDirty && !hasUnpublishedSchedule ? 'Published' : 'Publish schedule'}</button>
            </>}
            </div>
            {facultyView && !scheduleVersions.length && <p>No published schedule is available for this semester yet.</p>}
            <div className="sd-overview"><span><GraduationCap size={18} /><strong>{form.data.blocks.filter((block) => block.schedule_type === 'class').length}</strong> classes / week</span><span><Clock3 size={18} /><strong>{Number(weeklyPlotting(form.data.blocks, { allowAnyTime: demoAllowAnyTime }).payableHours.toFixed(2))}</strong> contact hours / week</span><span><CalendarDays size={18} /><strong>{new Set(form.data.blocks.map((block) => Number(block.day_of_week))).size}</strong> scheduled days</span><span>Consultation: <strong>{Number(weeklyPlotting(form.data.blocks, { allowAnyTime: demoAllowAnyTime }).consultationHours.toFixed(2))} hours / week</strong></span></div>
            <div className="sd-view-toolbar"><nav className="sd-view-tabs" aria-label="Schedule view"><button type="button" className={`sd-control sd-button ${view === 'weekly' ? 'sd-primary' : 'sd-secondary'}`} aria-pressed={view === 'weekly'} onClick={() => setView('weekly')}>Weekly Plotting</button><button type="button" className={`sd-control sd-button ${view === 'daily' ? 'sd-primary' : 'sd-secondary'}`} aria-pressed={view === 'daily'} onClick={() => setView('daily')}>{readOnly ? 'Daily View' : 'Manage by Day'}</button></nav><div className="wp-legend" aria-label="Schedule block colors"><span className="wp-lecture">Lecture</span><span className="wp-laboratory">Laboratory</span><span className="wp-consultation">Consultation</span><span className="wp-lunch_break">Break</span></div>{!readOnly && <div className="sd-faculty-switcher"><FacultyCombobox employees={facultyOptions} selectedId={employee.id} disabled={switchingFaculty || form.processing} label={null} onSelect={switchFaculty} /><div className="sd-faculty-arrows"><button type="button" className="sd-control sd-icon-button" aria-label="Previous faculty" disabled={facultyIndex <= 0 || switchingFaculty || form.processing} onClick={() => switchFaculty(facultyOptions[facultyIndex - 1]?.id)}><ChevronLeft size={18} /></button><button type="button" className="sd-control sd-icon-button" aria-label="Next faculty" disabled={facultyIndex < 0 || facultyIndex >= facultyOptions.length - 1 || switchingFaculty || form.processing} onClick={() => switchFaculty(facultyOptions[facultyIndex + 1]?.id)}><ChevronRight size={18} /></button></div></div>}</div>
            {view === 'weekly' ? <WeeklyPlotting blocks={form.data.blocks} subjectNames={subjectNames} allowAnyTime={demoAllowAnyTime} /> : <div className="sd-daily-view">
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
                            const content = <><span className="sd-block-type">{blockTypeLabel(block)}</span><strong>{block.subject || (block.schedule_type === 'class' ? 'Not specified' : types[block.schedule_type])}</strong><span>{block.schedule_type === 'class' ? `${block.section || 'Not specified'} · ${roomLabel(block.room_id)} · ` : ''}{time12(block.start_time)} – {time12(block.end_time)}</span>{!readOnly && <Edit3 size={14} className="sd-block-edit-icon" />}</>;
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
                        <div className="sd-time-inputs"><label>Start time<input type="time" min={demoAllowAnyTime ? '00:00' : '07:00'} max={demoAllowAnyTime ? '23:59' : '19:00'} value={draft.start_time} onChange={(e) => setDraft({ ...draft, start_time: e.target.value })} /></label><span>to</span><label>End time<input type="time" min={demoAllowAnyTime ? '00:00' : '07:00'} max={demoAllowAnyTime ? '23:59' : '19:00'} value={draft.end_time} onChange={(e) => setDraft({ ...draft, end_time: e.target.value })} /></label></div>
                        {draft.schedule_type === 'class' ? <>
                            <label>Course<select value={draft.course} onChange={(e) => updateSectionPart('course', e.target.value)}><option value="">Select course</option>{Object.entries(courses).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
                            <label>Year level<select disabled={!draft.course} value={draft.year_level} onChange={(e) => updateSectionPart('year_level', e.target.value)}><option value="">Select year</option>{yearLevels.map((year) => <option key={year} value={year}>Year {year}</option>)}</select></label>
                            <label>Section<select disabled={!draft.course || !draft.year_level} value={draft.section_letter} onChange={(e) => updateSectionPart('section_letter', e.target.value)}><option value="">Select section</option>{sectionLetters.map((letter) => <option key={letter} value={letter}>Section {letter}</option>)}</select></label>
                            <label>Subject<select disabled={!draft.section} ref={subjectInput} value={draft.subject} onChange={(e) => setDraft({ ...draft, subject: e.target.value })}><option value="">Select subject</option>{editing !== null && draft.subject === form.data.blocks[editing].subject && draft.section === form.data.blocks[editing].section && draft.subject && !subjects.includes(draft.subject) && !(draft.class_mode === 'laboratory' && lectureOnlySubjects.includes(draft.subject)) && <option value={draft.subject}>{draft.subject} (existing)</option>}{subjects.map((subject) => <option key={subject} value={subject}>{subject}</option>)}</select></label>
                            <label>Building<select value={draft.building_id || ''} onChange={(e) => setDraft({ ...draft, building_id: e.target.value, room_id: '' })}><option value="">Select building</option>{buildings.map((building) => <option key={building.id} value={building.id}>{building.name}</option>)}</select></label>
                            <label>Room<select disabled={!draft.building_id} value={draft.room_id || ''} onChange={(e) => setDraft({ ...draft, room_id: e.target.value })}><option value="">Select room</option>{rooms.filter((room) => String(room.building_id) === String(draft.building_id)).map((room) => <option key={room.id} value={room.id}>{room.code}</option>)}</select></label>
                            <label>Class delivery<select value={draft.class_mode} onChange={(e) => setDraft({ ...draft, class_mode: e.target.value, subject: e.target.value === 'laboratory' && lectureOnlySubjects.includes(draft.subject) ? '' : draft.subject })}>{Object.entries(classModes).map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>
                            {draft.section && !sections.includes(draft.section) && <p className="sd-editor-note">Existing section: {draft.section}. It remains available for historical viewing.</p>}
                        </> : <p className="sd-editor-note">{draft.schedule_type === 'consultation' ? 'Consultation contributes to scheduled DTR hours covered by school IN/OUT. No consultation hardware tap is required.' : 'Lunch and schedule gaps are excluded from DTR hours.'}</p>}
                    </div>
                    {error && <p className="sd-error" role="alert">{error}</p>}
                    <div className="sd-editor-actions"><button className="sd-control sd-button sd-primary" type="button" onClick={add}>{editing === null ? <Plus size={17} /> : <Check size={17} />}{editing === null ? 'Add Block' : 'Apply Changes'}</button>{(editing !== null || draftChanged) && <button className="sd-control sd-button sd-secondary" type="button" onClick={() => reset()}><X size={16} />Cancel</button>}</div>
                    <p className="sd-editor-footnote">Added blocks are saved when you select <strong>Save Schedule</strong>.</p>
                </section>}
            </div>
            <section className="sd-agenda"><div className="sd-section-heading"><div><h3>{days[day - 1]} blocks</h3><p>All classes and activities for the selected day.</p></div>{!readOnly && <button className="sd-control sd-button sd-secondary" type="button" onClick={newBlock}><Plus size={16} />New Block</button>}</div>
                {dayBlocks.length ? <div className="sd-agenda-list">{dayBlocks.map((block) => <article className={`sd-agenda-item is-${block.schedule_type}${block.class_mode === 'laboratory' ? ' is-laboratory' : ''}${editing === block.index ? ' is-editing' : ''}`} key={block.index}><span className="sd-agenda-marker" /><div className="sd-agenda-time"><strong>{time12(block.start_time)}</strong><span>{time12(block.end_time)}</span></div><div className="sd-agenda-details"><small>{blockTypeLabel(block)}</small><h4>{block.subject || (block.schedule_type === 'class' ? 'Not specified' : types[block.schedule_type])}</h4><span>{block.schedule_type === 'class' ? `${block.section || 'Not specified'} · ${roomLabel(block.room_id)}` : block.schedule_type === 'consultation' ? 'DTR schedule basis' : 'Excluded from DTR hours'}</span></div>{!readOnly && <div className="sd-row-actions"><button className="sd-control sd-button sd-secondary" type="button" onClick={() => edit(block.index)} aria-label={`Edit ${block.subject || types[block.schedule_type]}`}><Edit3 size={15} />Edit</button><button className="sd-control sd-button sd-remove" type="button" onClick={() => remove(block)} aria-label={`Remove ${block.subject || types[block.schedule_type]}`}><Trash2 size={15} />Remove</button></div>}</article>)}</div> : <div className="sd-empty-state"><CalendarDays size={28} /><h4>No blocks on {days[day - 1]}</h4><p>{readOnly ? 'No classes or activities have been assigned for this day.' : 'Add your first class using the editor or select a time on the timetable.'}</p></div>}
            </section>
            </div>}
            {Object.keys(form.errors).length > 0 && <div ref={saveErrors} role="alert" aria-label="Schedule save errors">
                <strong>Schedule could not be saved</strong>
                {Object.entries(form.errors).map(([key, message]) => <p className="sd-error" key={key}>{message}</p>)}
            </div>}
            {!readOnly && <footer className="sd-save-bar"><div className={form.isDirty || draftChanged ? 'has-changes' : ''} role="status" aria-live="polite"><span className="sd-save-indicator" /><strong>{draftChanged ? 'Block edits waiting to be applied' : form.isDirty ? 'Unsaved schedule changes' : 'Schedule is up to date'}</strong><small>{draftChanged ? 'Add or apply the block details first, then save the schedule.' : form.isDirty ? 'Save to apply your added, edited, or removed blocks.' : 'Changes will appear in the faculty’s schedule after saving.'}</small></div><button className="sd-control sd-button sd-primary" type="submit" value="draft" disabled={form.processing || !form.isDirty || draftChanged}><Save size={17} />{form.processing ? 'Saving…' : selectedSemester ? 'Save Draft' : 'Save Schedule'}</button></footer>}
        </form>
        {dialog}
    </AppLayout>;
}
