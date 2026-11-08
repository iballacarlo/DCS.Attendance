import '../../../css/subjects.css';
import { router, useForm } from '@inertiajs/react';
import { BookOpen, Edit3, Plus, Save } from 'lucide-react';
import { useMemo, useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import SettingsTabs from '../../components/SettingsTabs';
import useConfirmDialog from '../../hooks/useConfirmDialog';

const blank = { course_scope: 'BSIT', year_level: '1', semester: 'First Semester', code: '', name: '', lecture_only: false, is_active: true };

export default function Subjects({ subjects, semesters, courseScopes }) {
    const [editing, setEditing] = useState(null);
    const [showForm, setShowForm] = useState(false);
    const [filters, setFilters] = useState({ search: '', course: '', year: '', semester: '', status: 'active' });
    const form = useForm(blank);
    const { ask, dialog } = useConfirmDialog();
    const editingSubject = subjects.find((subject) => subject.id === editing);
    const placementLocked = Boolean(editingSubject?.used_by_schedules);
    const filtered = useMemo(() => subjects.filter((subject) => {
        const search = filters.search.trim().toLowerCase();
        return (!search || `${subject.code} ${subject.name}`.toLowerCase().includes(search))
            && (!filters.course || subject.course_scope === filters.course || subject.course_scope === 'BOTH')
            && (!filters.year || String(subject.year_level) === filters.year)
            && (!filters.semester || subject.semester === filters.semester)
            && (!filters.status || (filters.status === 'active' ? subject.is_active : !subject.is_active));
    }), [subjects, filters]);

    const resetForm = () => { setEditing(null); setShowForm(false); form.setData(blank); form.clearErrors(); };
    const add = () => { resetForm(); setShowForm(true); };
    const edit = (subject) => {
        setEditing(subject.id); setShowForm(true);
        form.setData({ course_scope: subject.course_scope, code: subject.code, name: subject.name, year_level: String(subject.year_level), semester: subject.semester, lecture_only: Boolean(subject.lecture_only), is_active: Boolean(subject.is_active) });
        window.requestAnimationFrame(() => document.getElementById('subject-form')?.scrollIntoView({ behavior: 'smooth', block: 'center' }));
    };
    const submit = (event) => { event.preventDefault(); const options = { preserveScroll: true, onSuccess: resetForm }; editing ? form.put(`/settings/subjects/${editing}`, options) : form.post('/settings/subjects', options); };
    const toggle = async (subject) => {
        const activating = !subject.is_active;
        if (await ask({ title: `${activating ? 'Activate' : 'Deactivate'} subject?`, message: activating ? `${subject.code} will become available when preparing schedules.` : `${subject.code} will be hidden from new schedule selections. Existing schedules and attendance history will remain unchanged.`, confirmLabel: activating ? 'Activate' : 'Deactivate', tone: activating ? 'default' : 'danger' })) router.patch(`/settings/subjects/${subject.id}/status`, {}, { preserveScroll: true });
    };

    return <AppLayout title="Subjects" subtitle="Manage the subjects available when preparing faculty schedules.">
        <SettingsTabs />
        <section className="panel subject-catalog">
            <div className="panel-heading"><div><span className="bento-eyebrow">Academic Setup</span><h2>Subject catalog</h2></div><button type="button" className="ui-control ui-action ui-primary" onClick={showForm ? resetForm : add}>{showForm ? 'Close form' : <><Plus size={17} />Add subject</>}</button></div>
            {showForm && <form id="subject-form" className="subject-form" onSubmit={submit}>
                <div className="subject-form-grid">
                    <label>Course<select value={form.data.course_scope} onChange={(e) => form.setData('course_scope', e.target.value)}>{Object.entries(courseScopes).map(([value, label]) => { const invalid = editingSubject?.used_courses?.some((course) => value !== 'BOTH' && value !== course); return <option key={value} value={value} disabled={invalid}>{label}{invalid ? ' (in use)' : ''}</option>; })}</select>{form.errors.course_scope && <small className="field-error">{form.errors.course_scope}</small>}</label>
                    <label>Year level<select value={form.data.year_level} onChange={(e) => form.setData('year_level', e.target.value)} disabled={placementLocked}>{[1, 2, 3, 4].map((year) => <option key={year} value={year}>Year {year}</option>)}</select>{form.errors.year_level && <small className="field-error">{form.errors.year_level}</small>}</label>
                    <label>Semester<select value={form.data.semester} onChange={(e) => form.setData('semester', e.target.value)} disabled={placementLocked}>{semesters.map((semester) => <option key={semester}>{semester}</option>)}</select>{form.errors.semester && <small className="field-error">{form.errors.semester}</small>}</label>
                    <label>Subject code<input value={form.data.code} onChange={(e) => form.setData('code', e.target.value)} placeholder="e.g. ITEC 120" disabled={placementLocked} required />{form.errors.code && <small className="field-error">{form.errors.code}</small>}</label>
                    <label className="subject-name-field">Subject name<input value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} placeholder="Enter the complete subject name" required />{form.errors.name && <small className="field-error">{form.errors.name}</small>}</label>
                    <label>Class delivery<select value={form.data.lecture_only ? 'lecture' : 'both'} onChange={(e) => form.setData('lecture_only', e.target.value === 'lecture')}><option value="both">Lecture or laboratory</option><option value="lecture">Lecture only</option></select>{form.errors.lecture_only && <small className="field-error">{form.errors.lecture_only}</small>}</label>
                </div>
                <p className="subject-placement-hint">Available to <strong>{form.data.course_scope === 'BOTH' ? 'BSIT and BSCS' : form.data.course_scope} Year {form.data.year_level}, Sections A–F</strong> during the <strong>{form.data.semester}</strong>.{placementLocked && ' Code, year level, and semester can no longer be changed because saved schedules use this subject.'}</p>
                <div className="form-actions"><button type="button" className="ui-control ui-action ui-secondary" onClick={resetForm}>Cancel</button><button className="ui-control ui-action ui-primary" disabled={form.processing}><Save size={17} />{editing ? 'Save changes' : 'Add subject'}</button></div>
            </form>}
            <div className="subject-filters" aria-label="Subject filters">
                <label>Search<input type="search" value={filters.search} onChange={(e) => setFilters({ ...filters, search: e.target.value })} placeholder="Code or subject name" /></label>
                <label>Course<select value={filters.course} onChange={(e) => setFilters({ ...filters, course: e.target.value })}><option value="">All courses</option><option value="BSIT">BSIT</option><option value="BSCS">BSCS</option></select></label>
                <label>Year level<select value={filters.year} onChange={(e) => setFilters({ ...filters, year: e.target.value })}><option value="">All year levels</option>{[1, 2, 3, 4].map((year) => <option key={year} value={year}>Year {year}</option>)}</select></label>
                <label>Semester<select value={filters.semester} onChange={(e) => setFilters({ ...filters, semester: e.target.value })}><option value="">All semesters</option>{semesters.map((semester) => <option key={semester}>{semester}</option>)}</select></label>
                <label>Status<select value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}><option value="">All statuses</option><option value="active">Active</option><option value="inactive">Inactive</option></select></label>
            </div>
            <div className="table-wrap"><table>
                <thead><tr><th>Course</th><th>Code</th><th>Subject name</th><th>Year level</th><th>Semester</th><th>Class delivery</th><th>Status</th><th>Actions</th></tr></thead>
                <tbody>{filtered.length ? filtered.map((subject) => <tr key={subject.id}><td>{subject.course_scope === 'BOTH' ? 'BSIT & BSCS' : subject.course_scope}</td><td><strong>{subject.code}</strong></td><td>{subject.name}</td><td>Year {subject.year_level}</td><td>{subject.semester}</td><td>{subject.lecture_only ? 'Lecture only' : 'Lecture or laboratory'}</td><td><span className={`subject-status ${subject.is_active ? 'active' : 'inactive'}`}>{subject.is_active ? 'Active' : 'Inactive'}</span></td><td><div className="row-actions subject-actions"><button type="button" className="ui-control ui-action ui-secondary" onClick={() => edit(subject)}><Edit3 size={16} />Edit</button><button type="button" className={`ui-control ui-action ${subject.is_active ? 'ui-danger' : 'ui-secondary'}`} disabled={subject.is_active && subject.used_by_open_schedules} title={subject.is_active && subject.used_by_open_schedules ? 'Used by an active or upcoming schedule' : undefined} onClick={() => toggle(subject)}>{subject.is_active && subject.used_by_open_schedules ? 'In use' : subject.is_active ? 'Deactivate' : 'Activate'}</button></div></td></tr>) : <tr><td colSpan="8" className="empty-row"><BookOpen size={20} />No subjects match these filters.</td></tr>}</tbody>
            </table></div>
        </section>{dialog}
    </AppLayout>;
}
