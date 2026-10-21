import { useForm } from '@inertiajs/react';
import { DatabaseBackup, Download, FileJson, RotateCcw } from 'lucide-react';
import AppLayout from '../../Layouts/AppLayout';
import SettingsTabs from '../../components/SettingsTabs';
import useConfirmDialog from '../../hooks/useConfirmDialog';

export default function Backup() {
    const form = useForm({ backup_file: null });
    const { ask, dialog } = useConfirmDialog();
    const restore = async (event) => {
        event.preventDefault();
        if (!form.data.backup_file || !await ask({ title: 'Restore system backup?', message: 'Current system records will be replaced by the selected backup. This action cannot be undone.', confirmLabel: 'Restore Backup', tone: 'danger' })) return;
        form.post('/settings/backup/restore', { forceFormData: true });
    };

    return <AppLayout title="Settings" subtitle="Manage system accounts, backups, and data recovery.">
        <SettingsTabs />
        <section className="settings-grid">
            <article className="settings-tool">
                <span className="settings-tool-icon"><DatabaseBackup size={22} /></span>
                <div><span className="bento-eyebrow">Backup</span><h2>Download system data</h2><p>Save faculty, attendance, schedules, accounts, and payroll records in one backup file.</p></div>
                <a className="button ui-control ui-action ui-secondary" href="/settings/backup/download"><Download size={17} />Download backup</a>
            </article>
            <article className="settings-tool recovery-tool">
                <span className="settings-tool-icon"><RotateCcw size={22} /></span>
                <div><span className="bento-eyebrow">Recovery</span><h2>Restore a backup</h2><p>Upload a backup created by this system. Existing records will be replaced.</p></div>
                <form onSubmit={restore}>
                    <label className="backup-file-field">
                        Backup file
                        <span className="backup-file-picker">
                            <input type="file" accept="application/json,.json" onChange={(event) => form.setData('backup_file', event.target.files[0] || null)} required />
                            <span className="backup-file-button"><FileJson size={17} />Choose JSON file</span>
                            <span className="backup-file-name">{form.data.backup_file?.name || 'No file selected'}</span>
                        </span>
                    </label>
                    {form.errors.backup_file && <div className="form-note">{form.errors.backup_file}</div>}
                    <button className="ui-control ui-action ui-danger" type="submit" disabled={form.processing || !form.data.backup_file}><RotateCcw size={17} />{form.processing ? 'Restoring...' : 'Restore backup'}</button>
                </form>
            </article>
        </section>
        {dialog}
    </AppLayout>;
}
