import { useForm } from '@inertiajs/react';
import { CalendarRange, Save } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';
import SettingsTabs from '../../components/SettingsTabs';

export default function GeneralSettings({ academicTerm }) {
    const form = useForm({
        mode: academicTerm.mode || 'automatic',
        semester: academicTerm.semester || 'First Semester',
        school_year: academicTerm.school_year || '',
    });
    const submit = (event) => {
        event.preventDefault();
        form.put('/settings/general');
    };
    const preview = form.data.mode === 'automatic'
        ? academicTerm.automatic_label
        : `${form.data.semester} SY ${form.data.school_year || 'YYYY-YYYY'}`.toUpperCase();

    return <AppLayout title="Settings" subtitle="Manage academic term, accounts, backups, and data recovery.">
        <SettingsTabs />
        <form className="settings-tool academic-term-settings" onSubmit={submit}>
            <span className="settings-tool-icon"><CalendarRange size={22} /></span>
            <div><span className="bento-eyebrow">Academic Calendar</span><h2>Current academic term</h2><p>The automatic schedule follows First Semester from August to December, Second Semester from January to May, and Midyear from June to July.</p></div>
            <label>Update Mode<select value={form.data.mode} onChange={(event) => form.setData('mode', event.target.value)}><option value="automatic">Automatic</option><option value="manual">Manual override</option></select></label>
            {form.data.mode === 'manual' && <div className="academic-term-fields"><label>Semester<select value={form.data.semester} onChange={(event) => form.setData('semester', event.target.value)}><option>First Semester</option><option>Second Semester</option><option>Midyear Term</option></select></label><label>School Year<input placeholder="2026-2027" value={form.data.school_year} onChange={(event) => form.setData('school_year', event.target.value)} /></label></div>}
            {form.errors.school_year && <div className="form-note">{form.errors.school_year}</div>}
            <div className="academic-term-preview"><span>Displayed term</span><strong>{preview}</strong></div>
            <button className="ui-control ui-action ui-primary" type="submit" disabled={form.processing}><Save size={17} />{form.processing ? 'Saving...' : 'Save Academic Term'}</button>
        </form>
    </AppLayout>;
}
