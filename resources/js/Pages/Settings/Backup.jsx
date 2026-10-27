import { useForm, usePage } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { DatabaseBackup, Download, FileJson, RotateCcw } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';
import SettingsTabs from '../../components/SettingsTabs';
import FacultyCombobox from '../../components/FacultyCombobox';
import { fullName } from '../../lib/format';
import useConfirmDialog from '../../hooks/useConfirmDialog';

export default function Backup({ backupGroups }) {
    const { facultyChoices } = usePage().props;
    const [mode, setMode] = useState('full');
    const [groups, setGroups] = useState(['faculty', 'schedules', 'attendance']);
    const [scope, setScope] = useState('all');
    const [selected, setSelected] = useState([]);
    const [recoveryKind, setRecoveryKind] = useState(null);
    const fileRequest = useRef(0);
    const form = useForm({ backup_file: null });
    const { ask, dialog } = useConfirmDialog();
    const canDownload = mode === 'full' || (groups.length > 0 && (scope === 'all' || selected.length > 0));
    const download = () => {
        if (!canDownload) return;
        const params = new URLSearchParams({ mode });
        if (mode === 'custom') {
            params.set('faculty_scope', scope);
            groups.forEach((group) => params.append('groups[]', group));
            if (scope === 'selected') selected.forEach((id) => params.append('employee_ids[]', id));
        }
        window.location.assign(`/settings/backup/download?${params}`);
    };
    const selectFile = async (file) => {
        const request = ++fileRequest.current;
        form.setData('backup_file', null);
        setRecoveryKind(null);
        form.clearErrors();
        if (!file) return;
        try {
            const data = JSON.parse(await file.text());
            if (request !== fileRequest.current) return;
            if (!['CvSU Payroll System', 'DCS Attendance & DTR'].includes(data.application) || ![1, 2].includes(data.version)) throw new Error();
            setRecoveryKind(data.version === 2 ? 'custom' : 'full');
            form.setData('backup_file', file);
        } catch {
            if (request !== fileRequest.current) return;
            form.setError('backup_file', 'Choose a valid JSON backup created by this system.');
        }
    };
    const restore = async (event) => {
        event.preventDefault();
        const custom = recoveryKind === 'custom';
        if (!form.data.backup_file || !await ask({ title: custom ? 'Recover custom backup?' : 'Restore system backup?', message: custom ? 'Add missing records from this custom backup. Existing records will be kept, and other faculty data will remain unchanged.' : 'Current system records will be replaced by the selected full backup. This action cannot be undone.', confirmLabel: custom ? 'Recover Missing Records' : 'Restore Backup', tone: custom ? 'warning' : 'danger' })) return;
        form.post('/settings/backup/restore', { forceFormData: true });
    };

    return <AppLayout title="Settings" subtitle="Manage system accounts, backups, and data recovery.">
        <SettingsTabs />
        <section className="settings-grid backup-settings-grid">
            <article className="settings-tool">
                <span className="settings-tool-icon"><DatabaseBackup size={22} /></span>
                <div><span className="bento-eyebrow">Backup</span><h2>Download system data</h2><p>Save the whole system, or choose the data and faculty to include.</p></div>
                <label>Backup type<select value={mode} onChange={(event) => setMode(event.target.value)}><option value="full">Full system backup</option><option value="custom">Custom backup</option></select></label>
                {mode === 'custom' && <>
                    <fieldset className="backup-options"><legend>Data to include</legend><div className="backup-category-grid">{backupGroups.map((group) => {
                        const disabled = group.value === 'audit' && scope === 'selected';
                        return <label className={`backup-category${disabled ? ' is-disabled' : ''}`} key={group.value}><input type="checkbox" disabled={disabled} checked={groups.includes(group.value)} onChange={(event) => setGroups((current) => event.target.checked ? [...current, group.value] : current.filter((value) => value !== group.value))} /><span><strong>{group.label}</strong></span></label>;
                    })}</div></fieldset>
                    <label>Faculty to include<select value={scope} onChange={(event) => { setScope(event.target.value); if (event.target.value === 'selected') setGroups((current) => current.filter((value) => value !== 'audit')); }}><option value="all">All faculty (including archived)</option><option value="selected">Selected faculty only</option></select></label>
                    {scope === 'selected' && <div className="backup-faculty-picker"><FacultyCombobox label="Choose faculty" employees={facultyChoices} multiple selectedIds={selected} onSelect={setSelected} /><div className="backup-selected-faculty">{selected.map((id) => <button type="button" className="ui-control ui-action ui-secondary" key={id} onClick={() => setSelected((current) => current.filter((value) => value !== id))} aria-label={`Remove ${fullName(facultyChoices.find((item) => item.id === id))}`}>{fullName(facultyChoices.find((item) => item.id === id))} ×</button>)}</div><small>{selected.length} faculty selected</small></div>}

                    {!groups.length && <p className="field-error" role="alert">Select at least one type of data.</p>}
                    {scope === 'selected' && !selected.length && <p className="field-error">Choose at least one faculty member.</p>}
                </>}
                <button className="ui-control ui-action ui-primary" type="button" disabled={!canDownload} onClick={download}><Download size={17} />Download {mode === 'custom' ? 'custom ' : ''}backup</button>
            </article>
            <article className="settings-tool recovery-tool">
                <span className="settings-tool-icon"><RotateCcw size={22} /></span>
                <div><span className="bento-eyebrow">Recovery</span><h2>Restore a backup</h2><p>A full backup replaces system data. A custom backup adds missing records and keeps existing records.</p></div>
                <form onSubmit={restore}>
                    <label className="backup-file-field">
                        Backup file
                        <span className="backup-file-picker">
                            <input type="file" accept="application/json,.json" onChange={(event) => selectFile(event.target.files[0] || null)} required />
                            <span className="backup-file-button"><FileJson size={17} />Choose JSON file</span>
                            <span className="backup-file-name">{form.data.backup_file?.name || 'No file selected'}</span>
                        </span>
                    </label>
                    {form.errors.backup_file && <div className="form-note">{form.errors.backup_file}</div>}
                    {recoveryKind && <p className="backup-note">{recoveryKind === 'custom' ? 'Custom backup: recover missing records only. Existing records will be kept.' : 'Full system backup: replaces existing system records.'}</p>}
                    <button className={`ui-control ui-action ${recoveryKind === 'custom' ? 'ui-primary' : 'ui-danger'}`} type="submit" disabled={form.processing || !form.data.backup_file}><RotateCcw size={17} />{form.processing ? 'Restoring...' : recoveryKind === 'custom' ? 'Recover missing records' : 'Restore backup'}</button>
                </form>
            </article>
        </section>
        {dialog}
    </AppLayout>;
}
