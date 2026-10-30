import YearFilterInput from '../../components/YearFilterInput';
import useConfirmDialog from '../../hooks/useConfirmDialog';
import DateInput from '../../components/DateInput';
import { Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../Layouts/AppLayout';
import SettingsTabs from '../../components/SettingsTabs';
import AttendancePagination from '../../components/AttendancePagination';
import { fullName, formatDate } from '../../lib/format';
import '../../../css/semesters.css';

export default function Semesters({ terms, selectedTerm: term, faculty, history, publishResult = null, startDateLocked = false, today, semesterListing = null, semesterFilters = null, semesterYears = [] }) {
    const semesterView = semesterFilters?.view || (term?.status === 'closed' ? 'closed' : 'current');
    const listedSemesters = semesterListing?.data || terms.filter((item) => (item.status === 'closed') === (semesterView === 'closed'));
    const pendingSchedules = faculty.filter((item) => item.needs_publication).length;
    const currentSemester = terms.find((item) => item.status === 'active');
    const year = Number(new Intl.DateTimeFormat('en', { year: 'numeric', timeZone: 'Asia/Manila' }).format(new Date()));
    const schoolYears = [...new Set([
        ...Array.from({ length: 16 }, (_, index) => `${year - 10 + index}-${year - 9 + index}`),
        ...terms.map((item) => item.school_year),
    ])].sort((a, b) => b.localeCompare(a));
    const statusLabel = (item) => item.reopened ? 'Reopened for correction' : ({ draft: 'Upcoming', scheduled: 'Upcoming', active: 'Active', ended: 'Ended (ready to close)', closed: 'Closed' }[item.status] ?? item.status);
    const form = useForm({ school_year: `${year}-${year + 1}`, semester: 'First Semester', start_date: '', end_date: '' });
    const [firstYear, lastYear] = form.data.school_year.split('-');
    const minDate = `${firstYear}-01-01`;
    const maxDate = `${lastYear}-12-31`;
    const changeAcademicYear = (value) => {
        const [first, last] = value.split('-');
        const withinYear = (date) => !date || (date >= `${first}-01-01` && date <= `${last}-12-31`);
        form.setData({ ...form.data, school_year: value,
            start_date: withinYear(form.data.start_date) ? form.data.start_date : '',
            end_date: withinYear(form.data.end_date) ? form.data.end_date : '',
        });
        form.clearErrors();
    };
    const { ask, dialog } = useConfirmDialog();
    const publishing = useForm({});
    const closing = useForm({ action: 'close' });
    const deleting = useForm({});
    const reopening = useForm({ action: 'reopen', reason: '' });
    const [reopeningTerm, setReopeningTerm] = useState(null);
    const [editingTerm, setEditingTerm] = useState(null);
    const [creating, setCreating] = useState(!terms.length);
    const [historyVisible, setHistoryVisible] = useState(false);
    const closeSemester = async (event) => {
        event.preventDefault();
        if (closing.processing) return;
        const confirmed = await ask({ title: 'Close semester?',
            message: `Close ${term.label}? Its records will become read-only. Reopen the semester before making historical corrections.`,
            confirmLabel: 'Close semester', cancelLabel: 'Cancel', tone: 'danger' });
        if (confirmed) closing.post(`/settings/semesters/${term.id}/transition`, { preserveScroll: true });
    };
    const deleteSemester = async () => {
        if (deleting.processing) return;
        if (await ask({ title: 'Delete upcoming semester?',
            message: `Delete ${term.label}? Its prepared faculty schedules will also be removed. This cannot be undone.`,
            confirmLabel: 'Delete semester', cancelLabel: 'Cancel', tone: 'danger' })) {
            deleting.delete(`/settings/semesters/${term.id}`, { preserveScroll: true });
        }
    };
    const semesterForm = <>
        <form className="semester-fields" onSubmit={(event) => { event.preventDefault(); form[editingTerm ? 'put' : 'post'](editingTerm ? '/settings/semesters/' + editingTerm : '/settings/semesters', { onSuccess: () => { setCreating(false); setEditingTerm(null); } }); }}>
                <label>Academic Year<select value={form.data.school_year} onChange={(e) => changeAcademicYear(e.target.value)} required>{schoolYears.map((value) => <option key={value} value={value}>{value}</option>)}</select></label>
                <label>Semester<select value={form.data.semester} onChange={(e) => form.setData('semester', e.target.value)}>{['First Semester', 'Second Semester', 'Midyear Term'].map((value) => <option key={value}>{value}</option>)}</select></label>
                <label>Start date<DateInput disabled={Boolean(editingTerm && startDateLocked)} min={minDate} max={maxDate} value={form.data.start_date} onChange={(e) => { const start_date = e.target.value; form.setData({ ...form.data, start_date, end_date: form.data.end_date && form.data.end_date < start_date ? '' : form.data.end_date }); form.clearErrors('start_date', 'end_date'); }} required /></label>
                <label>End date<DateInput min={editingTerm && term?.status === 'active' ? today : form.data.start_date || minDate} max={maxDate} value={form.data.end_date} onChange={(e) => form.setData('end_date', e.target.value)} required /></label>
                <button disabled={form.processing}>{editingTerm ? 'Save semester details' : 'Save semester'}</button>
            </form>
        {Object.keys(form.errors).length > 0 && <div role="alert">{Object.entries(form.errors).map(([field, message]) => <p key={field}>{message}</p>)}</div>}
    </>;
    const writable = term && (term.status !== 'closed' || term.reopened);
    return <AppLayout title="Academic Years & Semesters">
        <SettingsTabs />
        <section className="panel semester-panel">
            <div className="panel-heading"><h2>Semesters</h2><button type="button" onClick={() => { setEditingTerm(null); form.reset(); setCreating(!creating); }}>{creating ? 'Hide form' : 'New semester'}</button></div>
            {creating && semesterForm}
            <p><strong>Current semester:</strong> {currentSemester ? <>{currentSemester.label} ({formatDate(currentSemester.start_date)} - {formatDate(currentSemester.end_date)})</> : 'No active semester'}</p>
            <nav className="semester-view-tabs" aria-label="Semester groups">
                <Link href="/settings/semesters?view=current" className={semesterView === 'current' ? 'is-selected' : ''} aria-current={semesterView === 'current' ? 'page' : undefined}>Current &amp; upcoming</Link>
                <Link href="/settings/semesters?view=closed" className={semesterView === 'closed' ? 'is-selected' : ''} aria-current={semesterView === 'closed' ? 'page' : undefined}>Closed semesters</Link>
            </nav>
            {semesterView === 'closed' && <label className="semester-history-year">Academic Year<YearFilterInput academic allowEmpty value={semesterFilters?.academic_year || ''} onYearChange={(year) => router.get('/settings/semesters', { view: 'closed', academic_year: year }, { preserveScroll: true })} /></label>}
            <div className="semester-choices">{listedSemesters.map((item) => <Link key={item.id} href={`/settings/semesters?term_id=${item.id}&view=${semesterView}&academic_year=${semesterFilters?.academic_year || ''}&semesters_page=${semesterListing?.current_page || 1}`} className={item.id === term?.id ? 'is-selected' : ''} aria-current={item.id === term?.id ? 'page' : undefined}>
                <strong>{item.label}</strong><span>{formatDate(item.start_date)} – {formatDate(item.end_date)}</span><small>{statusLabel(item)}</small>
            </Link>)}</div>
            {!listedSemesters.length && <p>{semesterView === 'closed' ? 'No closed semesters for this selection.' : 'No current or upcoming semesters.'}</p>}
            {semesterListing?.last_page > 1 && <nav className="semester-catalog-pages" aria-label="Semester list pages">
                {semesterListing.prev_page_url ? <Link href={semesterListing.prev_page_url} preserveScroll>Previous</Link> : <span aria-disabled="true">Previous</span>}
                <span>Page {semesterListing.current_page} of {semesterListing.last_page}</span>
                {semesterListing.next_page_url ? <Link href={semesterListing.next_page_url} preserveScroll>Next</Link> : <span aria-disabled="true">Next</span>}
            </nav>}
            {term && <div className="semester-selected-details">
                <div className="panel-heading semester-detail-heading"><div><h2>{term.label}</h2><span>{statusLabel(term)}</span></div><div className="semester-detail-actions">
                    {['draft', 'scheduled', 'active'].includes(term.status) && <button type="button" disabled={form.processing || deleting.processing} onClick={() => {
                        form.clearErrors(); setCreating(false);
                        if (editingTerm === term.id) { setEditingTerm(null); return; }
                        setEditingTerm(term.id); form.setData({ school_year: term.school_year, semester: term.semester, start_date: term.start_date.slice(0, 10), end_date: term.end_date.slice(0, 10) });
                    }}>{editingTerm === term.id ? 'Cancel editing' : 'Edit details'}</button>}
                    {['draft', 'scheduled'].includes(term.status) && term.start_date.slice(0, 10) > today && <button type="button" className="semester-delete" disabled={deleting.processing || form.processing} onClick={deleteSemester}>{deleting.processing ? 'Deleting...' : 'Delete semester'}</button>}
                </div></div>
                {editingTerm === term.id && semesterForm}
                {term.status === 'scheduled' && <p>Starts automatically on {formatDate(term.start_date)}.</p>}
                {(term.status === 'active' || term.status === 'ended' || term.reopened) && <form className="semester-activation" onSubmit={closeSemester}>
                    <button type="submit" disabled={closing.processing}>{closing.processing ? 'Closing...' : term.reopened ? 'Finish corrections and close' : 'Close semester'}</button>
                </form>}
                {term.status === 'closed' && !term.reopened && (reopeningTerm === term.id ? <form className="semester-fields" onSubmit={(event) => { event.preventDefault(); reopening.post(`/settings/semesters/${term.id}/transition`, { preserveScroll: true, onSuccess: () => { reopening.reset(); setReopeningTerm(null); } }); }}>
                    <label className="semester-reason">Reason for reopening<input value={reopening.data.reason} onChange={(event) => reopening.setData('reason', event.target.value)} minLength={5} maxLength={1000} required autoFocus /></label>
                    <button type="submit" disabled={reopening.processing}>{reopening.processing ? 'Reopening...' : 'Reopen for correction'}</button>
                    <button type="button" disabled={reopening.processing} onClick={() => { reopening.reset(); reopening.clearErrors(); setReopeningTerm(null); }}>Cancel</button>
                </form> : <button type="button" onClick={() => { reopening.reset(); reopening.clearErrors(); setReopeningTerm(term.id); }}>Reopen semester</button>)}
            </div>}
        </section>
        {term && <>
            <section className="panel semester-panel">
                <div className="panel-heading"><h2>Faculty schedules</h2>{term.status !== 'closed' && <form onSubmit={(event) => { event.preventDefault(); if (!pendingSchedules || publishing.processing) return; publishing.post(`/settings/semesters/${term.id}/publish-all`, { preserveScroll: true }); }}>
                    <button type="submit" disabled={publishing.processing || !pendingSchedules}>{publishing.processing ? 'Publishing schedules...' : pendingSchedules ? 'Publish all schedules' : 'No changes to publish'}</button>
                </form>}</div>
                <p>{faculty.filter((item) => item.published_versions > 0).length} faculty schedules published</p>
                {publishResult && Number(publishResult.term_id) === term.id && <div className="semester-publish-result">
                    <p role="status"><strong>{publishResult.published} published.</strong> {publishResult.unchanged} unchanged; {publishResult.no_draft} without a saved draft{publishResult.ineligible ? `; ${publishResult.ineligible} inactive or archived` : ''}.</p>
                    {publishResult.failed.length > 0 && <div role="alert"><strong>{publishResult.failed.length} schedules need attention:</strong><ul>{publishResult.failed.map((item) => <li key={item.employee_id}>
                        <Link href={`/employees/${item.employee_id}/schedule?term_id=${term.id}`}>{item.faculty}</Link>: {item.messages.join(' ')}
                    </li>)}</ul></div>}
                </div>}
                <div className="table-wrap"><table><thead><tr><th>Faculty</th><th>Published versions</th><th>Actions</th></tr></thead><tbody>{faculty.map((employee) => <tr key={employee.id}>
                    <td>{fullName(employee)}{employee.archived_at && <small> · Archived</small>}</td><td>{employee.published_versions || 'Not published'}</td>
                    <td className="semester-row-actions"><Link href={`/employees/${employee.id}/schedule?term_id=${term.id}`}>{writable ? 'Manage schedule' : 'View history'}</Link>
                    </td>
                </tr>)}</tbody></table></div>
            </section>
            <section className="panel semester-panel"><div className="panel-heading"><h2>Semester change history</h2><button type="button" aria-expanded={historyVisible} aria-controls={`semester-history-${term.id}`} onClick={() => setHistoryVisible(!historyVisible)}>{historyVisible ? 'Hide change history' : 'View change history'}</button></div>
                {historyVisible && <div id={`semester-history-${term.id}`}>
                <div className="table-wrap"><table><thead><tr><th>Recorded</th><th>Action</th><th>Admin ID</th><th>Reason</th></tr></thead><tbody>{history?.data.map((item) => <tr key={item.id}><td>{formatDate(item.created_at)}</td><td>{item.action.replaceAll('_', ' ')}</td><td>{item.actor_id ?? '—'}</td><td>{item.reason}</td></tr>)}</tbody></table></div>
                {history && <AttendancePagination records={history} perPage={history.per_page} onPerPage={(per_page) => router.get('/settings/semesters', { term_id: term.id, per_page, view: semesterView, academic_year: semesterFilters?.academic_year || '', semesters_page: semesterListing?.current_page || 1 }, { preserveState: true, preserveScroll: true })} label="Semester history pages" />}
                </div>}
            </section>
        </>}
        {dialog}
    </AppLayout>;
}
